<?php

namespace App\Http\Controllers;

use App\Domain\Results\AnonymizedResultHandoffService;
use App\Models\AnonymizedResultHandoff;
use App\Models\Organisation;
use App\Models\PatientCase;
use App\Models\PatientFormAssignment;
use Illuminate\Http\Request;

class AnonymizedResultController extends Controller
{
    public function store(Request $request, PatientCase $patientCase, PatientFormAssignment $assignment, AnonymizedResultHandoffService $service)
    {
        abort_unless($assignment->patient_case_id === $patientCase->id, 404);
        $this->authorize('viewQuestionnaires', $patientCase);
        $data = $request->validate(['recipient' => ['required', 'integer']]);
        $submission = $assignment->completedSubmission()->firstOrFail();
        $handoff = $service->handoff($request->user(), $assignment, $submission, (int) $data['recipient']);
        return back()->with('success', __('messages.result_handed_off').' '.__('messages.handed_off', ['name' => $handoff->recipient->name]));
    }

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canReceiveAnonymizedResults(), 403);
        $isRoot = $user->isBootstrapRoot();
        abort_unless($isRoot
            ? Organisation::where('is_active', true)->exists()
            : $user->memberships()->where('is_active', true)->whereHas('organisation', fn ($organisation) => $organisation->where('is_active', true))->exists(), 403);
        $hasGlobalPermission = $user->isBootstrapRoot() || $user->globalRoles()->whereHas('permissions', fn ($permissions) => $permissions->where('permissions.name', 'anonymized_results.view'))->exists();
        $query = AnonymizedResultHandoff::whereHas('organisation', fn ($organisation) => $organisation->where('organisations.is_active', true));
        if (!$isRoot) $query->where('recipient_user_id', $user->id)->whereHas('organisation.memberships', function ($membership) use ($user, $hasGlobalPermission): void {
            $membership->where('user_id', $user->id)->where('is_active', true)
                ->when(!$hasGlobalPermission, fn ($membership) => $membership->whereHas('roles.permissions', fn ($permissions) => $permissions->where('permissions.name', 'anonymized_results.view')));
        });
        $handoffs = $query->with(['submission.publication.form', 'assignment.patientCase:id,patient_code'])
            ->latest('handed_off_at')->paginate(25);
        return view('anonymized-results.index', compact('handoffs'));
    }

    public function show(Request $request, AnonymizedResultHandoff $handoff)
    {
        abort_unless(($request->user()->isBootstrapRoot() || $handoff->recipient_user_id === $request->user()->id)
            && $request->user()->is_active
            && Organisation::whereKey($handoff->organisation_id)->where('is_active', true)->exists()
            && ($request->user()->isBootstrapRoot() || ($request->user()->memberships()->where('organisation_id', $handoff->organisation_id)->where('is_active', true)->exists()
                && $request->user()->canViewAnonymizedResults($handoff->organisation_id))), 403);
        $handoff->load([
            'organisation',
            'submission.publication.form',
            'submission.answers' => fn ($answers) => $answers->whereHas('component', fn ($component) => $component->where('is_sensitive', false)),
            'submission.answers.component.options',
            'assignment.patientCase:id,patient_code',
        ]);
        $patientCode = $handoff->assignment->patientCase->patient_code;
        $formName = $handoff->submission->publication->form->name;
        $submittedAt = $handoff->submission->submitted_at;
        $handedOffAt = $handoff->handed_off_at;
        $answers = $handoff->submission->answers->filter(fn ($answer) => !$answer->component->is_sensitive)->values();
        return view('anonymized-results.show', compact('patientCode', 'formName', 'submittedAt', 'handedOffAt', 'answers'));
    }
}
