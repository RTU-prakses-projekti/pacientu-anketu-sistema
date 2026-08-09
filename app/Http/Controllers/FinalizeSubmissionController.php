<?php

namespace App\Http\Controllers;

use App\Domain\Submissions\SubmissionService;
use App\Domain\Patients\PatientAccessService;
use App\Http\Requests\FinalizeSubmissionRequest;
use App\Models\FormSubmission;

class FinalizeSubmissionController extends Controller
{
    public function __invoke(FinalizeSubmissionRequest $request, FormSubmission $submission, SubmissionService $service, PatientAccessService $patientAccess)
    {
        RespondentController::assertOwner($submission,$request);
        $final = $service->finalizeWithSnapshot($submission, (int) $request->expected_revision, $request->client_mutation_id, $request->answers);
        $package = $patientAccess->packageForSubmission($request, $final);
        $redirect = $package ? route('patient.portal', $package) : route('submissions.complete',$submission);
        return response()->json(['success'=>true,'revision'=>$final->revision,'redirect'=>$redirect]);
    }
}
