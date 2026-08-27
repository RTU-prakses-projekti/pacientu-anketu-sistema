<?php

namespace App\Domain\Patients;

use App\Domain\Audit\AuditService;
use App\Models\Invitation;
use App\Models\PatientAccessPackage;
use App\Models\PatientCase;
use App\Models\PatientFormAssignment;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PatientQuestionnaireAssignmentService
{
    public function __construct(private AuditService $audit) {}

    public function availableFor(PatientCase $patientCase): Collection
    {
        return $this->availableForPatients(collect([$patientCase]));
    }

    public function availableForPatients(Collection $patientCases): Collection
    {
        if ($patientCases->isEmpty()) {
            return collect();
        }

        $organisationIds = $patientCases->pluck('organisation_id')->unique();
        if ($organisationIds->count() !== 1) {
            throw ValidationException::withMessages(['patient_case_ids' => __('messages.patients_must_share_workspace')]);
        }

        $assignedPublicationIds = PatientFormAssignment::query()
            ->whereIn('patient_case_id', $patientCases->pluck('id'))
            ->pluck('publication_id');

        return $this->eligibleQuery((int) $organisationIds->first())
            ->whereNotIn('id', $assignedPublicationIds)
            ->orderBy('name')
            ->get();
    }

    public function assign(
        User $actor,
        Collection $patientCases,
        int $publicationId,
        ?string $label = null,
        ?int $displayOrder = null,
    ): Collection {
        if ($patientCases->isEmpty()) {
            throw ValidationException::withMessages(['patient_case_ids' => __('messages.select_patient')]);
        }

        return DB::transaction(function () use ($actor, $patientCases, $publicationId, $label, $displayOrder): Collection {
            $ids = $patientCases->pluck('id')->map(fn ($id) => (int) $id)->unique()->values();
            if ($ids->count() !== $patientCases->count()) {
                throw ValidationException::withMessages(['patient_case_ids' => __('messages.duplicate_patients_not_allowed')]);
            }

            $lockedPatients = PatientCase::query()->whereIn('id', $ids)->lockForUpdate()->get();
            if ($lockedPatients->count() !== $ids->count()) {
                throw ValidationException::withMessages(['patient_case_ids' => __('messages.invalid_patient_selection')]);
            }

            $organisationIds = $lockedPatients->pluck('organisation_id')->unique();
            if ($organisationIds->count() !== 1 || $lockedPatients->contains(fn (PatientCase $patient) => $patient->doctor_id !== $actor->id)) {
                abort(403);
            }

            $organisationId = (int) $organisationIds->first();
            abort_unless($actor->hasDoctorPermission($organisationId, 'patients.update'), 403);

            $publication = $this->eligibleQuery($organisationId)->lockForUpdate()->find($publicationId);
            if (!$publication || PatientFormAssignment::query()->whereIn('patient_case_id', $ids)->where('publication_id', $publicationId)->exists()) {
                throw ValidationException::withMessages(['publication_id' => __('messages.questionnaire_not_available')]);
            }

            $activePackages = PatientAccessPackage::query()
                ->whereIn('patient_case_id', $ids)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->latest()
                ->get()
                ->unique('patient_case_id')
                ->keyBy('patient_case_id');
            $nextOrders = PatientFormAssignment::query()
                ->whereIn('patient_case_id', $ids)
                ->select('patient_case_id', DB::raw('MAX(display_order) as maximum_order'))
                ->groupBy('patient_case_id')
                ->pluck('maximum_order', 'patient_case_id');

            return $lockedPatients->sortBy('id')->values()->map(function (PatientCase $patient) use ($publication, $activePackages, $nextOrders, $label, $displayOrder, $lockedPatients): PatientFormAssignment {
                $activePackage = $activePackages->get($patient->id);
                $invitation = Invitation::create([
                    'publication_id' => $publication->id,
                    'token_hash' => hash('sha256', Str::random(64)),
                    'recipient_reference' => $patient->public_id,
                    'expires_at' => $activePackage?->expires_at,
                    'max_uses' => 1,
                ]);
                $order = $lockedPatients->count() === 1 && $displayOrder !== null
                    ? $displayOrder
                    : ((int) $nextOrders->get($patient->id)) + 1;
                $assignment = $patient->assignments()->create([
                    'publication_id' => $publication->id,
                    'invitation_id' => $invitation->id,
                    'patient_access_package_id' => $activePackage?->id,
                    'label' => filled($label) ? trim($label) : $publication->name,
                    'display_order' => $order,
                ]);
                $this->audit->record('patient_questionnaire.assigned', $assignment, $patient->organisation_id, [
                    'publication_id' => $publication->id,
                    'display_order' => $assignment->display_order,
                ]);

                return $assignment;
            });
        });
    }

    private function eligibleQuery(int $organisationId): Builder
    {
        return Publication::query()
            ->with(['form', 'formVersion'])
            ->where('organisation_id', $organisationId)
            ->where('status', 'active')
            ->whereIn('access_mode', ['invitation', 'public'])
            ->where(fn ($query) => $query->whereNull('opens_at')->orWhere('opens_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('closes_at')->orWhere('closes_at', '>=', now()))
            ->whereHas('form', fn ($query) => $query->where('status', 'published'))
            ->whereHas('formVersion', fn ($query) => $query->where('status', 'published'));
    }
}
