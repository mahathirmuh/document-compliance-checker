<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Exceptions\AnalyzerUnavailableException;
use App\Models\Document;
use App\Services\Analyzer\AnalyzerClient;
use App\Services\Analyzer\DTO\DocumentExtraction;
use App\Services\DocumentSources\DocumentSourceFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches a document's text for side-by-side review.
 *
 * Re-reads the source file on demand rather than storing its text. The
 * application otherwise holds metadata and measurements only, and keeping it
 * that way means there is no second copy of a controlled document to secure,
 * back up, or destroy when the original is withdrawn - SharePoint and the
 * file share stay the only sources of truth (CLAUDE.md 8.3, 12).
 *
 * The cache is what makes re-reading affordable. It is keyed on the version's
 * file hash, so a document that changes invalidates its own entry instead of
 * showing a reviewer text that no longer matches the file.
 */
class DocumentComparisonService
{
    public function __construct(
        private readonly AnalyzerClient $analyzer,
        private readonly DocumentSourceFactory $sourceFactory,
    ) {}

    /**
     * The document's text, grouped by section and language.
     *
     * Returns null when the text cannot be produced - the analyser is off or
     * unreachable, the source file has gone, the format has no parser. The
     * caller shows a reason; nothing here throws, because failing to render a
     * review aid must never take down the document page.
     */
    public function extract(Document $document): ?DocumentExtraction
    {
        if (! $this->analyzer->isEnabled()) {
            return null;
        }

        $version = $document->currentVersion;

        if ($version === null) {
            return null;
        }

        $minutes = (int) config('documents.comparison.cache_minutes', 15);

        $cached = Cache::remember(
            $this->cacheKey($document, $version->file_hash),
            now()->addMinutes(max($minutes, 1)),
            fn () => $this->fetch($document, $version->id),
        );

        return $cached instanceof DocumentExtraction ? $cached : null;
    }

    /** Drop any cached text for this document. */
    public function forget(Document $document): void
    {
        $version = $document->currentVersion;

        if ($version !== null) {
            Cache::forget($this->cacheKey($document, $version->file_hash));
        }
    }

    /* ------------------------------------------------------------------ */

    private function fetch(Document $document, int $versionId): ?DocumentExtraction
    {
        $adapter = $this->sourceFactory->make($document->source);
        $workingCopy = null;

        try {
            $workingCopy = $adapter->downloadTemporaryCopy($document->source_item_id);

            if ($workingCopy === null) {
                return null;
            }

            return $this->analyzer->extract(
                $workingCopy,
                $document->id,
                $versionId,
                (int) config('documents.comparison.max_characters', 400000),
            );
        } catch (AnalyzerUnavailableException $e) {
            Log::warning('Document comparison could not reach the analyzer.', [
                'document_id' => $document->id,
                'reason' => $e->getMessage(),
            ]);

            return null;
        } catch (Throwable $e) {
            // Logged in full, reported as nothing: a stack trace here can
            // carry a UNC path (CLAUDE.md 30).
            Log::error('Document comparison failed.', [
                'document_id' => $document->id,
                'exception' => $e->getMessage(),
            ]);

            return null;
        } finally {
            if ($workingCopy !== null) {
                $adapter->releaseTemporaryCopy($workingCopy);
            }
        }
    }

    private function cacheKey(Document $document, ?string $fileHash): string
    {
        // The hash rather than the version id: a re-scanned file that changed
        // without a new version row must not serve the previous text.
        return sprintf('doccheck.compare.%d.%s', $document->id, $fileHash ?? 'unknown');
    }
}
