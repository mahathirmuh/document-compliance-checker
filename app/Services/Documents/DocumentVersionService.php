<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Services\DocumentSources\DTO\SourceFile;
use Illuminate\Support\Carbon;

/**
 * Creates and supersedes document versions.
 *
 * History is append-only: superseding a version clears its `is_current` flag
 * and nothing else. The analyses hanging off it keep pointing at the state of
 * the file as it was when they ran (CLAUDE.md 8.4, 35.17).
 */
class DocumentVersionService
{
    /**
     * Record a new current version and demote the previous one.
     *
     * Callers must already be inside a transaction - the demote and the
     * insert have to land together or the document ends up with two current
     * versions, or none.
     */
    public function createVersion(
        Document $document,
        SourceFile $file,
        ?string $fileHash,
        ?string $storedPath = null,
    ): DocumentVersion {
        $document->versions()
            ->where('is_current', true)
            ->update(['is_current' => false, 'updated_at' => now()]);

        return $document->versions()->create([
            'version_number' => $this->nextVersionNumber($document),
            'revision_label' => $document->current_revision,
            'file_hash' => $fileHash,
            'source_etag' => $file->etag,
            'file_size' => $file->size,
            'source_last_modified_at' => $file->lastModifiedAt === null
                ? null
                : Carbon::instance(\DateTime::createFromImmutable($file->lastModifiedAt)),
            'detected_at' => now(),
            'stored_path' => $storedPath,
            'is_current' => true,
        ]);
    }

    /**
     * The next version number for a document.
     *
     * Derived from the highest number ever issued rather than a count, so
     * numbers are never reused even if an old version row is removed.
     */
    public function nextVersionNumber(Document $document): int
    {
        return (int) $document->versions()->max('version_number') + 1;
    }

    /**
     * Whether the incoming file differs from the stored current version.
     *
     * The cheap fingerprint - eTag, or size plus modification time - is
     * checked first, and a content hash is only computed when that says
     * something moved. On a repeat scan of an untouched folder this means no
     * file is ever read (CLAUDE.md 9, 35.16).
     *
     * @param callable():?string $hashResolver computes the content hash lazily
     */
    public function hasChanged(?DocumentVersion $current, SourceFile $file, callable $hashResolver): bool
    {
        if ($current === null) {
            return true;
        }

        $fingerprintMatches = $file->matchesFingerprint(
            $current->source_etag,
            $current->file_size,
            $current->source_last_modified_at?->toDateTimeImmutable(),
        );

        if ($fingerprintMatches) {
            return false;
        }

        $incomingHash = $hashResolver();

        // An unreadable file gives no hash. Treating that as "changed" would
        // re-queue it on every scan forever, so it is reported as unchanged
        // and the scan log records the read error separately.
        if ($incomingHash === null || $current->file_hash === null) {
            return $incomingHash !== null;
        }

        return $incomingHash !== $current->file_hash;
    }
}
