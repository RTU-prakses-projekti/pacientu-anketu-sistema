<?php

namespace App\Console\Commands;

use App\Domain\Submissions\SubmissionService;
use Illuminate\Console\Command;

class FinalizeOverdueSubmissions extends Command
{
    protected $signature = 'submissions:finalize-overdue';
    protected $description = 'Atomically expire submissions whose server deadline has passed';
    public function handle(SubmissionService $service): int
    {
        $this->info($service->expireOverdue().' overdue submissions finalized.');
        return self::SUCCESS;
    }
}
