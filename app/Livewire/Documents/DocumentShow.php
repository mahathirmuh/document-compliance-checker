<?php

declare(strict_types=1);

namespace App\Livewire\Documents;

use App\Models\Document;
use App\Services\Audit\AuditLogger;
use App\Services\Documents\DocumentAnalysisService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Document detail: metadata, per-language coverage, issues and version
 * history (CLAUDE.md 21).
 */
#[Layout('components.layouts.app')]
class DocumentShow extends Component
{
    public Document $document;

    public function mount(Document $document): void
    {
        Gate::authorize('view', $document);

        $this->document = $document;
    }

    /**
     * Put the current version back in the queue.
     *
     * Re-analysing creates a *new* analysis row against the same version
     * rather than editing the old one, so the previous result stays in the
     * history (CLAUDE.md 35.17).
     */
    public function reanalyze(DocumentAnalysisService $analysisService, AuditLogger $auditLogger): void
    {
        Gate::authorize('reanalyze', $this->document);

        $version = $this->document->currentVersion;

        if ($version === null) {
            session()->flash('error', 'This document has no current version to analyse.');

            return;
        }

        $analysisService->queue($this->document, $version, auth()->user());

        $auditLogger->log(
            AuditLogger::ACTION_DOCUMENT_REANALYZE_REQUESTED,
            $this->document,
            newValues: ['document_version_id' => $version->id],
        );

        $this->document->refresh();

        session()->flash('status', 'The document has been queued for analysis.');
    }

    public function render(): View
    {
        $analysis = $this->document->latestAnalysis()->with(['languageResults', 'issues'])->first();

        return view('livewire.documents.document-show', [
            'analysis' => $analysis,
            'languageResults' => $analysis?->languageResultMap() ?? [],
            'issues' => $analysis?->issues->sortByDesc(fn ($issue) => $issue->severity->rank()) ?? collect(),
            'versions' => $this->document->versions()
                ->with('latestAnalysis:id,document_version_id,status,overall_score,completed_at')
                ->orderByDesc('version_number')
                ->limit(20)
                ->get(),
        ])->title($this->document->displayTitle());
    }
}
