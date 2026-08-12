<?php

declare(strict_types=1);

namespace App\Services\DocumentSources\DTO;

use DateTimeImmutable;

/**
 * One file as reported by a source adapter.
 *
 * This is the whole contract between "where documents come from" and "what
 * the application does with them". A SharePoint adapter fills `etag` and
 * `itemId` from Graph; a filesystem adapter fills `itemId` with a hash of the
 * relative path and leaves `etag` null. Nothing downstream needs to know
 * which kind it is dealing with (CLAUDE.md 4).
 */
final readonly class SourceFile
{
    public function __construct(
        /** Stable identifier for this file within its source. */
        public string $itemId,

        /** Absolute path, or the Graph path for remote sources. */
        public string $path,

        /** Path relative to the source root, forward-slashed, for display. */
        public string $relativePath,

        public string $fileName,
        public string $extension,
        public int $size,
        public ?DateTimeImmutable $lastModifiedAt = null,
        public ?string $mimeType = null,

        /** Source-provided change token. Null for plain filesystems. */
        public ?string $etag = null,

        /** Parent folder relative to the source root, forward-slashed. */
        public ?string $parentPath = null,
    ) {}

    /**
     * Whether this file looks unchanged against a stored fingerprint.
     *
     * An eTag comparison is preferred when the source provides one: it is
     * authoritative and needs no file read. Otherwise size plus modification
     * time is the cheap pre-check, and the caller only pays for a hash when
     * this returns false (CLAUDE.md 9).
     */
    public function matchesFingerprint(?string $etag, ?int $size, ?DateTimeImmutable $lastModifiedAt): bool
    {
        if ($this->etag !== null && $etag !== null) {
            return $this->etag === $etag;
        }

        if ($size === null || $this->size !== $size) {
            return false;
        }

        if ($this->lastModifiedAt === null || $lastModifiedAt === null) {
            return false;
        }

        // Second precision: filesystems and databases disagree below that.
        return $this->lastModifiedAt->getTimestamp() === $lastModifiedAt->getTimestamp();
    }
}
