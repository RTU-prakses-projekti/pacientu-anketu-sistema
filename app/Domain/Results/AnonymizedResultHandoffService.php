<?php

namespace App\Domain\Results;

use App\Domain\Audit\AuditService;
use App\Models\AnonymizedResultHandoff;
use App\Models\FormSubmission;
use App\Models\Organisation;
use App\Models\PatientFormAssignment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AnonymizedResultHandoffService
{
    public function __construct(private AuditService $audit) {}

    public function recipients(Organisation $organisation): Collection
    {
        if (!$organisation->is_active) return collect();

        return User::query()->where('is_active', true)
            ->whereHas('memberships', fn ($membership) => $membership->where('organisation_id', $organisation->id)->where('is_active', true))
            ->whereHas('memberships.organisation', fn ($query) => $query->where('organisations.id', $organisation->id)->where('organisations.is_active', true))
            ->where(function ($query) use ($organisation): void {
                $query->whereHas('globalRoles.permissions', fn ($permissions) => $permissions->where('permissions.name', 'anonymized_results.view'))
                    ->orWhereHas('memberships', fn ($membership) => $membership->where('organisation_id', $organisation->id)->where('is_active', true)->whereHas('roles.permissions', fn ($permissions) => $permissions->where('permissions.name', 'anonymized_results.view')))
                    ->orWhereHas('globalRoles', fn ($role) => $role->where('name', 'platform_admin'));
            })->with(['memberships.roles'])->orderBy('name')->get();
    }

    public function handoff(User $actor, PatientFormAssignment $assignment, FormSubmission $submission, int $recipientId): AnonymizedResultHandoff
    {
        $patientCase = $assignment->patientCase;
        if (!$patientCase || $patientCase->organisation_id !== $submission->organisation_id || !Organisation::whereKey($patientCase->organisation_id)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['recipient' => __('messages.recipient_not_allowed')]);
        }
        abort_unless($patientCase->doctor_id === $actor->id || $actor->isBootstrapRoot(), 403);
        if (!in_array($submission->status, FormSubmission::PATIENT_COMPLETED_STATUSES, true) || $assignment->completedSubmission()->whereKey($submission->id)->doesntExist()) {
            throw ValidationException::withMessages(['recipient' => __('messages.completed_result_required')]);
        }
        $recipient = $this->recipients($patientCase->organisation)->firstWhere('id', $recipientId);
        if (!$recipient) throw ValidationException::withMessages(['recipient' => __('messages.recipient_not_allowed')]);
        if (AnonymizedResultHandoff::where('form_submission_id', $submission->id)->where('recipient_user_id', $recipient->id)->exists()) {
            throw ValidationException::withMessages(['recipient' => __('messages.result_already_handed_off')]);
        }

        return DB::transaction(function () use ($patientCase, $assignment, $submission, $recipient, $actor): AnonymizedResultHandoff {
            $handoff = AnonymizedResultHandoff::create([
                'organisation_id' => $patientCase->organisation_id,
                'patient_form_assignment_id' => $assignment->id,
                'form_submission_id' => $submission->id,
                'recipient_user_id' => $recipient->id,
                'handed_off_by' => $actor->id,
                'handed_off_at' => now(),
            ]);
            $this->audit->record('anonymized_result.handed_off', $handoff, $patientCase->organisation_id, ['recipient_user_id' => $recipient->id, 'form_submission_id' => $submission->id]);
            return $handoff;
        });
    }

    public function handoffMany(User $actor, Collection $assignments, int $recipientId): array
    {
        $organisationIds = $assignments->map(fn (PatientFormAssignment $assignment) => $assignment->patientCase?->organisation_id)->filter()->unique();
        if ($assignments->isEmpty() || $organisationIds->count() !== 1) {
            throw ValidationException::withMessages(['assignment_ids' => __('messages.invalid_patient_selection')]);
        }

        $organisation = $assignments->first()->patientCase?->organisation;
        if (!$organisation || !$organisation->is_active || !Organisation::whereKey($organisation->id)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['recipient' => __('messages.recipient_not_allowed')]);
        }

        $recipient = $this->recipients($organisation)->firstWhere('id', $recipientId);
        if (!$recipient) {
            throw ValidationException::withMessages(['recipient' => __('messages.recipient_not_allowed')]);
        }

        foreach ($assignments as $assignment) {
            $patientCase = $assignment->patientCase;
            $submission = $assignment->completedSubmission;
            if (!$patientCase || $patientCase->organisation_id !== $organisation->id) {
                throw ValidationException::withMessages(['assignment_ids' => __('messages.invalid_patient_selection')]);
            }
            abort_unless($patientCase->doctor_id === $actor->id || $actor->isBootstrapRoot(), 403);
            if (!$submission || $submission->organisation_id !== $organisation->id
                || !in_array($submission->status, FormSubmission::PATIENT_COMPLETED_STATUSES, true)
                || $assignment->completedSubmission()->whereKey($submission->id)->doesntExist()) {
                throw ValidationException::withMessages(['assignment_ids' => __('messages.completed_result_required')]);
            }
        }

        $counts = ['created' => 0, 'skipped' => 0];
        DB::transaction(function () use ($actor, $assignments, $recipient, &$counts): void {
            foreach ($assignments as $assignment) {
                $submission = $assignment->completedSubmission;
                if (AnonymizedResultHandoff::where('form_submission_id', $submission->id)->where('recipient_user_id', $recipient->id)->exists()) {
                    $counts['skipped']++;
                    continue;
                }

                $this->handoff($actor, $assignment, $submission, (int) $recipient->id);
                $counts['created']++;
            }
        });

        return $counts;
    }
}
