<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditService;
use App\Domain\Patients\PatientAccessService;
use App\Models\Invitation;
use App\Models\PatientAccessPackage;
use App\Models\PatientCase;
use App\Models\Publication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DoctorQuestionnaireController extends Controller
{
    public function index(PatientCase $patientCase)
    {
        $this->authorize('viewQuestionnaires', $patientCase);
        $patientCase->load(['assignments.publication.formVersion', 'assignments.completedSubmission', 'accessPackages' => fn ($query) => $query->latest()]);
        $publications = Publication::query()->with(['form', 'formVersion'])
            ->where('organisation_id', $patientCase->organisation_id)->where('status', 'active')->where('access_mode', 'invitation')
            ->whereHas('formVersion', fn ($query) => $query->where('status', 'published'))
            ->whereNotIn('id', $patientCase->assignments->pluck('publication_id'))->orderBy('name')->get()->filter->isOpen();
        $activePackage = $patientCase->accessPackages->first(fn ($package) => $package->isUsable());
        return view('doctor.questionnaires.index', compact('patientCase', 'publications', 'activePackage'));
    }

    public function store(Request $request, PatientCase $patientCase, AuditService $audit)
    {
        $this->authorize('update', $patientCase);
        $data = $request->validate([
            'publication_id' => ['required', 'integer', Rule::unique('patient_form_assignments')->where('patient_case_id', $patientCase->id)],
            'label' => ['required', 'string', 'max:255'], 'display_order' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);
        $publication = Publication::with('formVersion')->whereKey($data['publication_id'])->where('organisation_id', $patientCase->organisation_id)
            ->where('status', 'active')->where('access_mode', 'invitation')->firstOrFail();
        abort_unless($publication->isOpen() && $publication->formVersion->status === 'published', 422);
        $activePackage = $patientCase->accessPackages()->whereNull('revoked_at')->where('expires_at', '>', now())->latest()->first();
        $assignment = DB::transaction(function () use ($patientCase, $publication, $activePackage, $data) {
            $invitation = Invitation::create(['publication_id' => $publication->id, 'token_hash' => hash('sha256', Str::random(64)),
                'recipient_reference' => $patientCase->public_id, 'expires_at' => $activePackage?->expires_at, 'max_uses' => 1]);
            return $patientCase->assignments()->create(['publication_id' => $publication->id, 'invitation_id' => $invitation->id,
                'patient_access_package_id' => $activePackage?->id, 'label' => trim($data['label']), 'display_order' => $data['display_order']]);
        });
        $audit->record('patient_questionnaire.assigned', $assignment, $patientCase->organisation_id, ['publication_id' => $publication->id, 'display_order' => $assignment->display_order]);
        return back()->with('success', __('messages.questionnaire_assigned'));
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
}
