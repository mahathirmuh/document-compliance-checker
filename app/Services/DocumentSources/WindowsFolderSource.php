<?php

declare(strict_types=1);

namespace App\Services\DocumentSources;

use App\Exceptions\UnsafePathException;
use App\Models\Document;
use App\Models\DocumentSource;
use App\Services\DocumentSources\Contracts\DocumentSourceInterface;
use App\Services\DocumentSources\DTO\SourceFile;
use App\Services\Files\FileHashService;
use App\Services\Files\PathGuard;
use DateTimeImmutable;
use FilesystemIterator;
use Generator;
use Illuminate\Support\Facades\Log;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Adapter for anything that looks like a directory to the server: a local
 * disk, a UNC share, or a mounted NAS export.
 *
 * All three are one implementation on purpose. Once a Windows share is
 * reachable - whether through a UNC path on Windows or a mount point on Linux
 * - reading it is identical, and keeping the branch out of here is what makes
 * the application portable across both (CLAUDE.md 10, 35.9).
 *
 * Read-only by contract: this adapter never writes to, moves, or deletes
 * anything under the source root.
 */
class WindowsFolderSource implements DocumentSourceInterface
{
    /**
     * Files that exist on disk but are not documents.
     *
     * "~$" is the Office lock file prefix - indexing those would create a
     * document record that vanishes the moment the author closes Word.
     *
     * @var array<int, string>
     */
    private const IGNORED_PREFIXES = ['~$', '.~', '._'];

    public function __construct(
        private readonly DocumentSource $source,
        private readonly PathGuard $pathGuard,
        private readonly FileHashService $hashService,
    ) {}

    public function listFiles(): Generator
    {
        $root = $this->root();
        $allowed = $this->allowedExtensions();
        $maxDepth = (int) config('documents.scan.max_depth', 12);
        $maxFiles = (int) config('documents.scan.max_files_per_scan', 20000);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $root,
                FilesystemIterator::SKIP_DOTS
                    | FilesystemIterator::FOLLOW_SYMLINKS
                    | FilesystemIterator::UNIX_PATHS,
            ),
            RecursiveIteratorIterator::LEAVES_ONLY,

            // An unreadable subfolder is normal on a shared drive. Without
            // this the whole scan would abort on the first one.
            RecursiveIteratorIterator::CATCH_GET_CHILD,
        );

        $iterator->setMaxDepth($maxDepth);

        $yielded = 0;

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            if ($yielded >= $maxFiles) {
                Log::warning('Scan hit the per-run file cap; remaining files were not indexed.', [
                    'document_source_id' => $this->source->id,
                    'max_files_per_scan' => $maxFiles,
                ]);
                break;
            }

            $sourceFile = $this->toSourceFile($file, $root, $allowed);

            if ($sourceFile !== null) {
                $yielded++;
                yield $sourceFile;
            }
        }
    }

    public function getMetadata(string $itemId): ?SourceFile
    {
        foreach ($this->listFiles() as $file) {
            if ($file->itemId === $itemId) {
                return $file;
            }
        }

        return null;
    }

    public function exists(string $itemId): bool
    {
        return $this->getMetadata($itemId) !== null;
    }

    public function openFile(string $itemId)
    {
        $file = $this->getMetadata($itemId);

        if ($file === null) {
            return null;
        }

        $handle = @fopen($file->path, 'rb');

        return $handle === false ? null : $handle;
    }

    /**
     * The file is already local, so hand back its own path.
     *
     * Copying it would double the I/O for no benefit, and the analyser only
     * ever reads.
     */
    public function downloadTemporaryCopy(string $itemId): ?string
    {
        return $this->getMetadata($itemId)?->path;
    }

    /**
     * Deliberately does nothing.
     *
     * downloadTemporaryCopy() returned a path inside the source itself, so
     * deleting it here would destroy a controlled document.
     */
    public function releaseTemporaryCopy(string $path): void
    {
        // No temporary copy was made.
    }

    public function testConnection(): array
    {
        try {
            $root = $this->root();
        } catch (UnsafePathException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $entries = @scandir($root);

        if ($entries === false) {
            return [
                'ok' => false,
                'message' => 'Folder is reachable but its contents could not be listed. Check that the account running the web server and queue worker has read access.',
            ];
        }

        return [
            'ok' => true,
            'message' => sprintf('Connected. %d entries visible in the root folder.', max(count($entries) - 2, 0)),
        ];
    }

    public function absolutePathFor(Document $document): ?string
    {
        try {
            return $this->pathGuard->assertWithin(
                $this->root().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $document->file_path),
                $this->root(),
            );
        } catch (UnsafePathException) {
            return null;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Internals */
    /* ------------------------------------------------------------------ */

    /**
     * Turn a directory entry into a SourceFile, or null if it is not one of
     * ours.
     *
     * @param  array<int, string>  $allowed
     */
    private function toSourceFile(SplFileInfo $file, string $root, array $allowed): ?SourceFile
    {
        $fileName = $file->getFilename();

        foreach (self::IGNORED_PREFIXES as $prefix) {
            if (str_starts_with($fileName, $prefix)) {
                return null;
            }
        }

        $extension = mb_strtolower($file->getExtension());

        if (! in_array($extension, $allowed, true)) {
            return null;
        }

        $absolute = $file->getPathname();

        try {
            // Guards against a symlink inside the root pointing back out of
            // it - FOLLOW_SYMLINKS above makes that reachable otherwise.
            $absolute = $this->pathGuard->assertWithin($absolute, $root);
        } catch (UnsafePathException) {
            Log::warning('Skipped a file that resolved outside its source root.', [
                'document_source_id' => $this->source->id,
            ]);

            return null;
        }

        if (! $file->isReadable()) {
            return null;
        }

        $relative = $this->pathGuard->relativeTo($absolute, $root);
        $parent = str_contains($relative, '/') ? dirname($relative) : '';

        $modifiedTimestamp = $file->getMTime();

        return new SourceFile(
            itemId: $this->hashService->sourceItemId($absolute, $root),
            path: $absolute,
            relativePath: $relative,
            fileName: $fileName,
            extension: $extension,
            size: (int) $file->getSize(),
            lastModifiedAt: $modifiedTimestamp === false
                ? null
                : (new DateTimeImmutable)->setTimestamp($modifiedTimestamp),
            mimeType: null,
            etag: null,
            parentPath: $parent === '.' ? '' : $parent,
        );
    }

    /** @throws UnsafePathException */
    private function root(): string
    {
        return $this->pathGuard->validateSourceRoot((string) $this->source->path);
    }

    /** @return array<int, string> */
    private function allowedExtensions(): array
    {
        return array_map(
            'mb_strtolower',
            (array) config('documents.extensions.scannable', []),
        );
    }
}
