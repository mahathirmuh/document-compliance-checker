<?php

declare(strict_types=1);

namespace App\Livewire\Sources;

use App\Jobs\ScanDocumentSourceJob;
use App\Models\DocumentSource;
use App\Services\Audit\AuditLogger;
use App\Services\DocumentSources\DocumentSourceFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * Source management (CLAUDE.md 22).
 *
 * Note what is not here: no secret is ever rendered. A SharePoint source
 * shows its site and drive identifiers, which are not sensitive; the
 * credentials that reach them live in the environment and are never loaded
 * into this component.
 */
#[Layout('components.layouts.app')]
#[Title('Document sources')]
class SourceIndex extends Component
{
    /** @var array<int, array{ok: bool, message: string}> keyed by source id */
    public array $connectionResults = [];

    public function mount(): void
    {
        Gate::authorize('manage-sources');
    }

    public function scanNow(int $sourceId, AuditLogger $auditLogger): void
    {
        $source = DocumentSource::findOrFail($sourceId);

        Gate::authorize('scan', $source);

        // Dispatched, never run inline: a share with thousands of files would
        // hold the request open for minutes (CLAUDE.md 17).
        ScanDocumentSourceJob::dispatch($source->id, auth()->id());

        $auditLogger->log(AuditLogger::ACTION_SOURCE_SCAN_REQUESTED, $source);

        session()->flash('status', "Scan queued for [{$source->name}]. Results appear in its scan history.");
    }

    public function testConnection(int $sourceId, DocumentSourceFactory $factory): void
    {
        $source = DocumentSource::findOrFail($sourceId);

        Gate::authorize('testConnection', $source);

        try {
            $this->connectionResults[$sourceId] = $factory->make($source)->testConnection();
        } catch (Throwable $e) {
            Log::warning('Source connection test threw.', [
                'document_source_id' => $source->id,
                'exception' => $e->getMessage(),
            ]);

            $this->connectionResults[$sourceId] = [
                'ok' => false,
                'message' => 'The connection test failed. Check the application log for details.',
            ];
        }
    }

    public function toggleEnabled(int $sourceId, AuditLogger $auditLogger): void
    {
        $source = DocumentSource::findOrFail($sourceId);

        Gate::authorize('update', $source);

        $original = $source->getOriginal();
        $source->update(['enabled' => ! $source->enabled]);

        $auditLogger->logChanges(AuditLogger::ACTION_SOURCE_UPDATED, $source, $original);

        session()->flash('status', sprintf(
            '[%s] is now %s.',
            $source->name,
            $source->enabled ? 'enabled' : 'disabled',
        ));
    }

    public function render(): View
    {
        return view('livewire.sources.source-index', [
            'sources' => DocumentSource::query()
                ->withCount('documents')
                ->with(['scanLogs' => fn ($q) => $q->latest('started_at')->limit(1)])
                ->orderBy('name')
                ->get(),
        ]);
    }
}
