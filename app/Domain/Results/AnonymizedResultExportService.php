<?php

namespace App\Domain\Results;

use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

class AnonymizedResultExportService
{
    public function download(Collection $handoffs, string $format)
    {
        $headers = ['Research ID', 'Form', 'Submitted', 'Handed off', 'Question', 'Answer'];
        $rows = $this->rows($handoffs);
        $filename = 'anonymized-results-'.now()->format('Ymd-His');

        return $format === 'xlsx'
            ? $this->downloadXlsx($headers, $rows, $filename.'.xlsx')
            : $this->downloadCsv($headers, $rows, $filename.'.csv');
    }

    private function rows(Collection $handoffs): array
    {
        $rows = [];
        foreach ($handoffs as $handoff) {
            $patientCode = $handoff->assignment?->patientCase?->patient_code;
            $formName = $handoff->submission?->publication?->form?->name;
            if (!$patientCode || !$formName || !$handoff->submission) continue;

            foreach ($handoff->submission->answers as $answer) {
                $component = $answer->component;
                if (!$component || $component->is_sensitive) continue;
                $rows[] = [
                    $patientCode,
                    $formName,
                    $handoff->submission->submitted_at?->toIso8601String(),
                    $handoff->handed_off_at?->toIso8601String(),
                    $component->localizedLabel(),
                    $component->localizedAnswerValue($answer->value),
                ];
            }
        }

        return $rows;
    }

    private function downloadCsv(array $headers, array $rows, string $filename)
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, $headers);
            foreach ($rows as $row) fputcsv($handle, array_map([$this, 'safe'], $row));
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function downloadXlsx(array $headers, array $rows, string $filename)
    {
        $path = tempnam(sys_get_temp_dir(), 'anonymized-results-').'.xlsx';
        $writer = new Writer();
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Anonymized results');
        $writer->addRow(Row::fromValuesWithStyle($headers, (new \OpenSpout\Common\Entity\Style\Style())->withFontBold(true)));
        foreach ($rows as $row) $writer->addRow(Row::fromValues(array_map([$this, 'safe'], $row)));
        $writer->close();

        return response()->download($path, $filename)->deleteFileAfterSend(true);
    }

    private function safe(mixed $value): mixed
    {
        if (!is_string($value)) return $value;
        return preg_match('/^[=+\-@\t\r]/u', $value) ? "'".$value : $value;
    }
}
