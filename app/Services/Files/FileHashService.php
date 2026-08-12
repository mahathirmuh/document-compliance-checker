<?php

declare(strict_types=1);

namespace App\Services\Files;

/**
 * Content hashing for change detection.
 *
 * Hashing is always streamed. A controlled-document library routinely holds
 * multi-hundred-megabyte scanned PDFs, and reading one into memory to hash it
 * would take the queue worker down (CLAUDE.md 35.12).
 */
class FileHashService
{
    public function __construct(private readonly PathGuard $pathGuard) {}

    /**
     * Hash the contents of a file.
     *
     * @return string|null null when the file cannot be read, which the caller
     *                     must treat as "unknown", never as "changed"
     */
    public function hashFile(string $absolutePath): ?string
    {
        $algorithm = (string) config('documents.scan.hash_algorithm', 'sha256');
        $chunkSize = (int) config('documents.scan.hash_chunk_bytes', 1048576);

        $handle = @fopen($absolutePath, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            $context = hash_init($algorithm);

            while (! feof($handle)) {
                $chunk = fread($handle, $chunkSize);

                if ($chunk === false) {
                    return null;
                }

                hash_update($context, $chunk);
            }

            return hash_final($context);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Stable identifier for a file within its source.
     *
     * Derived from the path relative to the source root rather than the
     * absolute path, so remounting a share at a different drive letter does
     * not orphan every document underneath it.
     *
     * Lower-cased before hashing because Windows paths are case-insensitive:
     * without that, a rename that only changes case would look like a brand
     * new document.
     */
    public function sourceItemId(string $absolutePath, string $sourceRoot): string
    {
        $relative = $this->pathGuard->relativeTo($absolutePath, $sourceRoot);

        return hash('sha256', mb_strtolower($relative));
    }
}
