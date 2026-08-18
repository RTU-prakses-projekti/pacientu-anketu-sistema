<?php

namespace App\Http\Controllers;

use App\Domain\Patients\PatientAccessService;
use App\Domain\Patients\PatientQuestionnaireAssignmentService;
use App\Models\PatientAccessPackage;
use App\Models\PatientCase;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DoctorQuestionnaireController extends Controller
{
    public function index(PatientCase $patientCase, PatientQuestionnaireAssignmentService $assignments)
    {
        $this->authorize('viewQuestionnaires', $patientCase);
        $patientCase->load(['assignments.publication.formVersion', 'assignments.completedSubmission', 'accessPackages' => fn ($query) => $query->latest()]);
        $publications = $assignments->availableFor($patientCase);
        $activePackage = $patientCase->accessPackages->first(fn ($package) => $package->isUsable());
        return view('doctor.questionnaires.index', compact('patientCase', 'publications', 'activePackage'));
    }

    public function store(Request $request, PatientCase $patientCase, PatientQuestionnaireAssignmentService $assignments)
    {
        $this->authorize('update', $patientCase);
        $data = $request->validate([
            'publication_id' => ['required', 'integer'],
            'label' => ['required', 'string', 'max:255'], 'display_order' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);
        $assignments->assign($request->user(), collect([$patientCase]), (int) $data['publication_id'], $data['label'], (int) $data['display_order']);
        return back()->with('success', __('messages.questionnaire_assigned'));
    }

    public function bulkCreate(Request $request, PatientQuestionnaireAssignmentService $assignments)
    {
        $patientCases = $this->selectedPatients($request);
        $publications = $assignments->availableForPatients($patientCases);

        return view('doctor.questionnaires.bulk', compact('patientCases', 'publications'));
    }

    public function bulkStore(Request $request, PatientQuestionnaireAssignmentService $assignments)
    {
        $patientCases = $this->selectedPatients($request);
        $data = $request->validate(['publication_id' => ['required', 'integer']]);
        $created = $assignments->assign($request->user(), $patientCases, (int) $data['publication_id']);

        return redirect()->route('doctor.dashboard', ['organisation_id' => $patientCases->first()->organisation_id])
            ->with('success', __('messages.questionnaire_assigned_to_patients', ['count' => $created->count()]));
    }

    public function issueLink(Request $request, PatientCase $patientCase, PatientAccessService $access)
    {
        $this->authorize('update', $patientCase);
        $data = $request->validate(['expires_in_days' => ['required', Rule::in([7, 14, 30, 60, 90])]]);
        abort_if($patientCase->assignments()->doesntExist(), 422, __('messages.assign_questionnaire_first'));
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
