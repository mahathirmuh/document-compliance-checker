<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ScanDocumentSourceJob;
use App\Models\DocumentSource;
use Illuminate\Console\Command;

/**
 * Queues a scan for every source whose interval has elapsed.
 *
 * Runs every five minutes from the scheduler but respects each source's own
 * `scan_interval_minutes`, so the sweep frequency and the scan frequency stay
 * independent (CLAUDE.md 18).
 */
class ScanDueSourcesCommand extends Command
{
    protected $signature = 'documents:scan-due
                            {--source=* : Scan only these source ids, ignoring their interval}';

    protected $description = 'Queue a scan for every enabled document source that is due';

    public function handle(): int
    {
        $explicitIds = array_filter((array) $this->option('source'));

        $sources = $explicitIds !== []
            ? DocumentSource::query()->whereIn('id', $explicitIds)->get()
            : DocumentSource::query()->dueForScan()->get();

        if ($sources->isEmpty()) {
            $this->info('No sources are due for a scan.');

            return self::SUCCESS;
        }

        foreach ($sources as $source) {
            if (! $source->isScannable()) {
                $this->warn("Skipped [{$source->name}]: disabled, or its type cannot be scanned yet.");

                continue;
            }

            ScanDocumentSourceJob::dispatch($source->id);
            $this->line("Queued scan for [{$source->name}].");
        }

        return self::SUCCESS;
    }
}
