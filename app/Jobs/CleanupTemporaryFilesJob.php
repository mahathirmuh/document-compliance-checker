<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Files\TemporaryFileService;
use App\Services\Settings\SettingsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Sweeps abandoned working copies (CLAUDE.md 18).
 *
 * A worker that crashes mid-analysis leaves its temporary download behind;
 * without this the disk fills quietly over months.
 */
class CleanupTemporaryFilesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(TemporaryFileService $temporaryFiles, SettingsService $settings): void
    {
        $retentionHours = $settings->integer('temp_retention_hours');
        $removed = $temporaryFiles->purgeExpired($retentionHours);

        Log::info('Temporary file cleanup finished.', [
            'retention_hours' => $retentionHours,
            'files_removed' => $removed,
        ]);
    }
}
