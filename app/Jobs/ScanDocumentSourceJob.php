<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\DocumentSource;
use App\Services\Scanning\SourceScanService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Walks one source looking for new and changed files.
 *
 * Scanning a large share is minutes of I/O, so it never runs in a web
 * request - pressing "Scan now" dispatches this and returns immediately
 * (CLAUDE.md 17, 35.11).
 */
class ScanDocumentSourceJob implements ShouldQueue
{
    use Queueable;

    /**
     * Deliberately one attempt.
     *
     * A scan is safely repeatable, but retrying an unreachable share three
     * times just delays the failure being visible. The next scheduled scan
     * is the retry.
     */
    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public readonly int $documentSourceId,
        public readonly ?int $triggeredBy = null,
    ) {}

    /**
     * A source must never be scanned by two workers at once: both would see
     * the same new file and race to create it.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping((string) $this->documentSourceId))
                ->dontRelease()
                ->expireAfter(3600),
        ];
    }

    public function handle(SourceScanService $scanService): void
    {
        $source = DocumentSource::find($this->documentSourceId);

        if ($source === null) {
            Log::info('Scan skipped: the document source no longer exists.', [
                'document_source_id' => $this->documentSourceId,
            ]);

            return;
        }

        if (! $source->isScannable()) {
            Log::info('Scan skipped: the source is disabled or its type cannot be scanned yet.', [
                'document_source_id' => $source->id,
                'type' => $source->type->value,
            ]);

            return;
        }

        // SourceScanService records its own ScanLog for both success and
        // failure, so there is nothing to catch here.
        $scanService->scan($source, $this->triggeredBy);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Scan job failed outright.', [
            'document_source_id' => $this->documentSourceId,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
