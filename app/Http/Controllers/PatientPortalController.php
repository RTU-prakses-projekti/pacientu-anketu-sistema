<?php

namespace App\Http\Controllers;

use App\Domain\Patients\PatientAccessService;
use App\Domain\Submissions\SubmissionService;
use App\Models\PatientAccessPackage;
use App\Models\PatientFormAssignment;
use Illuminate\Http\Request;

class PatientPortalController extends Controller
{
    public function access(Request $request, string $token, PatientAccessService $access)
    {
        $package = $access->consumeToken($request, $token);
        abort_unless($package, 404);
        return redirect()->route('patient.portal', $package)->withHeaders(['Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'no-store']);
    }

    public function portal(Request $request, PatientAccessPackage $patientAccessPackage, PatientAccessService $access)
    {
        $access->assertPackage($request, $patientAccessPackage);
        $patientAccessPackage->load(['patientCase.assignments.publication.formVersion', 'patientCase.assignments.submissions']);
        $previousComplete = true;
        $parts = $patientAccessPackage->patientCase->assignments->map(function ($assignment) use (&$previousComplete) {
            $status = $assignment->status();
            $part = ['assignment' => $assignment, 'status' => $status, 'unlocked' => $previousComplete];
            $previousComplete = $previousComplete && $status === 'completed';
            return $part;
        });
        return view('patient.portal', compact('patientAccessPackage', 'parts'));
    }

    public function start(Request $request, PatientAccessPackage $patientAccessPackage, PatientFormAssignment $assignment, PatientAccessService $access, SubmissionService $submissions)
    {
        $access->assertPackage($request, $patientAccessPackage);
        abort_unless($assignment->patient_case_id === $patientAccessPackage->patient_case_id && $assignment->patient_access_package_id === $patientAccessPackage->id, 404);
        $previousIncomplete = $assignment->patientCase->assignments()->where(function ($query) use ($assignment) {
            $query->where('display_order', '<', $assignment->display_order)
                ->orWhere(fn ($tie) => $tie->where('display_order', $assignment->display_order)->where('id', '<', $assignment->id));
        })->whereDoesntHave('completedSubmission')->exists();
        abort_if($previousIncomplete, 409, __('messages.previous_part_required'));
        if ($assignment->status() === 'completed') return redirect()->route('patient.portal', $patientAccessPackage);
        $submission = $submissions->startForInvitation($assignment->publication, $assignment->invitation);
        return redirect()->route('submissions.take', $submission);
    }
}
