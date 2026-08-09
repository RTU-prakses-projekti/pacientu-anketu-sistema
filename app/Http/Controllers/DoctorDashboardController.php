<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditService;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\PatientCase;
use App\Models\PatientFormAssignment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DoctorDashboardController extends Controller
{
    public function index(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor->hasDoctorWorkspace(), 403);

        $workspaces = OrganisationMembership::query()
            ->with(['organisation', 'user'])
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('roles.name', 'doctor'))
            ->where('user_id', $actor->id)
            ->get()
            ->sortBy(fn ($membership) => $membership->organisation->name.'|'.$membership->user->name)
            ->values();

        $selected = $workspaces->first(function ($membership) use ($request) {
            return (!$request->filled('organisation_id') || $membership->organisation_id === $request->integer('organisation_id'))
                && (!$request->filled('doctor_id') || $membership->user_id === $request->integer('doctor_id'));
        });

        if (($request->filled('organisation_id') || $request->filled('doctor_id')) && !$selected) {
            abort(404);
        }

        $patientCases = collect();
        $columns = collect();
        if ($selected) {
            $patientCases = PatientCase::query()
                ->visibleTo($actor)
                ->where('organisation_id', $selected->organisation_id)
                ->with(['assignments.publication.formVersion', 'assignments.completedSubmission'])
                ->orderBy('slot_number')
                ->get()
                ->keyBy('slot_number');

            $columns = $patientCases
                ->flatMap(fn (PatientCase $patientCase) => $patientCase->assignments)
                ->sortBy(fn (PatientFormAssignment $assignment) => sprintf('%010d|%s|%010d', $assignment->display_order, $assignment->label, $assignment->publication_id))
                ->unique('publication_id')
                ->values();
        }

        return view('doctor.dashboard', [
            'workspaces' => $workspaces,
            'selectedMembership' => $selected,
            'patientCases' => $patientCases,
            'columns' => $columns,
            'slots' => range(1, 200),
        ]);
    }

    public function updateSlot(Request $request, Organisation $organisation, User $doctor, int $slot, AuditService $audit)
    {
        abort_unless($slot >= 1 && $slot <= 200, 404);
        abort_unless($doctor->is_active && OrganisationMembership::query()
            ->where('organisation_id', $organisation->id)
            ->where('user_id', $doctor->id)
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('roles.name', 'doctor'))
            ->exists(), 404);

        $patientCase = PatientCase::firstOrNew([
            'organisation_id' => $organisation->id,
            'doctor_id' => $doctor->id,
            'slot_number' => $slot,
        ]);
        $this->authorize('update', $patientCase);
        $data = $request->validate([
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'external_patient_code' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:10000'],
        ]);

        $created = !$patientCase->exists;
        DB::transaction(function () use ($patientCase, $data): void {
            foreach (['first_name', 'last_name', 'external_patient_code', 'note'] as $field) {
                $patientCase->{$field} = filled($data[$field] ?? null) ? trim($data[$field]) : null;
            }
            $patientCase->save();
        });
        $audit->record($created ? 'patient_case.created' : 'patient_case.updated', $patientCase, $organisation->id, ['slot_number' => $slot]);

        return redirect()->route('doctor.dashboard', ['organisation_id' => $organisation->id, 'doctor_id' => $doctor->id])
            ->with('success', __('messages.patient_saved'));
    }

    public function result(Request $request, PatientCase $patientCase, PatientFormAssignment $assignment)
    {
        $this->authorize('viewQuestionnaires', $patientCase);
        abort_unless($assignment->patient_case_id === $patientCase->id, 404);
        abort_unless($assignment->invitation_id, 404);
        $submission = $assignment->completedSubmission()->firstOrFail();
        $submission->load('publication.form', 'formVersion', 'answers.component.options', 'answers.score');

        return view('doctor.results.show', compact('patientCase', 'assignment', 'submission'));
    }
}
