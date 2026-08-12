<?php

declare(strict_types=1);

namespace App\Services\DocumentSources;

use App\Models\Document;
use App\Models\DocumentSource;
use App\Services\DocumentSources\Contracts\DocumentSourceInterface;
use App\Services\DocumentSources\DTO\SourceFile;
use Generator;
use RuntimeException;

/**
 * Placeholder for the Microsoft Graph adapter (Phase 3).
 *
 * It exists now so the shape of the abstraction is proven against a second,
 * genuinely different source type rather than being designed around the
 * filesystem alone - the SourceFile DTO already carries `etag`, `itemId`,
 * `drive_id` and `site_id` for exactly this implementation to fill in.
 *
 * Every method fails loudly. A half-working SharePoint adapter that silently
 * returned nothing would look identical to an empty library, and a Document
 * Controller would read that as "no documents to fix" (CLAUDE.md 35.18).
 */
class SharePointSource implements DocumentSourceInterface
{
    public function __construct(private readonly DocumentSource $source) {}

    public function listFiles(): Generator
    {
        throw $this->notImplemented();

        // @phpstan-ignore-next-line - unreachable, but keeps the return type honest.
        yield from [];
    }

    public function getMetadata(string $itemId): ?SourceFile
    {
        throw $this->notImplemented();
    }

    public function exists(string $itemId): bool
    {
        throw $this->notImplemented();
    }

    public function openFile(string $itemId)
    {
        throw $this->notImplemented();
    }

    public function downloadTemporaryCopy(string $itemId): ?string
    {
        throw $this->notImplemented();
    }

    public function releaseTemporaryCopy(string $path): void
    {
        throw $this->notImplemented();
    }

    /**
     * Reports the configuration gap rather than throwing.
     *
     * "Test connection" is the one place an administrator can reasonably
     * press this button today, and a clear answer is more useful than a
     * stack trace.
     */
    public function testConnection(): array
    {
        return [
            'ok' => false,
            'message' => 'SharePoint sources can be registered now but are not scanned until the Microsoft Graph integration ships in Phase 3.',
        ];
    }

    public function absolutePathFor(Document $document): ?string
    {
        // Nothing local: SharePoint remains the system of record and the file
        // is only ever materialised as a temporary download.
        return null;
    }

    private function notImplemented(): RuntimeException
    {
        return new RuntimeException(sprintf(
            'SharePoint source [%s] cannot be read yet: the Microsoft Graph integration is Phase 3.',
            $this->source->name,
        ));
    }
}
