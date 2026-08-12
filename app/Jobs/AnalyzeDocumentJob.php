<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\AnalyzerUnavailableException;
use App\Models\DocumentVersion;
use App\Services\Analyzer\AnalyzerClient;
use App\Services\Documents\DocumentAnalysisService;
use App\Services\DocumentSources\DocumentSourceFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Analyses one document version.
 *
 * Phase 1 ships with the analyser disabled, so this job's normal outcome
 * today is to log that fact and leave the version PENDING. That is the
 * "queue analysis placeholder" from CLAUDE.md 36: the queue, the job, the
 * retry policy and the failure handling are all real and exercised, and
 * turning ANALYZER_ENABLED on is the only change Phase 2 needs.
 *
 * The job takes an id rather than the model so a version deleted between
 * dispatch and execution is handled here, not by a serialisation failure.
 */
class AnalyzeDocumentJob implements ShouldQueue
{
    use Queueable;

    /**
     * A transient analyser outage should be retried; a broken document
     * should not be retried forever.
     */
    public int $tries = 3;

    public int $timeout = 600;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $documentVersionId,
        public readonly ?int $requestedBy = null,
    ) {}

    /**
     * Stops the same version being analysed twice concurrently, which would
     * race two analyses into the document's status mirror.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->documentVersionId))->expireAfter(900)];
    }

    public function handle(
        AnalyzerClient $analyzer,
        DocumentAnalysisService $analysisService,
        DocumentSourceFactory $sourceFactory,
    ): void {
        $version = DocumentVersion::with(['document.source'])->find($this->documentVersionId);

        if ($version === null) {
            Log::info('Analysis skipped: the document version no longer exists.', [
                'document_version_id' => $this->documentVersionId,
            ]);

            return;
        }

        if (! $analyzer->isEnabled()) {
            Log::info('Analysis skipped: the document analyzer is not enabled yet.', [
                'document_version_id' => $version->id,
                'document_id' => $version->document_id,
            ]);

            return;
        }

        $analysis = $analysisService->start($version, $this->requestedBy);
        $adapter = $sourceFactory->make($version->document->source);
        $workingCopy = null;

        try {
            $workingCopy = $adapter->downloadTemporaryCopy($version->document->source_item_id);

            if ($workingCopy === null) {
                $analysisService->fail($analysis, 'The document could not be read from its source.');

                return;
            }

            $result = $analyzer->analyze($workingCopy, $version->document_id, $version->id);

            $analysisService->complete($analysis, $result);
        } catch (AnalyzerUnavailableException $e) {
            // An outage says nothing about the document, so no verdict is
            // recorded against it - the version simply goes back to PENDING
            // and the job retries.
            $analysisService->releaseWithoutVerdict($analysis, $e->getMessage());

            throw $e;
        } catch (Throwable $e) {
            Log::error('Document analysis failed.', [
                'document_version_id' => $version->id,
                'document_id' => $version->document_id,
                'exception' => $e->getMessage(),
            ]);

            // The stored message is written here, not taken from the
            // exception, so nothing from a stack trace reaches the UI.
            $analysisService->fail($analysis, 'The document could not be analysed. See the application log for details.');
        } finally {
            if ($workingCopy !== null) {
                $adapter->releaseTemporaryCopy($workingCopy);
            }
        }
    }

    /** Last-resort handler once every retry is exhausted. */
    public function failed(?Throwable $exception): void
    {
        Log::error('Analysis job exhausted its retries.', [
            'document_version_id' => $this->documentVersionId,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
