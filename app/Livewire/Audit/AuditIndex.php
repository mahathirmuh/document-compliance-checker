<?php

declare(strict_types=1);

namespace App\Livewire\Audit;

use App\Models\AuditLog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only view of the audit trail (CLAUDE.md 8.9).
 *
 * There is no edit or delete action here by design. Values were already
 * redacted on the way in, so nothing rendered on this page can contain a
 * secret.
 */
#[Layout('components.layouts.app')]
#[Title('Audit log')]
class AuditIndex extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $action = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(): void
    {
        Gate::authorize('view-audit-log');
    }

    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $query = AuditLog::query()->with('user:id,name')->latest('created_at');

        if ($this->action !== '') {
            $query->where('action', $this->action);
        }

        if (trim($this->search) !== '') {
            $escaped = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($this->search)).'%';
            $query->where('user_email', 'ilike', $escaped);
        }

        return view('livewire.audit.audit-index', [
            'entries' => $query->paginate(30),
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
        ]);
    }
}
