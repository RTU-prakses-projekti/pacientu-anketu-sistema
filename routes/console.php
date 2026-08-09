<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Domain\Forms\QuestionnairePackageService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('submissions:finalize-overdue')->everyMinute()->withoutOverlapping();

Artisan::command('questionnaires:list', function (QuestionnairePackageService $packages) {
    $rows = collect($packages->discover())->map(fn ($package) => [
        $package['package_name'], $package['name'], substr($package['content_hash'], 0, 12), $package['sections'], $package['components'], $package['has_assets'] ? 'yes' : 'no',
    ])->all();
    $this->table(['Package', 'Name', 'Hash', 'Sections', 'Components', 'Assets'], $rows);
})->purpose('List valid questionnaire packages tracked by Git');

Artisan::command('questionnaires:validate', function (QuestionnairePackageService $packages) {
    $items = $packages->discover(null, true); $failed = false;
    foreach ($items as $item) {
        if ($item['valid']) $this->info('VALID '.$item['package_name'].' '.$item['content_hash']);
        else { $failed = true; $this->error('INVALID '.$item['package_name'].' '.$item['error']); }
    }
    return $failed ? 1 : 0;
})->purpose('Validate all questionnaire packages without importing them');
