<?php

declare(strict_types=1);

namespace App\Services\MicrosoftGraph\DTO;

use DateTimeImmutable;

/**
 * One item from a SharePoint or OneDrive document library.
 *
 * A thin, typed view over the Graph JSON, so nothing downstream has to know
 * the wire format or reach into nested arrays.
 */
final readonly class DriveItem
{
    public function __construct(
        public string $id,
        public string $name,

        /** Path relative to the configured source root, forward-slashed. */
        public string $relativePath,

        public ?string $parentPath,
        public int $size,
        public bool $isFolder,

        /**
         * The content change token.
         *
         * This is Graph's cTag when available, not its eTag. Both change when
         * a file is edited, but eTag *also* moves when only metadata changes -
         * renaming the file, editing a library column, updating a content
         * type. Keying change detection on eTag would re-queue an unchanged
         * document for full analysis every time a Document Controller tidied
         * a column, which is exactly what CLAUDE.md 9 and 35.16 exist to
         * prevent. eTag is the fallback for the rare item that has no cTag.
         */
        public ?string $changeToken,

        public ?string $mimeType = null,
        public ?DateTimeImmutable $lastModifiedAt = null,
        public ?string $webUrl = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  a Graph driveItem resource
     */
    public static function fromGraph(array $payload, string $relativePath): self
    {
        $file = is_array($payload['file'] ?? null) ? $payload['file'] : null;

        $rawModified = $payload['lastModifiedDateTime'] ?? null;
        $lastModified = null;

        if (is_string($rawModified) && $rawModified !== '') {
            try {
                $lastModified = new DateTimeImmutable($rawModified);
            } catch (\Exception) {
                // A malformed timestamp is not worth failing a scan over; the
                // content hash still catches the change.
                $lastModified = null;
            }
        }

        $parent = str_contains($relativePath, '/') ? dirname($relativePath) : '';

        return new self(
            id: (string) ($payload['id'] ?? ''),
            name: (string) ($payload['name'] ?? ''),
            relativePath: $relativePath,
            parentPath: $parent === '.' ? '' : $parent,
            size: (int) ($payload['size'] ?? 0),
            isFolder: is_array($payload['folder'] ?? null),
            changeToken: self::changeTokenFrom($payload),
            mimeType: is_string($file['mimeType'] ?? null) ? $file['mimeType'] : null,
            lastModifiedAt: $lastModified,
            webUrl: is_string($payload['webUrl'] ?? null) ? $payload['webUrl'] : null,
        );
    }

    public function extension(): string
    {
        return mb_strtolower(pathinfo($this->name, PATHINFO_EXTENSION));
    }

    /** @param array<string, mixed> $payload */
    private static function changeTokenFrom(array $payload): ?string
    {
        foreach (['cTag', 'eTag'] as $key) {
            $value = $payload[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
