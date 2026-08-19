<?php

namespace App\Domain\Administration;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Organisation;
use App\Models\QuestionnairePackageImport;
use App\Models\QuestionnairePackagePartImport;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CleanupService
{
    public function formEligibility(Form $form): array
    {
        $versionIds = $form->versions()->pluck('id');
        if ($form->versions()->where(fn ($query) => $query->whereIn('status', ['published', 'archived'])->orWhereNotNull('published_at'))->exists()) {
            return $this->denied(__('messages.form_delete_published_denied'));
        }
        if ($form->publications()->exists() || DB::table('form_submissions')->whereIn('form_version_id', $versionIds)->exists()) {
            return $this->denied(__('messages.form_delete_used_denied'));
        }
        if (DB::table('exports')->where('form_id', $form->id)->exists()) {
            return $this->denied(__('messages.form_delete_audit_denied'));
        }
        if ($this->hasRetainedFormAuditEvidence($form, $versionIds)) {
            return $this->denied(__('messages.form_delete_audit_denied'));
        }

        return $this->allowed();
    }

    public function deleteForm(Form $form): void
    {
        $storageFiles = DB::transaction(function () use ($form): array {
            $form = Form::withTrashed()->lockForUpdate()->findOrFail($form->id);
            $form->versions()->lockForUpdate()->get();
            return $this->deleteFormGraph($form);
        });
        foreach ($storageFiles as [$disk, $path]) Storage::disk($disk)->delete($path);
    }

    public function organisationEligibility(Organisation $organisation): array
    {
        if (Form::withTrashed()->where('organisation_id', $organisation->id)
                ->whereHas('versions', fn ($query) => $query->whereIn('status', ['published', 'archived'])->orWhereNotNull('published_at'))->exists()
            || DB::table('publications')->where('organisation_id', $organisation->id)->exists()
            || DB::table('form_submissions')->where('organisation_id', $organisation->id)->exists()) {
            return $this->denied(__('messages.organisation_delete_used_denied'));
        }
        if ($organisation->patientCases()->exists()) return $this->denied(__('messages.organisation_delete_patient_data_denied'));
        if (DB::table('exports')->where('organisation_id', $organisation->id)->exists()) return $this->denied(__('messages.organisation_delete_audit_denied'));
        $organisationFormIds = Form::withTrashed()->where('organisation_id', $organisation->id)->pluck('id');
        $auditLogs = AuditLog::where('organisation_id', $organisation->id)
            ->orWhere(fn ($query) => $query->where('subject_type', $organisation->getMorphClass())->where('subject_id', $organisation->id))
            ->get(['action', 'subject_type', 'subject_id']);
        if ($auditLogs->contains(fn (AuditLog $log) => !$this->isOrganisationCreationAudit($log, $organisation, $organisationFormIds))) {
            return $this->denied(__('messages.organisation_delete_audit_denied'));
        }
        foreach (Form::withTrashed()->where('organisation_id', $organisation->id)->get() as $form) {
            if ($this->hasRetainedFormAuditEvidence($form, $form->versions()->pluck('id'))) {
                return $this->denied(__('messages.organisation_delete_audit_denied'));
            }
        }

        $formIds = Form::withTrashed()->where('organisation_id', $organisation->id)->pluck('id');
        $versionIds = DB::table('form_versions')->whereIn('form_id', $formIds)->pluck('id');
        $versionAttachmentCount = Attachment::where('attachable_type', (new FormVersion())->getMorphClass())->whereIn('attachable_id', $versionIds)->count();
        if (Attachment::where('organisation_id', $organisation->id)->count() !== $versionAttachmentCount) {
            return $this->denied(__('messages.organisation_delete_audit_denied'));
        }

        return $this->allowed($auditLogs->isEmpty() ? 'hard' : 'soft');
    }

    public function deleteOrganisation(Organisation $organisation): void
    {
        $storageFiles = DB::transaction(function () use ($organisation): array {
            $organisation = Organisation::withTrashed()->lockForUpdate()->findOrFail($organisation->id);
            $eligibility = $this->organisationEligibility($organisation);
            $this->ensureAllowed($eligibility, 'organisation');
            if ($eligibility['mode'] === 'soft') {
                $organisation->update(['is_active' => false]);
                $organisation->delete();
                return [];
            }
            $storageFiles = [];
            foreach ($organisation->forms()->withTrashed()->lockForUpdate()->get() as $form) {
                $form->versions()->lockForUpdate()->get();
                $storageFiles = array_merge($storageFiles, $this->deleteFormGraph($form));
            }
            $membershipIds = DB::table('organisation_memberships')->where('organisation_id', $organisation->id)->pluck('id');
            DB::table('membership_roles')->whereIn('organisation_membership_id', $membershipIds)->delete();
            DB::table('organisation_memberships')->whereIn('id', $membershipIds)->delete();
            $organisation->forceDelete();
            return $storageFiles;
        });
        foreach ($storageFiles as [$disk, $path]) Storage::disk($disk)->delete($path);
    }

    public function userEligibility(User $user): array
    {
        if ($this->isLastActivePlatformAdministrator($user)) return $this->denied(__('messages.last_platform_admin_required'));
        if (DB::table('forms')->where('created_by', $user->id)->exists() || DB::table('form_versions')->where('created_by', $user->id)->exists()) {
            return $this->denied(__('messages.user_delete_authored_data_denied'));
        }
        foreach ([
            ['form_submissions', 'user_id'], ['patient_cases', 'doctor_id'], ['patient_access_packages', 'created_by'],
            ['attachments', 'uploaded_by'], ['exports', 'requested_by'], ['attempt_grants', 'granted_by'],
            ['answer_scores', 'graded_by'], ['questionnaire_package_imports', 'imported_by'],
            ['questionnaire_package_part_imports', 'imported_by'], ['audit_logs', 'actor_id'],
        ] as [$table, $column]) {
            if (DB::table($table)->where($column, $user->id)->exists()) return $this->denied(__('messages.user_delete_used_denied'));
        }
        if (AuditLog::where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->id)
            ->where('action', '!=', 'user.created')
            ->exists()) {
            return $this->denied(__('messages.user_delete_used_denied'));
        }

        return $this->allowed();
    }

    public function userEligibilityMap(iterable $users): Collection
    {
        $users = collect($users);
        $userIds = $users->pluck('id');
        if ($userIds->isEmpty()) return collect();

        $activeAdministratorIds = User::where('is_active', true)
            ->whereHas('globalRoles', fn ($query) => $query->where('name', 'platform_admin'))
            ->pluck('id');
        $lastAdministratorId = $activeAdministratorIds->count() === 1 ? $activeAdministratorIds->first() : null;
        $authoredUserIds = DB::table('forms')->whereIn('created_by', $userIds)->pluck('created_by')
            ->merge(DB::table('form_versions')->whereIn('created_by', $userIds)->pluck('created_by'))
            ->unique();
        $usedUserIds = collect();
        foreach ([
            ['form_submissions', 'user_id'], ['patient_cases', 'doctor_id'], ['patient_access_packages', 'created_by'],
            ['attachments', 'uploaded_by'], ['exports', 'requested_by'], ['attempt_grants', 'granted_by'],
            ['answer_scores', 'graded_by'], ['questionnaire_package_imports', 'imported_by'],
            ['questionnaire_package_part_imports', 'imported_by'], ['audit_logs', 'actor_id'],
        ] as [$table, $column]) {
            $usedUserIds = $usedUserIds->merge(DB::table($table)->whereIn($column, $userIds)->pluck($column));
        }
        $usedUserIds = $usedUserIds->merge(
            AuditLog::where('subject_type', (new User())->getMorphClass())
                ->whereIn('subject_id', $userIds)
                ->where('action', '!=', 'user.created')
                ->pluck('subject_id')
        );
        $authoredLookup = $authoredUserIds->flip();
        $usedLookup = $usedUserIds->unique()->flip();

        return $users->mapWithKeys(function (User $user) use ($lastAdministratorId, $authoredLookup, $usedLookup): array {
            $eligibility = match (true) {
                $user->id === $lastAdministratorId => $this->denied(__('messages.last_platform_admin_required')),
                $authoredLookup->has($user->id) => $this->denied(__('messages.user_delete_authored_data_denied')),
                $usedLookup->has($user->id) => $this->denied(__('messages.user_delete_used_denied')),
                default => $this->allowed(),
            };
            return [$user->id => $eligibility];
        });
    }

    public function deleteUser(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user = User::lockForUpdate()->findOrFail($user->id);
            $this->lockActivePlatformAdministrators();
            $this->ensureAllowed($this->userEligibility($user), 'user');
            $membershipIds = DB::table('organisation_memberships')->where('user_id', $user->id)->pluck('id');
            DB::table('membership_roles')->whereIn('organisation_membership_id', $membershipIds)->delete();
            DB::table('organisation_memberships')->whereIn('id', $membershipIds)->delete();
            DB::table('user_roles')->where('user_id', $user->id)->delete();
            $user->delete();
        });
    }

    public function ensureCanDeactivate(User $user): void
    {
        $this->lockActivePlatformAdministrators();
        if ($user->is_active && $this->isLastActivePlatformAdministrator($user)) {
            throw ValidationException::withMessages(['user' => __('messages.last_platform_admin_required')]);
        }
    }

    private function isLastActivePlatformAdministrator(User $user): bool
    {
        $role = Role::where('name', 'platform_admin')->where('scope', 'global')->first();
        if (!$role || !$user->globalRoles()->whereKey($role->id)->exists() || !$user->is_active) return false;
        return User::where('is_active', true)->whereHas('globalRoles', fn ($query) => $query->whereKey($role->id))->count() === 1;
    }

    private function lockActivePlatformAdministrators(): void
    {
        User::where('is_active', true)
            ->whereHas('globalRoles', fn ($query) => $query->where('name', 'platform_admin'))
            ->lockForUpdate()
            ->get();
    }

    private function deleteFormGraph(Form $form): array
    {
        $this->ensureAllowed($this->formEligibility($form), 'form');
        $versionIds = $form->versions()->pluck('id');
        $sectionIds = DB::table('form_sections')->whereIn('form_version_id', $versionIds)->pluck('id');
        $componentIds = DB::table('form_components')->whereIn('form_version_id', $versionIds)->pluck('id');
        $ruleIds = DB::table('conditional_rules')->whereIn('form_version_id', $versionIds)->pluck('id');
        $attachmentQuery = Attachment::where('attachable_type', (new FormVersion())->getMorphClass())->whereIn('attachable_id', $versionIds);
        $attachments = $attachmentQuery->get();
        $storageFiles = $attachments->map(fn ($attachment) => [$attachment->disk, $attachment->storage_path])->all();

        DB::table('conditional_actions')->whereIn('conditional_rule_id', $ruleIds)->delete();
        DB::table('conditional_rules')->whereIn('id', $ruleIds)->delete();
        DB::table('scoring_rules')->whereIn('form_component_id', $componentIds)->delete();
        DB::table('validation_rules')->whereIn('form_component_id', $componentIds)->delete();
        DB::table('component_options')->whereIn('form_component_id', $componentIds)->delete();
        QuestionnairePackagePartImport::where('form_id', $form->id)->delete();
        QuestionnairePackageImport::where('form_id', $form->id)->delete();
        $attachmentQuery->delete();
        DB::table('form_components')->whereIn('id', $componentIds)->delete();
        DB::table('form_sections')->whereIn('id', $sectionIds)->delete();
        DB::table('form_versions')->whereIn('id', $versionIds)->delete();
        $form->forceDelete();
        return $storageFiles;
    }

    private function hasRetainedFormAuditEvidence(Form $form, Collection $versionIds): bool
    {
        $attachmentIds = Attachment::where('attachable_type', (new FormVersion())->getMorphClass())
            ->whereIn('attachable_id', $versionIds)->pluck('id');
        $packageImportIds = QuestionnairePackageImport::where('form_id', $form->id)->pluck('id');
        $partImportIds = QuestionnairePackagePartImport::where('form_id', $form->id)->pluck('id');
        $subjects = [
            [(new Form())->getMorphClass(), collect([$form->id])],
            [(new FormVersion())->getMorphClass(), $versionIds],
            [(new Attachment())->getMorphClass(), $attachmentIds],
            [(new QuestionnairePackageImport())->getMorphClass(), $packageImportIds],
            [(new QuestionnairePackagePartImport())->getMorphClass(), $partImportIds],
        ];

        return AuditLog::where(function ($query) use ($subjects): void {
            foreach ($subjects as [$type, $ids]) {
                if ($ids->isEmpty()) continue;
                $query->orWhere(fn ($subject) => $subject->where('subject_type', $type)->whereIn('subject_id', $ids));
            }
        })->get(['action', 'subject_type', 'subject_id'])
            ->contains(fn (AuditLog $log) => !(
                $log->action === 'form.created'
                && $log->subject_type === $form->getMorphClass()
                && (int) $log->subject_id === $form->id
            ));
    }

    private function isOrganisationCreationAudit(AuditLog $log, Organisation $organisation, Collection $formIds): bool
    {
        $organisationCreation = $log->action === 'organisation.created'
            && $log->subject_type === $organisation->getMorphClass()
            && (int) $log->subject_id === $organisation->id;
        $formCreation = $log->action === 'form.created'
            && $log->subject_type === (new Form())->getMorphClass()
            && $formIds->contains((int) $log->subject_id);

        return $organisationCreation || $formCreation;
    }

    private function ensureAllowed(array $eligibility, string $field): void
    {
        if (!$eligibility['allowed']) throw ValidationException::withMessages([$field => $eligibility['reason']]);
    }

    private function allowed(string $mode = 'hard'): array { return ['allowed' => true, 'reason' => null, 'mode' => $mode]; }
    private function denied(string $reason): array { return ['allowed' => false, 'reason' => $reason, 'mode' => null]; }
}
