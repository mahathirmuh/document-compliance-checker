<?php

declare(strict_types=1);

namespace App\Livewire\Documents;

use App\Enums\LanguageCode;
use App\Models\Document;
use App\Services\Documents\DocumentComparisonService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The three languages side by side (CLAUDE.md 21).
 *
 * The coverage table answers "how much Indonesian is in this document". This
 * page answers the question a Document Controller asks next: *which*
 * Indonesian passage corresponds to which English one, and do they say the
 * same thing.
 *
 * The application makes no claim about meaning - it pairs the text up and
 * puts a person in front of it. That is the division CLAUDE.md 33 asks for:
 * the machine measures, the human judges, and nothing automated overwrites a
 * document.
 */
#[Layout('components.layouts.app')]
class DocumentCompare extends Component
{
    public Document $document;

    /** Show only sections where at least one language is absent. */
    public bool $onlyGaps = false;

    /**
     * Whether the text has been asked for yet.
     *
     * The first render deliberately fetches nothing. Reading a scanned
     * document means OCR, and a real 13-page scan took 83 seconds - which as
     * a synchronous page load is a browser that appears to have hung. The
     * shell renders immediately, says what it is doing, and `wire:init`
     * fetches straight after.
     */
    public bool $ready = false;

    public function mount(Document $document): void
    {
        Gate::authorize('view', $document);

        $this->document = $document;
    }

    /** Called by wire:init once the shell is on screen. */
    public function load(): void
    {
        $this->ready = true;
    }

    /**
     * Drop the cached text and read the file again.
     *
     * Offered because the cache is keyed on the file hash, which a source
     * that changed without being re-scanned would not yet reflect.
     */
    public function refresh(DocumentComparisonService $comparison): void
    {
        Gate::authorize('view', $this->document);

        $comparison->forget($this->document);
        $this->ready = true;

        session()->flash('status', 'The document was read again from its source.');
    }

    public function render(DocumentComparisonService $comparison): View
    {
        $extraction = $this->ready ? $comparison->extract($this->document) : null;

        $sections = $extraction?->sections ?? [];

        if ($this->onlyGaps) {
            $sections = array_values(array_filter(
                $sections,
                static fn ($section) => $section->missing !== [],
            ));
        }

        return view('livewire.documents.document-compare', [
            'extraction' => $extraction,
            'sections' => $sections,
            'languages' => LanguageCode::requiredOrder(),
            'unavailableReason' => $this->ready
                ? $this->unavailableReason($extraction !== null)
                : null,
        ])->title('Compare — '.$this->document->displayTitle());
    }

    /**
     * Why there is nothing to show.
     *
     * Written for a Document Controller rather than an operator: each of
     * these has a different fix, and "no text available" would leave them
     * guessing which.
     */
    private function unavailableReason(bool $haveExtraction): ?string
    {
        if ($haveExtraction) {
            return null;
        }

        if (! (bool) config('documents.analyzer.enabled', false)) {
            return 'The document analyzer is switched off, so the file cannot be read for comparison.';
        }

        if ($this->document->currentVersion === null) {
            return 'This document has no stored version to read.';
        }

        return 'The file could not be read from its source. It may have been moved or renamed, '
            .'or the analyzer may be unavailable. The application log has the detail.';
    }
}
