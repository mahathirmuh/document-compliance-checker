<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\Exceptions\UnsafePathException;

/**
 * The only place the application is allowed to turn an operator-supplied
 * string into a filesystem path.
 *
 * Two rules drive everything here (CLAUDE.md 12):
 *
 *  1. No arbitrary filesystem access. A path is only ever usable relative to
 *     a registered source root, and containment is verified against the
 *     *resolved* path, so a symlink or junction pointing out of the root is
 *     caught as well as a literal "..".
 *
 *  2. No user-supplied filename ever reaches the disk unchanged.
 */
class PathGuard
{
    /**
     * Roots that must never be registered as a document source.
     *
     * Read access to these does not directly compromise the host, but a scan
     * pointed at one would index credentials, profiles and system files into
     * a searchable list - so they are refused outright rather than left to
     * operator discipline.
     *
     * @var array<int, string>
     */
    private const DENIED_ROOT_PREFIXES = [
        'C:\\Windows',
        'C:\\Program Files',
        'C:\\Program Files (x86)',
        'C:\\ProgramData\\Microsoft',
        'C:\\Users\\Default',
        '/etc',
        '/proc',
        '/sys',
        '/dev',
        '/root',
        '/boot',
        '/var/lib',
    ];

    /**
     * Normalise separators and strip trailing slashes.
     *
     * The UNC prefix is preserved: "\\\\server\\share" must keep both leading
     * separators or it stops addressing the share.
     */
    public function normalize(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            throw new UnsafePathException('Path is empty.');
        }

        if (str_contains($path, "\0")) {
            throw new UnsafePathException('Path contains a null byte.');
        }

        $isUnc = str_starts_with($path, '\\\\') || str_starts_with($path, '//');

        $path = str_replace('/', DIRECTORY_SEPARATOR, $path);
        $path = str_replace('\\', DIRECTORY_SEPARATOR, $path);

        // Collapse runs of separators, then restore the UNC double prefix.
        $path = preg_replace('#'.preg_quote(DIRECTORY_SEPARATOR, '#').'{2,}#', DIRECTORY_SEPARATOR, $path) ?? $path;

        if ($isUnc) {
            $path = DIRECTORY_SEPARATOR.$path;
        }

        $trimmed = rtrim($path, DIRECTORY_SEPARATOR);

        // Do not trim a bare root ("/" or "C:\") away to nothing.
        return $trimmed === '' || preg_match('/^[A-Za-z]:$/', $trimmed) === 1
            ? $path
            : $trimmed;
    }

    /**
     * Validate a path an administrator wants to register as a source root.
     *
     * @return string the normalised, resolved root
     *
     * @throws UnsafePathException
     */
    public function validateSourceRoot(string $path): string
    {
        $normalized = $this->normalize($path);

        if (str_contains($normalized, '..')) {
            throw new UnsafePathException('Path may not contain relative segments.');
        }

        if (! $this->isAbsolute($normalized)) {
            throw new UnsafePathException('Path must be absolute, for example D:\\DocumentControl\\SOP or \\\\fileserver\\DocumentControl.');
        }

        $this->assertNotDeniedRoot($normalized);

        if (! is_dir($normalized)) {
            throw new UnsafePathException('Folder does not exist or is not reachable from the application server.');
        }

        if (! is_readable($normalized)) {
            throw new UnsafePathException('Folder exists but is not readable by the account running the application.');
        }

        // realpath() fails on some network paths even when the directory is
        // readable, so a failure here is not fatal - it just means later
        // containment checks compare normalised rather than resolved paths.
        return $this->resolve($normalized) ?? $normalized;
    }

    /**
     * Assert that $candidate sits inside $root.
     *
     * Both sides are resolved first so "..", symlinks and NTFS junctions are
     * all flattened before the comparison.
     *
     * @throws UnsafePathException
     */
    public function assertWithin(string $candidate, string $root): string
    {
        $resolvedRoot = $this->resolve($this->normalize($root)) ?? $this->normalize($root);
        $resolvedCandidate = $this->resolve($this->normalize($candidate)) ?? $this->normalize($candidate);

        $rootWithSeparator = rtrim($resolvedRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (! $this->startsWith($resolvedCandidate.DIRECTORY_SEPARATOR, $rootWithSeparator)) {
            throw new UnsafePathException('Resolved path escapes its document source root.');
        }

        return $resolvedCandidate;
    }

    /**
     * Path of $absolute relative to $root, using forward slashes.
     *
     * Stored and displayed rather than the absolute path, so the UI does not
     * hand out the internal folder layout to every viewer.
     */
    public function relativeTo(string $absolute, string $root): string
    {
        $resolvedRoot = rtrim($this->resolve($this->normalize($root)) ?? $this->normalize($root), DIRECTORY_SEPARATOR);
        $resolvedPath = $this->resolve($this->normalize($absolute)) ?? $this->normalize($absolute);

        if ($this->startsWith($resolvedPath, $resolvedRoot.DIRECTORY_SEPARATOR)) {
            $resolvedPath = substr($resolvedPath, strlen($resolvedRoot) + 1);
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', $resolvedPath);
    }

    /**
     * Reduce a user-supplied filename to something safe to write.
     *
     * Directory separators, control characters, leading dots and Windows
     * reserved device names are all removed. The result is only ever used for
     * display and for the stored `original_file_name`; the name actually
     * written to disk is generated, never derived from input.
     */
    public function sanitizeFileName(string $fileName): string
    {
        $name = basename(str_replace('\\', '/', $fileName));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? $name;
        $name = preg_replace('/[<>:"|?*]/u', '_', $name) ?? $name;
        $name = ltrim($name, '. ');
        $name = trim($name);

        if ($name === '') {
            return 'document';
        }

        // CON, PRN, AUX, NUL, COM1-9, LPT1-9 are device names on Windows even
        // with an extension appended.
        $stem = pathinfo($name, PATHINFO_FILENAME);

        if (preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/i', $stem) === 1) {
            $name = '_'.$name;
        }

        return mb_substr($name, 0, 200);
    }

    public function isAbsolute(string $path): bool
    {
        return preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1   // D:\...
            || str_starts_with($path, '\\\\')                     // \\server\share
            || str_starts_with($path, '/');                       // /mnt/...
    }

    /**
     * realpath() that returns null instead of false.
     *
     * Wrapped in a warning-silencing call because realpath() emits a warning
     * for unreachable network paths, which is an expected condition here.
     */
    private function resolve(string $path): ?string
    {
        $resolved = @realpath($path);

        return $resolved === false ? null : $resolved;
    }

    private function assertNotDeniedRoot(string $path): void
    {
        foreach (self::DENIED_ROOT_PREFIXES as $denied) {
            $normalizedDenied = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $denied);

            if ($this->startsWith($path, $normalizedDenied)) {
                throw new UnsafePathException('System folders may not be registered as a document source.');
            }
        }
    }

    /** Prefix test that is case-insensitive on Windows, where paths are. */
    private function startsWith(string $haystack, string $needle): bool
    {
        return PHP_OS_FAMILY === 'Windows'
            ? str_starts_with(mb_strtolower($haystack), mb_strtolower($needle))
            : str_starts_with($haystack, $needle);
    }
}
