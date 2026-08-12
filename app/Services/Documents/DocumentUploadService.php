<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Enums\AnalysisStatus;
use App\Enums\DocumentSourceType;
use App\Enums\DocumentType;
use App\Exceptions\RejectedUploadException;
use App\Models\Document;
use App\Models\DocumentSource;
use App\Models\User;
use App\Services\DocumentSources\DTO\SourceFile;
use App\Services\Files\FileHashService;
use App\Services\Files\PathGuard;
use DateTimeImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Accepts a manually uploaded document.
 *
 * The validation here is deliberately paranoid and layered (CLAUDE.md 13):
 *
 *   1. the extension must not be on the blocked list - checked first, and
 *      not overridable from the settings screen;
 *   2. the extension must be on the allowed list;
 *   3. the size must be within the configured limit;
 *   4. the detected MIME type must match the extension;
 *   5. the leading bytes must match the format the extension claims.
 *
 * The name written to disk is always generated. The name the user chose is
 * kept as metadata only, so a crafted filename never reaches the filesystem.
 */
class DocumentUploadService
{
    public function __construct(
        private readonly PathGuard $pathGuard,
        private readonly FileHashService $hashService,
        private readonly DocumentVersionService $versionService,
        private readonly DocumentAnalysisService $analysisService,
    ) {}

    /**
     * Validate, store and index an uploaded file.
     *
     * @throws RejectedUploadException
     */
    public function store(UploadedFile $upload, User $uploader, array $attributes = []): Document
    {
        $this->assertAcceptable($upload);

        $source = $this->uploadSource();
        $originalName = $this->pathGuard->sanitizeFileName($upload->getClientOriginalName());
        $extension = mb_strtolower($upload->getClientOriginalExtension());

        // Generated name: a ULID plus the validated extension. Nothing from
        // the client contributes to the path.
        $storedName = Str::ulid()->toString().'.'.$extension;
        $storedPath = date('Y/m').'/'.$storedName;

        $disk = Storage::disk((string) config('documents.upload.disk', 'documents'));
        $disk->putFileAs(dirname($storedPath), $upload, $storedName);

        $absolutePath = $disk->path($storedPath);
        $hash = $this->hashService->hashFile($absolutePath);

        $sourceFile = new SourceFile(
            itemId: hash('sha256', $storedPath),
            path: $absolutePath,
            relativePath: $storedPath,
            fileName: $originalName,
            extension: $extension,
            size: (int) $disk->size($storedPath),
            lastModifiedAt: new DateTimeImmutable,
            mimeType: $upload->getClientMimeType(),
        );

        return DB::transaction(function () use ($source, $sourceFile, $attributes, $hash, $storedPath, $uploader): Document {
            $document = Document::create([
                'document_source_id' => $source->id,
                'source_type' => DocumentSourceType::UPLOAD,
                'source_item_id' => $sourceFile->itemId,
                'file_path' => $storedPath,
                'file_name' => $sourceFile->fileName,
                'original_file_name' => $sourceFile->fileName,
                'extension' => $sourceFile->extension,
                'mime_type' => $sourceFile->mimeType,
                'file_size' => $sourceFile->size,
                'document_code' => $attributes['document_code'] ?? null,
                'document_title' => $attributes['document_title'] ?? pathinfo($sourceFile->fileName, PATHINFO_FILENAME),
                'document_type' => $attributes['document_type'] ?? DocumentType::guessFromFileName($sourceFile->fileName),
                'department' => $attributes['department'] ?? null,
                'current_revision' => $attributes['current_revision'] ?? null,
                'file_hash' => $hash,
                'source_last_modified_at' => now(),
                'analysis_status' => AnalysisStatus::PENDING,
                'is_active' => true,
            ]);

            $version = $this->versionService->createVersion($document, $sourceFile, $hash, $storedPath);

            $this->analysisService->queue($document, $version, $uploader);

            return $document;
        });
    }

    /**
     * The singleton source that owns every manual upload.
     *
     * Created on first use rather than seeded, so the row cannot be deleted
     * out from under the upload form.
     */
    public function uploadSource(): DocumentSource
    {
        return DocumentSource::firstOrCreate(
            ['type' => DocumentSourceType::UPLOAD],
            [
                'name' => 'Manual Upload',
                'enabled' => true,
                'scan_interval_minutes' => 0,
            ],
        );
    }

    /* ------------------------------------------------------------------ */
    /* Validation                                                          */
    /* ------------------------------------------------------------------ */

    /** @throws RejectedUploadException */
    private function assertAcceptable(UploadedFile $upload): void
    {
        if (! $upload->isValid()) {
            throw new RejectedUploadException('The file did not upload correctly. Please try again.');
        }

        $extension = mb_strtolower($upload->getClientOriginalExtension());

        // Deny list first: an extension here is refused even if a settings
        // change has somehow added it to the allowed list.
        if (in_array($extension, (array) config('documents.extensions.blocked', []), true)) {
            throw new RejectedUploadException('Executable and script files may not be uploaded.');
        }

        $allowed = array_map('mb_strtolower', (array) config('documents.extensions.uploadable', []));

        if (! in_array($extension, $allowed, true)) {
            throw new RejectedUploadException(sprintf(
                'Only %s files may be uploaded.',
                mb_strtoupper(implode(', ', $allowed)),
            ));
        }

        $maxKb = (int) config('documents.upload.max_size_kb', 65536);

        if ($upload->getSize() > $maxKb * 1024) {
            throw new RejectedUploadException(sprintf('The file is larger than the %d MB limit.', (int) round($maxKb / 1024)));
        }

        $this->assertMimeMatchesExtension($upload, $extension);
        $this->assertContentMatchesExtension($upload, $extension);
    }

    /** @throws RejectedUploadException */
    private function assertMimeMatchesExtension(UploadedFile $upload, string $extension): void
    {
        $expected = (array) config("documents.mime_types.{$extension}", []);

        if ($expected === []) {
            return;
        }

        // getMimeType() sniffs the contents via fileinfo; the client-supplied
        // type is not consulted, because the client controls it.
        $detected = $upload->getMimeType();

        if ($detected !== null && ! in_array($detected, $expected, true)) {
            throw new RejectedUploadException(
                'The file contents do not match its extension. The upload was rejected.',
            );
        }
    }

    /**
     * Check the leading bytes against the declared format.
     *
     * fileinfo reports every OOXML file as a zip, so this is what actually
     * distinguishes "a real .docx" from "a renamed archive with a payload
     * inside" (CLAUDE.md 13: do not trust the extension).
     *
     * @throws RejectedUploadException
     */
    private function assertContentMatchesExtension(UploadedFile $upload, string $extension): void
    {
        $handle = @fopen($upload->getRealPath(), 'rb');

        if ($handle === false) {
            throw new RejectedUploadException('The file could not be read for validation.');
        }

        try {
            $magic = (string) fread($handle, 8);
        } finally {
            fclose($handle);
        }

        $valid = match ($extension) {
            'pdf' => str_starts_with($magic, '%PDF-'),
            'docx', 'xlsx' => str_starts_with($magic, "PK\x03\x04"),

            // A text file has no signature; the only meaningful check is that
            // it is not secretly a binary, which the MIME sniff already did.
            'txt' => true,
            default => false,
        };

        if (! $valid) {
            throw new RejectedUploadException(
                'The file does not appear to be a valid '.mb_strtoupper($extension).' document.',
            );
        }

        if ($extension === 'docx' || $extension === 'xlsx') {
            $this->assertValidOoxml($upload, $extension);
        }
    }

    /**
     * Confirm an OOXML upload really is one.
     *
     * A .docx is a zip containing word/document.xml; a .xlsx contains
     * xl/workbook.xml. Checking for the right member catches a .xlsx renamed
     * to .docx as well as an arbitrary archive.
     *
     * @throws RejectedUploadException
     */
    private function assertValidOoxml(UploadedFile $upload, string $extension): void
    {
        $zip = new \ZipArchive;

        if ($zip->open($upload->getRealPath()) !== true) {
            throw new RejectedUploadException('The Office file could not be opened. It may be corrupt.');
        }

        try {
            $required = $extension === 'docx' ? 'word/document.xml' : 'xl/workbook.xml';

            if ($zip->locateName($required) === false) {
                throw new RejectedUploadException(
                    'The file is not a valid '.mb_strtoupper($extension).' document.',
                );
            }
        } finally {
            $zip->close();
        }
    }
}
