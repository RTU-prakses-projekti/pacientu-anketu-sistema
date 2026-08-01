<?php

namespace App\Domain\Exports;

use App\Domain\Audit\AuditService;
use App\Models\Export;
use App\Models\FormSubmission;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

class ExportService
{
    public function __construct(private AuditService $audit) {}

    public function generate(Export $export): Export
    {
        $export->update(['status' => 'processing']);
        try {
            $submissions = FormSubmission::with('publication.form', 'user', 'answers.component')->where('organisation_id', $export->organisation_id)
                ->when($export->form_id, fn ($q) => $q->whereHas('publication', fn ($p) => $p->where('form_id', $export->form_id)))->get();
            $relative = 'exports/'.$export->organisation_id.'/'.$export->public_id.'.'.$export->format;
            Storage::disk('local')->makeDirectory(dirname($relative));
            $path = Storage::disk('local')->path($relative);
            $export->format === 'xlsx' ? $this->xlsx($path, $submissions) : $this->csv($path, $submissions);
            $export->update(['status' => 'completed', 'storage_path' => $relative, 'expires_at' => now()->addDays(7)]);
            $this->audit->record('export.completed', $export, $export->organisation_id, ['format' => $export->format, 'rows' => $submissions->count()]);
        } catch (\Throwable $exception) {
            $export->update(['status' => 'failed', 'failure_message' => mb_substr($exception->getMessage(), 0, 1000)]);
            throw $exception;
        }
        return $export->fresh();
    }

    private function csv(string $path, $submissions): void
    {
        $handle = fopen($path, 'wb');
        fputcsv($handle, ['Submission', 'Form', 'Version', 'Status', 'Respondent', 'Attempt', 'Started', 'Submitted', 'Score', 'Percentage']);
        foreach ($submissions as $submission) fputcsv($handle, array_map([$this, 'safe'], $this->submissionRow($submission)));
        fclose($handle);
    }

    private function xlsx(string $path, $submissions): void
    {
        $writer = new Writer(); $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Summary');
        $writer->addRow(Row::fromValues(['Universal Form Builder export'])); $writer->addRow(Row::fromValues(['Generated', now()->toIso8601String()])); $writer->addRow(Row::fromValues(['Submissions', $submissions->count()]));
        $writer->addNewSheetAndMakeItCurrent()->setName('Submissions');
        $writer->addRow(Row::fromValues(['Submission', 'Form', 'Version', 'Status', 'Respondent', 'Attempt', 'Started', 'Submitted', 'Score', 'Percentage']));
        foreach ($submissions as $submission) $writer->addRow(Row::fromValues(array_map([$this, 'safe'], $this->submissionRow($submission))));
        $writer->addNewSheetAndMakeItCurrent()->setName('Answers');
        $writer->addRow(Row::fromValues(['Submission', 'Component key', 'Component', 'Type', 'Value']));
        foreach ($submissions as $submission) foreach ($submission->answers as $answer) $writer->addRow(Row::fromValues(array_map([$this, 'safe'], [$submission->public_id, $answer->component->stable_key, $answer->component->label, $answer->component->type, $answer->display_value])));
        $writer->addNewSheetAndMakeItCurrent()->setName('Component statistics');
        $writer->addRow(Row::fromValues(['Component', 'Answered count']));
        $stats=[]; foreach($submissions as $submission)foreach($submission->answers as $answer)$stats[$answer->component->label]=($stats[$answer->component->label]??0)+1;
        foreach($stats as $label=>$count)$writer->addRow(Row::fromValues([$this->safe($label),$count]));
        $writer->close();
    }

    private function submissionRow($s): array
    {
        return [$s->public_id, $s->publication->form->name, $s->formVersion->version_number, $s->status, $s->user?->email ?? 'anonymous', $s->attempt_number, $s->started_at?->toIso8601String(), $s->submitted_at?->toIso8601String(), $s->final_points, $s->percentage];
    }

    public function safe(mixed $value): mixed
    {
        if (!is_string($value)) return $value;
        return preg_match('/^[=+\-@\t\r]/u', $value) ? "'".$value : $value;
    }
}
