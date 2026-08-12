<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Enums\AnalysisStatus;
use App\Enums\DocumentType;
use App\Enums\IndexOutcome;
use App\Models\Document;
use App\Models\DocumentSource;
use App\Services\DocumentSources\DTO\SourceFile;
use App\Services\Files\FileHashService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns a file reported by a source adapter into an indexed document.
 *
 * This is where the change-detection rules from CLAUDE.md 9 live:
 *
 *   new file                        -> document + version, queue analysis
 *   existing, fingerprint matches   -> skip, touch nothing
 *   existing, content changed       -> new version, queue analysis
 *
 * Nothing here knows or cares which kind of source the file came from.
 */
class DocumentService
{
    public function __construct(
        private readonly DocumentVersionService $versionService,
        private readonly FileHashService $hashService,
        private readonly DocumentAnalysisService $analysisService,
    ) {}

    /**
     * Index one discovered file.
     *
     * The whole upsert runs in a transaction: a document, its version and the
     * demotion of the previous version are one fact, and a half-written one
     * would leave a document with no current version (CLAUDE.md 25).
     */
    public function indexFile(DocumentSource $source, SourceFile $file): IndexOutcome
    {
        $document = Document::query()
            ->withTrashed()
            ->where('document_source_id', $source->id)
            ->where('source_item_id', $file->itemId)
            ->with('currentVersion')
            ->first();

        if ($document === null) {
            $this->createDocument($source, $file);

            return IndexOutcome::NEW;
        }

        // A file reappearing after it was removed is the same document coming
        // back, not a new one - restoring keeps its version history attached.
        if ($document->trashed()) {
            $document->restore();
        }

        $hash = null;
        $hashResolver = function () use ($file, &$hash): ?string {
            $hash ??= $this->hashService->hashFile($file->path);

            return $hash;
        };

        if (! $this->versionService->hasChanged($document->currentVersion, $file, $hashResolver)) {
            $this->markSeen($document);

            return IndexOutcome::UNCHANGED;
        }

        $this->recordModification($document, $file, $hashResolver());

        return IndexOutcome::MODIFIED;
    }

    /**
     * Flag documents that were not seen during a scan.
     *
     * They are deactivated rather than deleted: a file missing from one scan
     * is usually a permissions blip or a folder being reorganised, and the
     * compliance history must survive either (CLAUDE.md 35.20).
     *
     * @param  array<int, string>  $seenItemIds
     * @return int number of documents newly marked missing
     */
    public function markMissing(DocumentSource $source, array $seenItemIds): int
    {
        $query = Document::query()
            ->where('document_source_id', $source->id)
            ->where('is_active', true);

        if ($seenItemIds !== []) {
            $query->whereNotIn('source_item_id', $seenItemIds);
        }

        return $query->update([
            'is_active' => false,
            'missing_since' => now(),
            'updated_at' => now(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Internals */
    /* ------------------------------------------------------------------ */

    private function createDocument(DocumentSource $source, SourceFile $file): Document
    {
        return DB::transaction(function () use ($source, $file): Document {
            $document = Document::create([
                'document_source_id' => $source->id,
                'source_type' => $source->type,
                'source_item_id' => $file->itemId,
                'parent_path' => $file->parentPath,
                'file_path' => $file->relativePath,
                'file_name' => $file->fileName,
                'original_file_name' => $file->fileName,
                'extension' => $file->extension,
                'mime_type' => $file->mimeType,
                'file_size' => $file->size,
                'document_type' => DocumentType::guessFromFileName($file->fileName),
                'document_title' => $this->titleFromFileName($file->fileName),
                'file_hash' => $this->hashService->hashFile($file->path),
                'source_etag' => $file->etag,
                'source_last_modified_at' => $this->toCarbon($file),
                'analysis_status' => AnalysisStatus::PENDING,
                'is_active' => true,
            ]);

            $version = $this->versionService->createVersion($document, $file, $document->file_hash);

            $this->analysisService->queue($document, $version);

            return $document;
        });
    }

    private function recordModification(Document $document, SourceFile $file, ?string $hash): void
    {
        DB::transaction(function () use ($document, $file, $hash): void {
            $document->update([
                'file_name' => $file->fileName,
                'file_path' => $file->relativePath,
                'parent_path' => $file->parentPath,
                'file_size' => $file->size,
                'file_hash' => $hash,
                'source_etag' => $file->etag,
                'source_last_modified_at' => $this->toCarbon($file),
                'analysis_status' => AnalysisStatus::PENDING,
                'is_active' => true,
                'missing_since' => null,
            ]);

            $version = $this->versionService->createVersion($document, $file, $hash);

            $this->analysisService->queue($document, $version);
        });
    }

    /** Cheapest possible "still there" update - no version, no queueing. */
    private function markSeen(Document $document): void
    {
        if ($document->is_active) {
            return;
        }

        $document->update(['is_active' => true, 'missing_since' => null]);
    }

    /**
     * A readable working title from the file name.
     *
     * Only ever used to pre-fill a brand new record. A Document Controller who
     * corrects the title keeps it: recordModification() does not touch the
     * field, so a later scan cannot overwrite their work.
     */
    private function titleFromFileName(string $fileName): string
    {
        $stem = pathinfo($fileName, PATHINFO_FILENAME);
        $stem = str_replace(['_', '-'], ' ', $stem);
        $stem = preg_replace('/\s+/u', ' ', $stem) ?? $stem;

        return trim($stem) ?: $fileName;
    }

    private function toCarbon(SourceFile $file): ?Carbon
    {
        return $file->lastModifiedAt === null
            ? null
            : Carbon::createFromTimestamp($file->lastModifiedAt->getTimestamp());
    }
}
