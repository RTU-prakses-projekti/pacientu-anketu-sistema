<?php

namespace App\Http\Controllers;

use App\Domain\Submissions\SubmissionService;
use App\Http\Requests\AutosaveRequest;
use App\Models\FormSubmission;

class AutosaveController extends Controller
{
    public function __invoke(AutosaveRequest $request, FormSubmission $submission, SubmissionService $service)
    {
        RespondentController::assertOwner($submission,$request);
        return response()->json($service->autosave($submission,(int)$request->expected_revision,$request->client_mutation_id,$request->answers));
    }
}
