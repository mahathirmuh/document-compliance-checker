<?php

declare(strict_types=1);

namespace App\Livewire\Documents;

use App\Enums\DocumentType;
use App\Exceptions\RejectedUploadException;
use App\Services\Audit\AuditLogger;
use App\Services\Documents\DocumentUploadService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Manual document upload (CLAUDE.md 3.2, 13).
 *
 * The rules here are the outer layer only. DocumentUploadService re-checks
 * everything - extension, MIME, magic bytes, OOXML structure - because this
 * component is not the only possible caller and a validation rule that lives
 * in the UI is a validation rule that can be bypassed.
 */
#[Layout('components.layouts.app')]
#[Title('Upload document')]
class UploadDocument extends Component
{
    use WithFileUploads;

    public $file;

    public string $documentCode = '';

    public string $documentTitle = '';

    public string $documentType = '';

    public string $department = '';

    public string $revision = '';

    public function mount(): void
    {
        Gate::authorize('upload-document');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $allowed = implode(',', (array) config('documents.extensions.uploadable', []));
        $maxKb = (int) config('documents.upload.max_size_kb', 65536);

        return [
            'file' => ['required', 'file', "mimes:{$allowed}", "max:{$maxKb}"],
            'documentCode' => ['nullable', 'string', 'max:100'],
            'documentTitle' => ['nullable', 'string', 'max:255'],
            'documentType' => ['nullable', 'string', 'in:'.implode(',', array_column(DocumentType::cases(), 'value'))],
            'department' => ['nullable', 'string', 'max:100'],
            'revision' => ['nullable', 'string', 'max:32'],
        ];
    }

    public function save(DocumentUploadService $uploadService, AuditLogger $auditLogger)
    {
        Gate::authorize('upload-document');

        $this->validate();

        if (! $this->file instanceof TemporaryUploadedFile) {
            $this->addError('file', 'Please choose a file to upload.');

            return null;
        }

        try {
            $document = $uploadService->store(
                $this->file,
                auth()->user(),
                array_filter([
                    'document_code' => $this->documentCode ?: null,
                    'document_title' => $this->documentTitle ?: null,
                    'document_type' => DocumentType::tryFrom($this->documentType),
                    'department' => $this->department ?: null,
                    'current_revision' => $this->revision ?: null,
                ]),
            );
        } catch (RejectedUploadException $e) {
            // Safe to show: these messages are written for this form.
            $this->addError('file', $e->getMessage());

            return null;
        } catch (Throwable $e) {
            Log::error('Document upload failed.', ['exception' => $e->getMessage()]);
            $this->addError('file', 'The upload could not be completed. Please try again or contact your administrator.');

            return null;
        }

        $auditLogger->log(
            AuditLogger::ACTION_DOCUMENT_UPLOADED,
            $document,
            newValues: [
                'file_name' => $document->file_name,
                'file_size' => $document->file_size,
                'document_code' => $document->document_code,
            ],
        );

        session()->flash('status', 'Document uploaded and queued for analysis.');

        return $this->redirectRoute('documents.show', $document, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.documents.upload-document', [
            'types' => DocumentType::cases(),
            'allowedExtensions' => (array) config('documents.extensions.uploadable', []),
            'maxSizeMb' => (int) round(((int) config('documents.upload.max_size_kb', 65536)) / 1024),
        ]);
    }
}
