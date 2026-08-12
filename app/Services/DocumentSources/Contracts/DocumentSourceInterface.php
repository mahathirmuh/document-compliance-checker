<?php

declare(strict_types=1);

namespace App\Services\DocumentSources\Contracts;

use App\Models\Document;
use App\Services\DocumentSources\DTO\SourceFile;
use Generator;

/**
 * What every document source must be able to do.
 *
 * Adding a source type means writing one implementation of this interface and
 * registering it with DocumentSourceFactory. No scanning, indexing or analysis
 * code may branch on the source type (CLAUDE.md 4, 35.10).
 */
interface DocumentSourceInterface
{
    /**
     * Walk the source and yield every supported file.
     *
     * Returns a Generator rather than an array so a folder with tens of
     * thousands of files streams through the scanner instead of being
     * materialised in memory first.
     *
     * @return Generator<int, SourceFile>
     */
    public function listFiles(): Generator;

    /** Metadata for one file, or null if it is gone. */
    public function getMetadata(string $itemId): ?SourceFile;

    public function exists(string $itemId): bool;

    /**
     * Open a read-only stream over the file.
     *
     * @return resource|null
     */
    public function openFile(string $itemId);

    /**
     * Materialise a local copy the analyser can read.
     *
     * For a filesystem source this is the file itself and nothing is copied;
     * for SharePoint it is a temporary download the caller must release.
     *
     * @return string|null absolute path to a readable file
     */
    public function downloadTemporaryCopy(string $itemId): ?string;

    /**
     * Release whatever downloadTemporaryCopy() created.
     *
     * Implementations that returned a path into the source itself must make
     * this a no-op - it must never delete a source document.
     */
    public function releaseTemporaryCopy(string $path): void;

    /**
     * Check the source is reachable and readable.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array;

    /** Absolute location a Document row should record for this file. */
    public function absolutePathFor(Document $document): ?string;
}
