<?php

namespace App\Console\Commands;

use App\Models\WorkScheduleRevision;
use App\Services\WorkScheduleRevisionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ApplyScheduleRevisions extends Command
{
    protected $signature = 'schedules:apply-revisions';
    protected $description = 'Apply work schedule revisions whose effective date has arrived';

    public function handle(WorkScheduleRevisionService $service): int
    {
        WorkScheduleRevision::query()
            ->whereNull('applied_at')
            ->where('effective_from', '<=', today()->toDateString())
            ->orderBy('effective_from')
            ->with(['schedule', 'days'])
            ->each(fn ($revision) => DB::transaction(
                fn () => $service->applyToSchedule($revision->schedule, $revision)
            ));

        return self::SUCCESS;
    }
}