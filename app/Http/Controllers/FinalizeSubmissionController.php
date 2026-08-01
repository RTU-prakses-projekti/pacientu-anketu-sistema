<?php

namespace App\Http\Controllers;

use App\Domain\Submissions\SubmissionService;
use App\Http\Requests\FinalizeSubmissionRequest;
use App\Models\FormSubmission;

class FinalizeSubmissionController extends Controller
{
    public function __invoke(FinalizeSubmissionRequest $request, FormSubmission $submission, SubmissionService $service)
    {
        RespondentController::assertOwner($submission,$request);
        $final = $service->finalizeWithSnapshot($submission, (int) $request->expected_revision, $request->client_mutation_id, $request->answers);
        return response()->json(['success'=>true,'revision'=>$final->revision,'redirect'=>route('submissions.complete',$submission)]);
    }
}
