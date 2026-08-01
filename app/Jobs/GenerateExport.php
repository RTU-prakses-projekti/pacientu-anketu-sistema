<?php

namespace App\Jobs;

use App\Domain\Exports\ExportService;
use App\Models\Export;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateExport implements ShouldQueue
{
    use Queueable;
    public function __construct(public int $exportId) { $this->afterCommit(); }
    public function handle(ExportService $service): void { $service->generate(Export::findOrFail($this->exportId)); }
}
