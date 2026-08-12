<?php

declare(strict_types=1);

namespace App\Services\Scanning;

use App\Enums\IndexOutcome;
use App\Enums\ScanStatus;
use App\Exceptions\UnsafePathException;
use App\Models\DocumentSource;
use App\Models\ScanLog;
use App\Services\DocumentSources\DocumentSourceFactory;
use App\Services\Documents\DocumentService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one scan of one source and records what happened.
 *
 * Two things make this safe to run unattended:
 *
 *  - A failure indexing one file never aborts the run. A single locked or
 *    corrupt document in a folder of thousands must not stop the other 999
 *    from being checked; the failure is counted and logged instead.
 *
 *  - Every run writes a ScanLog whatever the outcome, so "the scan silently
 *    did nothing" is not a state the system can be in (CLAUDE.md 30, 35.18).
 */
class SourceScanService
{
    public function __construct(
        private readonly DocumentSourceFactory $sourceFactory,
        private readonly DocumentService $documentService,
    ) {}

    public function scan(DocumentSource $source, ?int $triggeredBy = null): ScanLog
    {
        $log = $source->scanLogs()->create([
            'started_at' => now(),
            'status' => ScanStatus::RUNNING,
            'triggered_by' => $triggeredBy,
        ]);

        $startedAt = microtime(true);

        Log::info('Source scan started.', [
            'document_source_id' => $source->id,
            'scan_log_id' => $log->id,
        ]);

        try {
            $counters = $this->walk($source, $log);
        } catch (UnsafePathException $e) {
            return $this->finishFailed($log, $source, $startedAt, $e->getMessage(), $e);
        } catch (Throwable $e) {
            return $this->finishFailed(
                $log,
                $source,
                $startedAt,
                'The source could not be scanned. Check that it is reachable and readable by the application account.',
                $e,
            );
        }

        $status = $counters['error_count'] > 0
            ? ScanStatus::COMPLETED_WITH_ERRORS
            : ScanStatus::COMPLETED;

        $log->update([
            ...$counters,
            'status' => $status,
            'completed_at' => now(),
            'duration_ms' => $this->elapsedMs($startedAt),
            'message' => $this->summarise($counters),
        ]);

        $source->forceFill([
            'last_scan_at' => now(),
            'last_successful_scan_at' => now(),
        ])->save();

        Log::info('Source scan completed.', [
            'document_source_id' => $source->id,
            'scan_log_id' => $log->id,
            'status' => $status->value,
            ...$counters,
        ]);

        return $log->refresh();
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{total_found: int, new_files: int, modified_files: int, unchanged_files: int, deleted_files: int, skipped_files: int, queued_for_analysis: int, error_count: int}
     */
    private function walk(DocumentSource $source, ScanLog $log): array
    {
        $adapter = $this->sourceFactory->make($source);

        $counters = [
            'total_found' => 0,
            'new_files' => 0,
            'modified_files' => 0,
            'unchanged_files' => 0,
            'deleted_files' => 0,
            'skipped_files' => 0,
            'queued_for_analysis' => 0,
            'error_count' => 0,
        ];

        $seenItemIds = [];

        foreach ($adapter->listFiles() as $file) {
            $counters['total_found']++;
            $seenItemIds[] = $file->itemId;

            try {
                $outcome = $this->documentService->indexFile($source, $file);
            } catch (Throwable $e) {
                $counters['error_count']++;

                // Logged with the item id rather than the path: the id is
                // enough to find the row again and does not put the internal
                // folder layout into the log file.
                Log::error('Failed to index a discovered file.', [
                    'document_source_id' => $source->id,
                    'scan_log_id' => $log->id,
                    'source_item_id' => $file->itemId,
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }

            match ($outcome) {
                IndexOutcome::NEW => $counters['new_files']++,
                IndexOutcome::MODIFIED => $counters['modified_files']++,
                IndexOutcome::UNCHANGED => $counters['unchanged_files']++,
                IndexOutcome::SKIPPED => $counters['skipped_files']++,
                IndexOutcome::FAILED => $counters['error_count']++,
            };

            if ($outcome->shouldQueueAnalysis()) {
                $counters['queued_for_analysis']++;
            }
        }

        // Only trust an empty result to mean "everything is gone" when the
        // walk itself succeeded; a source that threw never reaches here.
        $counters['deleted_files'] = $this->documentService->markMissing($source, $seenItemIds);

        return $counters;
    }

    private function finishFailed(
        ScanLog $log,
        DocumentSource $source,
        float $startedAt,
        string $safeMessage,
        Throwable $exception,
    ): ScanLog {
        Log::error('Source scan failed.', [
            'document_source_id' => $source->id,
            'scan_log_id' => $log->id,
            'exception' => $exception->getMessage(),
        ]);

        $log->update([
            'status' => ScanStatus::FAILED,
            'completed_at' => now(),
            'duration_ms' => $this->elapsedMs($startedAt),
            'error_count' => 1,
            'message' => $safeMessage,
        ]);

        // last_scan_at moves even on failure so a permanently broken source
        // does not get retried on every single scheduler tick.
        $source->forceFill(['last_scan_at' => now()])->save();

        return $log->refresh();
    }

    /** @param array<string, int> $counters */
    private function summarise(array $counters): string
    {
        return sprintf(
            '%d found · %d new · %d modified · %d unchanged · %d missing · %d queued · %d errors',
            $counters['total_found'],
            $counters['new_files'],
            $counters['modified_files'],
            $counters['unchanged_files'],
            $counters['deleted_files'],
            $counters['queued_for_analysis'],
            $counters['error_count'],
        );
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
