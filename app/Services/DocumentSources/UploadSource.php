<?php

declare(strict_types=1);

namespace App\Services\DocumentSources;

use App\Models\Document;
use App\Models\DocumentSource;
use App\Services\DocumentSources\Contracts\DocumentSourceInterface;
use App\Services\DocumentSources\DTO\SourceFile;
use DateTimeImmutable;
use Generator;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Adapter for documents pushed in through the web upload form.
 *
 * Unlike every other source this one is push-driven: the application is the
 * system of record for these bytes, so there is nothing to walk and
 * listFiles() yields nothing. Uploads reach the index through
 * DocumentUploadService instead.
 *
 * Files live on the `documents` disk under a generated name; the name the
 * user chose is kept only as metadata (CLAUDE.md 13).
 */
class UploadSource implements DocumentSourceInterface
{
    public function __construct(private readonly DocumentSource $source) {}

    /**
     * Always empty.
     *
     * A scheduled scan over the upload source would be meaningless - and
     * DocumentSourceType::UPLOAD::isScannable() already returns false, so the
     * scheduler never gets here.
     */
    public function listFiles(): Generator
    {
        yield from [];
    }

    public function getMetadata(string $itemId): ?SourceFile
    {
        $version = $this->currentVersionFor($itemId);

        if ($version === null || $version->stored_path === null) {
            return null;
        }

        $disk = $this->disk();

        if (! $disk->exists($version->stored_path)) {
            return null;
        }

        $document = $version->document;
        $lastModified = $disk->lastModified($version->stored_path);

        return new SourceFile(
            itemId: $itemId,
            path: $disk->path($version->stored_path),
            relativePath: $version->stored_path,
            fileName: $document->file_name,
            extension: $document->extension,
            size: (int) $disk->size($version->stored_path),
            lastModifiedAt: (new DateTimeImmutable)->setTimestamp($lastModified),
            mimeType: $document->mime_type,
            etag: null,
            parentPath: null,
        );
    }

    public function exists(string $itemId): bool
    {
        return $this->getMetadata($itemId) !== null;
    }

    public function openFile(string $itemId)
    {
        $version = $this->currentVersionFor($itemId);

        if ($version === null || $version->stored_path === null) {
            return null;
        }

        return $this->disk()->readStream($version->stored_path);
    }

    public function downloadTemporaryCopy(string $itemId): ?string
    {
        return $this->getMetadata($itemId)?->path;
    }

    /** No-op: the returned path is the stored document, not a copy. */
    public function releaseTemporaryCopy(string $path): void
    {
        // Nothing to clean up.
    }

    public function testConnection(): array
    {
        $disk = $this->disk();

        try {
            $disk->directories();
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'The upload storage disk is not writable.'];
        }

        return ['ok' => true, 'message' => 'Upload storage is available.'];
    }

    public function absolutePathFor(Document $document): ?string
    {
        $storedPath = $document->currentVersion?->stored_path;

        if ($storedPath === null) {
            return null;
        }

        return $this->disk()->exists($storedPath)
            ? $this->disk()->path($storedPath)
            : null;
    }

    /* ------------------------------------------------------------------ */
    /* Internals */
    /* ------------------------------------------------------------------ */

    private function currentVersionFor(string $itemId)
    {
        return Document::query()
            ->where('document_source_id', $this->source->id)
            ->where('source_item_id', $itemId)
            ->with(['currentVersion', 'currentVersion.document'])
            ->first()
            ?->currentVersion;
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('documents.upload.disk', 'documents'));
    }
}
