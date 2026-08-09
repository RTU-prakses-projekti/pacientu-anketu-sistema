<?php

namespace App\Domain\Submissions;

use App\Domain\Audit\AuditService;
use App\Domain\Forms\ComponentRegistry;
use App\Domain\Forms\LocalizedContent;
use App\Models\AttemptGrant;
use App\Models\ConsentRecord;
use App\Models\FormSubmission;
use App\Models\Invitation;
use App\Models\Publication;
use App\Models\SubmissionAnswer;
use App\Models\SubmissionMutation;
use App\Models\User;
use App\Notifications\SubmissionFinalizedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SubmissionService
{
    public function __construct(private ComponentRegistry $registry, private ScoringService $scoring, private ConditionalLogicService $conditions, private AuditService $audit, private LocalizedContent $localized) {}

    public function start(Publication $publication, ?User $user, ?string $accessCode, ?string $invitationToken, string $anonymousKey): FormSubmission
    {
        $invitation = $this->authorizeAccess($publication, $user, $accessCode, $invitationToken);
        $anonymousHash = $user ? null : hash('sha256', $anonymousKey);

        return $this->startAuthorized($publication, $user, $invitation, $anonymousHash);
    }

    public function startForInvitation(Publication $publication, Invitation $invitation): FormSubmission
    {
        if (!$publication->isOpen() || $publication->access_mode !== 'invitation' || $invitation->publication_id !== $publication->id
            || $invitation->revoked_at || ($invitation->expires_at && $invitation->expires_at->isPast())) {
            throw ValidationException::withMessages(['invitation' => __('messages.invalid_invitation')]);
        }

        return $this->startAuthorized($publication, null, $invitation, null, true);
    }

    private function startAuthorized(Publication $publication, ?User $user, ?Invitation $invitation, ?string $anonymousHash, bool $forceResume = false): FormSubmission
    {

        return DB::transaction(function () use ($publication, $user, $invitation, $anonymousHash, $forceResume) {
            $query = FormSubmission::where('publication_id', $publication->id)->where('status', 'in_progress');
            $this->identityQuery($query, $user, $invitation, $anonymousHash);
            if (($publication->resume_enabled || $forceResume) && ($existing = $query->first())) return $existing;

            if ($invitation) {
                $lockedInvitation = Invitation::lockForUpdate()->findOrFail($invitation->id);
                if ($lockedInvitation->uses >= $lockedInvitation->max_uses) throw ValidationException::withMessages(['invitation' => __('messages.invalid_invitation')]);
                $invitation = $lockedInvitation;
            }

            $attemptQuery = FormSubmission::where('publication_id', $publication->id)->whereNotIn('status', ['cancelled']);
            $this->identityQuery($attemptQuery, $user, $invitation, $anonymousHash);
            $count = $attemptQuery->count();
            $numberQuery = FormSubmission::where('publication_id', $publication->id);
            $this->identityQuery($numberQuery, $user, $invitation, $anonymousHash);
            $nextAttemptNumber = ((int) $numberQuery->max('attempt_number')) + 1;
            $grants = AttemptGrant::where('publication_id', $publication->id)
                ->when($user, fn ($q) => $q->where('user_id', $user->id), fn ($q) => $q->whereNull('user_id'))
                ->when($invitation, fn ($q) => $q->where('invitation_id', $invitation->id))
                ->when(!$user && !$invitation, fn ($q) => $q->where('anonymous_key_hash', $anonymousHash))
                ->sum('additional_attempts');
            if ($count >= $publication->attempt_limit + $grants) throw ValidationException::withMessages(['attempt' => __('messages.attempt_limit_reached')]);

            $started = now();
            $deadline = $publication->timer_enabled && $publication->duration_minutes ? $started->copy()->addMinutes($publication->duration_minutes) : null;
            if ($publication->closes_at && (!$deadline || $publication->closes_at->lt($deadline))) $deadline = $publication->closes_at;
            $submission = FormSubmission::create([
                'public_id' => (string) Str::uuid(), 'organisation_id' => $publication->organisation_id, 'publication_id' => $publication->id,
                'form_version_id' => $publication->form_version_id, 'user_id' => $user?->id, 'invitation_id' => $invitation?->id,
                'anonymous_key_hash' => $anonymousHash, 'attempt_number' => $nextAttemptNumber, 'status' => 'in_progress', 'started_at' => $started, 'deadline_at' => $deadline,
            ]);
            if ($invitation) $invitation->increment('uses');
            $this->audit->record('submission.started', $submission, $submission->organisation_id, ['attempt' => $submission->attempt_number]);
            return $submission;
        });
    }

    public function autosave(FormSubmission $submission, int $expectedRevision, string $mutationId, array $answers): array
    {
        if ($submission->deadline_at && now()->gte($submission->deadline_at)) {
            $this->finalize($submission, true);
            throw ValidationException::withMessages(['deadline' => __('messages.deadline_passed')]);
        }
        return DB::transaction(function () use ($submission, $expectedRevision, $mutationId, $answers) {
            $locked = FormSubmission::lockForUpdate()->findOrFail($submission->id);
            return $this->saveLocked($locked, $expectedRevision, $mutationId, $answers);
        });
    }

    public function finalizeWithSnapshot(FormSubmission $submission, int $expectedRevision, string $mutationId, array $answers): FormSubmission
    {
        if ($submission->deadline_at && now()->gte($submission->deadline_at)) return $this->finalize($submission, true);

        $didFinalize = false;
        $result = DB::transaction(function () use ($submission, $expectedRevision, $mutationId, $answers, &$didFinalize) {
            $locked = FormSubmission::lockForUpdate()->findOrFail($submission->id);
            if ($locked->status !== 'in_progress') return $locked;
            $this->saveLocked($locked, $expectedRevision, $mutationId, $answers);
            $locked->refresh();

            return $this->completeLocked($locked, false, $didFinalize);
        });
        $this->notifyCreator($result, $didFinalize);

        return $result;
    }

    public function finalize(FormSubmission $submission, bool $expired = false): FormSubmission
    {
        if (!$expired && $submission->deadline_at && now()->gte($submission->deadline_at)) {
            return $this->finalize($submission, true);
        }
        $didFinalize = false;
        $result = DB::transaction(function () use ($submission, $expired, &$didFinalize) {
            $locked = FormSubmission::lockForUpdate()->findOrFail($submission->id);
            return $this->completeLocked($locked, $expired, $didFinalize);
        });
        $this->notifyCreator($result, $didFinalize);

        return $result;
    }

    public function expireOverdue(): int
    {
        $count = 0;
        FormSubmission::where('status', 'in_progress')->whereNotNull('deadline_at')->where('deadline_at', '<=', now())->chunkById(100, function ($items) use (&$count) {
            foreach ($items as $item) { $this->finalize($item, true); $count++; }
        });
        return $count;
    }

    private function assertWritable(FormSubmission $submission): void
    {
        $submission->loadMissing('publication');
        if ($submission->status !== 'in_progress') throw ValidationException::withMessages(['submission' => __('messages.submission_closed')]);
        if (!$submission->publication->isOpen()) throw ValidationException::withMessages(['publication' => __('messages.publication_closed')]);
        if ($submission->deadline_at && now()->gte($submission->deadline_at)) throw ValidationException::withMessages(['deadline' => __('messages.deadline_passed')]);
    }

    private function saveLocked(FormSubmission $locked, int $expectedRevision, string $mutationId, array $answers): array
    {
        if ($existing = SubmissionMutation::where('form_submission_id', $locked->id)->where('client_mutation_id', $mutationId)->first()) {
            return ['revision' => $existing->acknowledged_revision, 'server_time' => now()->toIso8601String(), 'idempotent' => true];
        }
        $this->assertWritable($locked);
        if ($locked->revision !== $expectedRevision) throw ValidationException::withMessages(['revision' => __('messages.revision_conflict')]);

        $components = $locked->formVersion->components()->with('options')->whereIn('id', array_keys($answers))->get()->keyBy('id');
        if ($components->count() !== count($answers)) throw ValidationException::withMessages(['answers' => __('messages.component_not_in_version')]);

        $refusedConsent = false;
        foreach ($answers as $componentId => $value) {
            $component = $components->get((int) $componentId);
            $normalized = $this->registry->validateAnswer($component, $value, false);
            if ($component->type === 'consent_checkbox' && $normalized === false && $locked->publication->consent_required) $refusedConsent = true;
            SubmissionAnswer::updateOrCreate(
                ['form_submission_id' => $locked->id, 'form_component_id' => $component->id],
                ['value' => $normalized, 'display_value' => $this->registry->formatForExport($component, $normalized), 'answer_revision' => $expectedRevision + 1, 'saved_at' => now()]
            );
            if ($component->type === 'consent_checkbox') $this->recordConsent($locked, $component, (bool) $normalized);
        }
        if ($refusedConsent) SubmissionAnswer::where('form_submission_id', $locked->id)->whereHas('component', fn ($q) => $q->where('type', '!=', 'consent_checkbox'))->delete();
        $locked->increment('revision');
        SubmissionMutation::create(['form_submission_id' => $locked->id, 'client_mutation_id' => $mutationId, 'acknowledged_revision' => $locked->revision]);

        return ['revision' => $locked->revision, 'server_time' => now()->toIso8601String(), 'consent_refused' => $refusedConsent];
    }

    private function completeLocked(FormSubmission $locked, bool $expired, bool &$didFinalize): FormSubmission
    {
        if ($locked->status !== 'in_progress') return $locked;
        if (!$expired) $this->assertWritable($locked);

        $locked->load('answers.component.options', 'formVersion.sections.components.options', 'publication');
        $answerMap = $locked->answers->pluck('value', 'form_component_id')->all();
        $visibility = $this->conditions->visibility($locked->formVersion, $answerMap);
        if (!$expired) {
            $errors = [];
            foreach ($locked->formVersion->components as $component) {
                if (!$this->conditions->componentIsVisible($locked->formVersion, $component->id, $visibility) || !$this->registry->validatesAnswers($component->type)) continue;
                if ($component->is_required && (!$this->hasAnswer($answerMap, $component->id))) $errors['answers.'.$component->id] = __('messages.required_answer', ['label' => $component->localizedLabel()]);
            }
            if ($errors) throw ValidationException::withMessages($errors);
            if ($locked->publication->consent_required && !$locked->consentRecords()->where('decision', 'accepted')->exists()) throw ValidationException::withMessages(['consent' => __('messages.consent_required')]);
        }

        // Hidden answers remain stored for audit/resume stability, but are
        // deliberately ignored for required validation and scoring.
        $score = $this->scoring->score($locked, $visibility);
        $status = $expired ? 'expired' : ($score['manual_required'] ? 'awaiting_grading' : ($score['maximum'] > 0 ? 'graded' : 'submitted'));
        $percentage = $score['maximum'] > 0 ? round($score['automatic'] / $score['maximum'] * 100, 2) : null;
        $locked->update(['status' => $status, 'submitted_at' => now(), 'maximum_points' => $score['maximum'], 'automatic_points' => $score['automatic'], 'final_points' => $score['automatic'], 'percentage' => $percentage, 'grading_status' => $score['manual_required'] ? 'awaiting_grading' : 'complete']);
        $didFinalize = true;
        $this->audit->record($expired ? 'submission.expired' : 'submission.finalized', $locked, $locked->organisation_id, ['status' => $status, 'revision' => $locked->revision]);

        return $locked->fresh('answers.score', 'publication.form');
    }

    private function hasAnswer(array $answers, int $componentId): bool
    {
        if (!array_key_exists($componentId, $answers)) return false;
        $value = $answers[$componentId];
        return !($value === null || $value === '' || $value === []);
    }

    private function notifyCreator(FormSubmission $result, bool $didFinalize): void
    {
        if (!$didFinalize) return;
        $result->loadMissing('publication.form.creator');
        $result->publication->form->creator?->notify(new SubmissionFinalizedNotification($result));
    }

    private function authorizeAccess(Publication $publication, ?User $user, ?string $accessCode, ?string $invitationToken): ?Invitation
    {
        if (!$publication->isOpen()) throw ValidationException::withMessages(['publication' => __('messages.publication_closed')]);
        if ($publication->access_mode === 'authenticated' && !$user) throw ValidationException::withMessages(['auth' => __('messages.login_required')]);
        if ($publication->access_mode === 'authenticated' && $user && !$user->isPlatformAdmin() && !$user->memberships()->where('organisation_id', $publication->organisation_id)->where('is_active', true)->exists()) throw ValidationException::withMessages(['auth' => __('messages.login_required')]);
        if ($publication->identified_required && !$user && $publication->access_mode !== 'invitation') throw ValidationException::withMessages(['identity' => __('messages.identity_required')]);
        if (!$user && !$publication->anonymous_allowed && $publication->access_mode !== 'invitation') throw ValidationException::withMessages(['identity' => __('messages.identity_required')]);
        if ($publication->access_mode === 'access_code' && (!$accessCode || !$publication->access_code_hash || !Hash::check($accessCode, $publication->access_code_hash))) throw ValidationException::withMessages(['access_code' => __('messages.invalid_access_code')]);
        if ($publication->access_mode !== 'invitation') return null;
        if (!$invitationToken) throw ValidationException::withMessages(['invitation' => __('messages.invalid_invitation')]);
        $invitation = $publication->invitations()->where('token_hash', hash('sha256', $invitationToken))->first();
        if (!$invitation || $invitation->revoked_at || ($invitation->expires_at && $invitation->expires_at->isPast())) throw ValidationException::withMessages(['invitation' => __('messages.invalid_invitation')]);
        return $invitation;
    }

    private function identityQuery($query, ?User $user, ?Invitation $invitation, ?string $anonymousHash): void
    {
        if ($user) $query->where('user_id', $user->id);
        elseif ($invitation) $query->where('invitation_id', $invitation->id);
        else $query->where('anonymous_key_hash', $anonymousHash);
    }

    private function recordConsent(FormSubmission $submission, $component, bool $accepted): void
    {
        $decision = $accepted ? 'accepted' : 'refused';
        $existing = ConsentRecord::where('form_submission_id', $submission->id)->where('form_component_id', $component->id)->first();
        if ($existing?->decision === $decision) return;

        $requestedLocale = $this->localized->locale();
        $contentLocale = $component->localizedConsentTextSourceLocale($requestedLocale);
        $evidence = [
            'form_version_id' => $submission->form_version_id,
            'decision' => $decision,
            'content_locale' => $contentLocale,
            'consent_text_hash' => hash('sha256', (string) $component->localizedConsentText($requestedLocale)),
            'recorded_at' => now(),
        ];

        if ($existing) $existing->update($evidence);
        else ConsentRecord::create(['form_submission_id' => $submission->id, 'form_component_id' => $component->id, ...$evidence]);

        $this->audit->record('consent.recorded', $submission, $submission->organisation_id, ['decision' => $decision, 'component_id' => $component->id, 'requested_locale' => $requestedLocale, 'content_locale' => $contentLocale]);
    }
}
