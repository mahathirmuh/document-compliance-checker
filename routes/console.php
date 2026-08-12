<?php

declare(strict_types=1);

use App\Console\Commands\ScanDueSourcesCommand;
use App\Jobs\CleanupTemporaryFilesJob;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| The scheduler only ever dispatches - it never scans or analyses inline
| (CLAUDE.md 17, 18). Every entry is withoutOverlapping() so a slow run does
| not stack a second copy on top of itself.
|
| Requires one cron entry / Windows scheduled task:
|     php artisan schedule:run   (every minute)
|
*/

// Each source carries its own interval; this ticks often enough that a
// source configured for 15 minutes is not held up waiting for the sweep.
Schedule::command(ScanDueSourcesCommand::class)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::job(new CleanupTemporaryFilesJob)
    ->dailyAt('01:30')
    ->withoutOverlapping();

// Surfaces analyses that have been stuck in the queue, so a wedged worker is
// noticed by an operator rather than by a Document Controller wondering why
// nothing has been graded.
Schedule::command('documents:report-stalled')
    ->dailyAt('07:00')
    ->withoutOverlapping();
