<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditService;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\PatientCase;
use App\Models\PatientFormAssignment;
use App\Models\FormSubmission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

class DoctorDashboardController extends Controller
{
    public function index(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor->hasDoctorWorkspace(), 403);

        $workspaces = OrganisationMembership::query()
            ->with(['organisation', 'user'])
            ->where('is_active', true)
            ->whereHas('organisation', fn ($query) => $query->where('is_active', true))
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
        if ($selected) {
            $patientCases = PatientCase::query()
                ->visibleTo($actor)
                ->where('organisation_id', $selected->organisation_id)
                ->withCount([
                    'assignments',
                    'assignments as completed_assignments_count' => fn ($query) => $query->whereHas('submissions', fn ($submissions) => $submissions->whereIn('status', FormSubmission::PATIENT_COMPLETED_STATUSES)),
                    'assignments as in_progress_assignments_count' => fn ($query) => $query
                        ->whereHas('submissions', fn ($submissions) => $submissions->where('status', 'in_progress'))
                        ->whereDoesntHave('submissions', fn ($submissions) => $submissions->whereIn('status', FormSubmission::PATIENT_COMPLETED_STATUSES)),
                ])
                ->orderBy('slot_number')
                ->paginate(50)
                ->withQueryString();
        }

        return view('doctor.dashboard', [
            'workspaces' => $workspaces,
            'selectedMembership' => $selected,
            'patientCases' => $patientCases,
        ]);
    }

    public function storePatient(Request $request, Organisation $organisation, AuditService $audit)
    {
        $actor = $request->user();
        abort_unless($actor->hasDoctorWorkspace(), 403);
        $data = $this->patientData($request);

        $patientCase = DB::transaction(function () use ($actor, $organisation, $data): PatientCase {
            $membership = OrganisationMembership::query()
                ->where('organisation_id', $organisation->id)
                ->where('user_id', $actor->id)
                ->where('is_active', true)
                ->whereHas('organisation', fn ($query) => $query->where('is_active', true))
                ->whereHas('roles', fn ($query) => $query->where('roles.name', 'doctor'))
                ->lockForUpdate()
                ->first();
            abort_unless($membership && $actor->hasDoctorPermission($organisation->id, 'patients.update'), 403);

            $slot = ((int) PatientCase::query()
                ->where('organisation_id', $organisation->id)
                ->where('doctor_id', $actor->id)
                ->max('slot_number')) + 1;
            if ($slot > 200) {
                throw ValidationException::withMessages(['patient' => __('messages.patient_limit_reached')]);
            }

            return PatientCase::create(array_merge($data, [
                'organisation_id' => $organisation->id,
                'doctor_id' => $actor->id,
                'slot_number' => $slot,
            ]));
        });
        $audit->record('patient_case.created', $patientCase, $organisation->id, ['slot_number' => $patientCase->slot_number]);

        return redirect()->route('doctor.dashboard', ['organisation_id' => $organisation->id])
            ->with('success', __('messages.patient_saved'));
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
        $data = $this->patientData($request);

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

    public function exportForm(Request $request, Organisation $organisation)
    {
        $actor = $request->user();
        abort_unless($actor->hasDoctorPermission($organisation->id, 'patient.questionnaires.view'), 403);
        $data = $request->validate(['patient_case_ids' => ['nullable', 'array', 'min:1', 'max:200'], 'patient_case_ids.*' => ['integer', 'distinct']]);

        return view('doctor.export', ['organisation' => $organisation, 'patientCaseIds' => $data['patient_case_ids'] ?? []]);
    }

    public function exportAnswers(Request $request, Organisation $organisation)
    {
        $actor = $request->user();
        abort_unless($actor->hasDoctorPermission($organisation->id, 'patient.questionnaires.view'), 403);
        $data = $request->validate([
            'format' => ['required', Rule::in(['csv', 'xlsx'])],
            'anonymize' => ['sometimes', 'boolean'],
            'patient_case_ids' => ['nullable', 'array', 'min:1', 'max:200'],
            'patient_case_ids.*' => ['integer', 'distinct'],
        ]);
        $anonymize = $request->boolean('anonymize', true);

        $patientCases = PatientCase::query()->visibleTo($actor)
            ->where('organisation_id', $organisation->id)
            ->when($data['patient_case_ids'] ?? null, fn ($query, $ids) => $query->whereIn('id', $ids))
            ->with(['assignments.completedSubmission.answers.component'])
            ->orderBy('slot_number')
            ->get();

        $rows = [];
        foreach ($patientCases as $patientCase) {
            $patientName = $anonymize ? '' : trim($patientCase->first_name.' '.$patientCase->last_name);
            $hasAnswers = false;
            foreach ($patientCase->assignments as $assignment) {
                $submission = $assignment->completedSubmission;
                if (!$submission) continue;
                foreach ($submission->answers as $answer) {
                    $rows[] = [$patientCase->patient_code, $patientName, $assignment->label, $answer->component->label, $answer->display_value];
                    $hasAnswers = true;
                }
            }
            if ($hasAnswers) $rows[] = array_fill(0, 5, '');
        }
        $header = [__('messages.research_id'), __('messages.patient'), __('messages.questionnaires'), __('messages.component'), __('messages.answer')];

        return $data['format'] === 'xlsx' ? $this->downloadXlsx($header, $rows) : $this->downloadCsv($header, $rows);
    }

    private function downloadCsv(array $header, array $rows)
    {
        return response()->streamDownload(function () use ($header, $rows): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, $header);
            foreach ($rows as $row) fputcsv($handle, array_map([$this, 'csvSafe'], $row));
            fclose($handle);
        }, 'patient-answers.csv');
    }

    private function downloadXlsx(array $header, array $rows)
    {
        $path = tempnam(sys_get_temp_dir(), 'patient-export').'.xlsx';
        $headerStyle = (new \OpenSpout\Common\Entity\Style\Style())
            ->withFontBold(true)
            ->withFontColor('FFFFFF')
            ->withBackgroundColor('4F46E5')
            ->withShouldWrapText(true);
        $writer = new Writer();
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Patient answers');
        $writer->getCurrentSheet()->setColumnWidth(20, 1);
        $writer->getCurrentSheet()->setColumnWidth(28, 2);
        $writer->getCurrentSheet()->setColumnWidth(32, 3);
        $writer->getCurrentSheet()->setColumnWidth(42, 4);
        $writer->getCurrentSheet()->setColumnWidth(56, 5);
        $writer->addRow(Row::fromValues(['Patient answer export']));
        $writer->addRow(Row::fromValuesWithStyle($header, $headerStyle));
        foreach ($rows as $row) $writer->addRow(Row::fromValues(array_map([$this, 'csvSafe'], $row)));
        $writer->close();

        return response()->download($path, 'patient-answers.xlsx')->deleteFileAfterSend(true);
    }

    private function csvSafe(mixed $value): mixed
    {
        if (!is_string($value)) return $value;
        return preg_match('/^[=+\-@\t\r]/u', $value) ? "'".$value : $value;
    }

    private function patientData(Request $request): array
    {
        $data = $request->validate([
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'external_patient_code' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:10000'],
        ]);
        foreach ($data as $field => $value) {
            $data[$field] = filled($value) ? trim($value) : null;
        }

        return $data;
    }
}
