<?php

declare(strict_types=1);

namespace App\Services\Files;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Working copies of documents that are not local to the server.
 *
 * Only remote sources need this - a filesystem adapter hands the analyser the
 * real file. Everything written here is disposable, and the daily cleanup
 * sweeps whatever a crashed worker left behind (CLAUDE.md 18).
 *
 * The disk is scoped to its own directory, so nothing in this class can be
 * pointed at a real document: releasing a path outside the temporary disk is
 * refused rather than obeyed.
 */
class TemporaryFileService
{
    /** Reserve a path for a working copy. Nothing is written yet. */
    public function reservePath(string $extension): string
    {
        $name = Str::ulid()->toString().'.'.ltrim(mb_strtolower($extension), '.');

        $this->disk()->makeDirectory('working');

        return $this->disk()->path('working/'.$name);
    }

    /**
     * Delete a working copy.
     *
     * Refuses anything that does not resolve inside the temporary disk, so a
     * caller passing the wrong path cannot delete a stored upload or a
     * document in a scanned folder.
     */
    public function release(string $absolutePath): void
    {
        $root = rtrim($this->disk()->path(''), DIRECTORY_SEPARATOR);
        $resolved = @realpath($absolutePath);

        if ($resolved === false) {
            return;
        }

        if (! str_starts_with(mb_strtolower($resolved), mb_strtolower($root.DIRECTORY_SEPARATOR))) {
            Log::warning('Refused to release a path outside the temporary disk.');

            return;
        }

        @unlink($resolved);
    }

    /**
     * Delete working copies older than the retention window.
     *
     * @return int number of files removed
     */
    public function purgeExpired(int $retentionHours): int
    {
        $disk = $this->disk();
        $cutoff = now()->subHours(max($retentionHours, 1))->getTimestamp();
        $removed = 0;

        foreach ($disk->allFiles('working') as $path) {
            if ($disk->lastModified($path) < $cutoff) {
                $disk->delete($path);
                $removed++;
            }
        }

        return $removed;
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('documents.temporary.disk', 'temporary'));
    }
}
