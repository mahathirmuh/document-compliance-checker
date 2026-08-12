<?php

declare(strict_types=1);

namespace App\Services\DocumentSources;

use App\Enums\DocumentSourceType;
use App\Models\DocumentSource;
use App\Services\DocumentSources\Contracts\DocumentSourceInterface;
use App\Services\Files\FileHashService;
use App\Services\Files\PathGuard;
use App\Services\Files\TemporaryFileService;
use App\Services\MicrosoftGraph\SharePointService;

/**
 * Resolves the adapter for a source.
 *
 * This is the one and only place in the application that is allowed to switch
 * on DocumentSourceType. Adding a source type should mean writing an adapter
 * and adding one arm here - nothing else (CLAUDE.md 4).
 */
class DocumentSourceFactory
{
    public function __construct(
        private readonly PathGuard $pathGuard,
        private readonly FileHashService $hashService,
        private readonly SharePointService $sharePoint,
        private readonly TemporaryFileService $temporaryFiles,
    ) {}

    public function make(DocumentSource $source): DocumentSourceInterface
    {
        return match ($source->type) {
            DocumentSourceType::WINDOWS_LOCAL,
            DocumentSourceType::WINDOWS_SHARE,
            DocumentSourceType::NAS => new WindowsFolderSource(
                $source,
                $this->pathGuard,
                $this->hashService,
            ),

            DocumentSourceType::SHAREPOINT => new SharePointSource(
                $source,
                $this->sharePoint,
                $this->temporaryFiles,
            ),

            DocumentSourceType::UPLOAD => new UploadSource($source),
        };
    }
}
