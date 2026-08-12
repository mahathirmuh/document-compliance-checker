<?php

declare(strict_types=1);

namespace App\Services\DocumentSources;

use App\Exceptions\GraphException;
use App\Models\Document;
use App\Models\DocumentSource;
use App\Services\DocumentSources\Contracts\DocumentSourceInterface;
use App\Services\DocumentSources\DTO\SourceFile;
use App\Services\Files\TemporaryFileService;
use App\Services\MicrosoftGraph\DTO\DriveItem;
use App\Services\MicrosoftGraph\SharePointService;
use Generator;
use Illuminate\Support\Facades\Log;

/**
 * Adapter for SharePoint and OneDrive libraries reached through Microsoft
 * Graph (CLAUDE.md 11, 27 Phase 3).
 *
 * SharePoint stays the system of record. Nothing is ever written back, and
 * the only time a document exists on this server is the brief window while a
 * temporary copy is held for the analyzer - which the caller releases in a
 * `finally` block.
 *
 * Change detection here is entirely token-based: Graph reports a cTag that
 * moves when content changes, which is both cheaper and more reliable than
 * downloading a file to hash it (CLAUDE.md 9).
 */
class SharePointSource implements DocumentSourceInterface
{
    public function __construct(
        private readonly DocumentSource $source,
        private readonly SharePointService $sharePoint,
        private readonly TemporaryFileService $temporaryFiles,
    ) {}

    public function listFiles(): Generator
    {
        $allowed = array_map(
            'mb_strtolower',
            (array) config('documents.extensions.scannable', []),
        );

        foreach ($this->sharePoint->listFiles($this->source, $allowed) as $item) {
            yield $this->toSourceFile($item);
        }
    }

    public function getMetadata(string $itemId): ?SourceFile
    {
        $item = $this->sharePoint->getItem($this->source, $itemId);

        return $item === null ? null : $this->toSourceFile($item);
    }

    public function exists(string $itemId): bool
    {
        try {
            return $this->sharePoint->getItem($this->source, $itemId) !== null;
        } catch (GraphException $e) {
            // A transient Graph failure is not evidence that the document is
            // gone. Saying "no" here would let the scanner mark a whole
            // library missing during an outage.
            if ($e->isTransient()) {
                throw $e;
            }

            return false;
        }
    }

    /**
     * Open a read stream.
     *
     * Implemented by materialising a temporary copy first: Graph serves
     * content through a short-lived redirect, and holding an open HTTP stream
     * across a long parse is far less robust than a local file the caller
     * controls. The temporary file is unlinked once the handle is closed.
     */
    public function openFile(string $itemId)
    {
        $path = $this->downloadTemporaryCopy($itemId);

        if ($path === null) {
            return null;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            $this->releaseTemporaryCopy($path);

            return null;
        }

        return $handle;
    }

    public function downloadTemporaryCopy(string $itemId): ?string
    {
        $item = $this->sharePoint->getItem($this->source, $itemId);

        if ($item === null) {
            return null;
        }

        $destination = $this->temporaryFiles->reservePath($item->extension() ?: 'bin');

        try {
            $this->sharePoint->downloadItem($this->source, $itemId, $destination);
        } catch (GraphException $e) {
            // A partial download must not be left behind for the analyzer to
            // read as a corrupt document.
            $this->temporaryFiles->release($destination);

            throw $e;
        }

        return $destination;
    }

    /**
     * Delete the working copy.
     *
     * TemporaryFileService refuses any path outside the temporary disk, so a
     * mistaken call here cannot reach a stored upload or a scanned folder.
     */
    public function releaseTemporaryCopy(string $path): void
    {
        $this->temporaryFiles->release($path);
    }

    public function testConnection(): array
    {
        return $this->sharePoint->testConnection($this->source);
    }

    /**
     * Always null: the document lives in SharePoint, not on this server.
     *
     * Callers that need the bytes ask for a temporary copy and release it
     * afterwards.
     */
    public function absolutePathFor(Document $document): ?string
    {
        return null;
    }

    /* ------------------------------------------------------------------ */
    /* Internals */
    /* ------------------------------------------------------------------ */

    private function toSourceFile(DriveItem $item): SourceFile
    {
        if ($item->changeToken === null) {
            // Without a token this item falls back to size comparison, which
            // is weak. Worth knowing about if it ever happens at scale.
            Log::debug('SharePoint item has no change token.', [
                'document_source_id' => $this->source->id,
                'item_id' => $item->id,
            ]);
        }

        return new SourceFile(
            // Graph's own driveItem id: stable across renames and moves,
            // which is precisely what the document identity needs.
            itemId: $item->id,

            // No local path exists; the web URL is what an operator would
            // actually want to follow, and isRemote stops anything treating
            // it as a filesystem path.
            path: $item->webUrl ?? $item->relativePath,

            relativePath: $item->relativePath,
            fileName: $item->name,
            extension: $item->extension(),
            size: $item->size,
            lastModifiedAt: $item->lastModifiedAt,
            mimeType: $item->mimeType,
            etag: $item->changeToken,
            parentPath: $item->parentPath,
            isRemote: true,
        );
    }
}
