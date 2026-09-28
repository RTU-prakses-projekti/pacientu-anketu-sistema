<?php

namespace App\Http\Controllers;

use App\Domain\Patients\PatientAccessService;
use App\Domain\Patients\PatientQuestionnaireAssignmentService;
use App\Models\PatientAccessPackage;
use App\Models\PatientCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DoctorQuestionnaireController extends Controller
{
    public function index(PatientCase $patientCase, PatientQuestionnaireAssignmentService $assignments)
    {
        $this->authorize('viewQuestionnaires', $patientCase);
        $patientCase->load(['assignments.publication.formVersion', 'assignments.completedSubmission', 'accessPackages' => fn ($query) => $query->latest()]);
        $publications = $assignments->availableFor($patientCase);
        $activePackage = $patientCase->accessPackages->first(fn ($package) => $package->isUsable());
        $activePackageValidityDays = 30;
        if ($activePackage) {
            foreach ([7, 14, 30, 60, 90] as $days) {
                if (abs($activePackage->created_at->copy()->addDays($days)->diffInSeconds($activePackage->expires_at)) <= 5) {
                    $activePackageValidityDays = $days;
                    break;
                }
            }
        }

        return view('doctor.questionnaires.index', compact('patientCase', 'publications', 'activePackage', 'activePackageValidityDays'));
    }

    public function store(Request $request, PatientCase $patientCase, PatientQuestionnaireAssignmentService $assignments)
    {
        $this->authorize('update', $patientCase);
        $data = $request->validate([
            'publication_id' => ['required', 'integer'],
            'label' => ['required', 'string', 'max:255'], 'display_order' => ['required', 'integer', 'min:1', 'max:1000'],
        ], ['publication_id.required' => __('messages.select_survey')]);
        $assignments->assign($request->user(), collect([$patientCase]), (int) $data['publication_id'], $data['label'], (int) $data['display_order']);
        return back()->with('success', __('messages.questionnaire_assigned'));
    }

    public function bulkCreate(Request $request, PatientQuestionnaireAssignmentService $assignments)
    {
        $patientCases = $this->selectedPatients($request);
        $publications = $assignments->availableForPatients($patientCases);

        return view('doctor.questionnaires.bulk', compact('patientCases', 'publications'));
    }

    public function bulkStore(Request $request, PatientQuestionnaireAssignmentService $assignments, PatientAccessService $access)
    {
        if (empty($request->input('patient_case_ids')) && !$request->filled('publication_id')) {
            throw ValidationException::withMessages(['patient_case_ids' => __('messages.select_patients_and_survey')]);
        }

        $patientCases = $this->selectedPatients($request);
        $data = $request->validate(
            ['publication_id' => ['required', 'integer'], 'expires_in_days' => ['nullable', Rule::in([7, 14, 30, 60, 90])]],
            ['publication_id.required' => __('messages.select_survey')],
        );
        $links = DB::transaction(function () use ($request, $patientCases, $assignments, $access, $data) {
            $created = $assignments->assign($request->user(), $patientCases, (int) $data['publication_id']);
            return $created->load('patientCase')->map(function ($assignment) use ($request, $access, $data): array {
                [, $plainToken] = $access->issue($assignment->patientCase, $request->user()->id, (int) ($data['expires_in_days'] ?? 30));
                $patient = $assignment->patientCase;
                return ['name' => trim($patient->first_name.' '.$patient->last_name) ?: $patient->patient_code, 'patient_code' => $patient->patient_code, 'url' => route('patient.access', $plainToken)];
            })->values();
        });

        return response()->view('doctor.questionnaires.bulk-result', ['links' => $links, 'organisationId' => $patientCases->first()->organisation_id])
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function issueLink(Request $request, PatientCase $patientCase, PatientAccessService $access)
    {
        $this->authorize('update', $patientCase);
        $data = $request->validate(['expires_in_days' => ['required', Rule::in([7, 14, 30, 60, 90])]]);
        if (!$patientCase->assignments()->exists()) {
            return back()->withErrors(['assignments' => __('messages.assign_questionnaire_first')]);
        }
        [$package, $plainToken] = $access->issue($patientCase, $request->user()->id, (int) $data['expires_in_days']);
        return back()->with('success', __('messages.patient_link_created'))->with('patient_access_url', route('patient.access', $plainToken));
    }

    public function revokeLink(PatientCase $patientCase, PatientAccessPackage $patientAccessPackage, PatientAccessService $access)
    {
        $this->authorize('update', $patientCase);
        abort_unless($patientAccessPackage->patient_case_id === $patientCase->id, 404);
        $access->revoke($patientAccessPackage);
        return back()->with('success', __('messages.patient_link_revoked'));
    }

    private function selectedPatients(Request $request)
    {
        $data = $request->validate([
            'patient_case_ids' => ['required', 'array', 'min:1', 'max:200'],
            'patient_case_ids.*' => ['required', 'integer', 'distinct'],
        ], [
            'patient_case_ids.required' => __('messages.select_at_least_one_patient'),
            'patient_case_ids.array' => __('messages.select_at_least_one_patient'),
            'patient_case_ids.min' => __('messages.select_at_least_one_patient'),
            'patient_case_ids.*.required' => __('messages.select_at_least_one_patient'),
        ]);
        $ids = collect($data['patient_case_ids'])->map(fn ($id) => (int) $id);
        $patientCases = PatientCase::query()->whereIn('id', $ids)->get();
        abort_unless($patientCases->count() === $ids->count(), 404);
        foreach ($patientCases as $patientCase) {
            $this->authorize('update', $patientCase);
        }
        abort_unless($patientCases->pluck('organisation_id')->unique()->count() === 1, 422);

        return $patientCases;
    }
}
