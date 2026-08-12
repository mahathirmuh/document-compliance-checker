<?php

declare(strict_types=1);

namespace App\Livewire\Sources;

use App\Models\DocumentSource;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Scan history for one source (CLAUDE.md 22).
 *
 * The counters here are how an operator verifies change detection is doing
 * its job: a second scan of an untouched folder should show everything as
 * unchanged and nothing queued.
 */
#[Layout('components.layouts.app')]
class ScanHistory extends Component
{
    use WithPagination;

    public DocumentSource $source;

    public function mount(DocumentSource $source): void
    {
        Gate::authorize('view', $source);

        $this->source = $source;
    }

    public function render(): View
    {
        return view('livewire.sources.scan-history', [
            'scans' => $this->source->scanLogs()
                ->with('trigger:id,name')
                ->latest('started_at')
                ->paginate(20),
        ])->title('Scan history — '.$this->source->name);
    }
}
