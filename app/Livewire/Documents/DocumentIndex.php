<?php

declare(strict_types=1);

namespace App\Livewire\Documents;

use App\Enums\AnalysisStatus;
use App\Enums\DocumentType;
use App\Enums\LanguageCode;
use App\Models\Document;
use App\Models\DocumentSource;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The document list (CLAUDE.md 20).
 *
 * Filters are bound to the query string so a Document Controller can bookmark
 * "everything missing Mandarin in Quality" and share the link with a
 * colleague, which is the workflow this screen exists for.
 */
#[Layout('components.layouts.app')]
#[Title('Documents')]
class DocumentIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $source = '';

    #[Url(except: '')]
    public string $department = '';

    #[Url(as: 'missing', except: '')]
    public string $missingLanguage = '';

    #[Url(except: false)]
    public bool $includeInactive = false;

    #[Url(except: 'updated_at')]
    public string $sortField = 'updated_at';

    #[Url(except: 'desc')]
    public string $sortDirection = 'desc';

    public int $perPage = 25;

    /**
     * Columns a user is allowed to sort by.
     *
     * An allow list rather than trusting the bound property: sortField comes
     * from the query string, and it is interpolated into an order-by.
     *
     * @var array<int, string>
     */
    private const SORTABLE = [
        'document_code', 'document_title', 'file_name', 'document_type',
        'compliance_score', 'analysis_status', 'source_last_modified_at',
        'last_analyzed_at', 'updated_at',
    ];

    /** Any filter change has to send the user back to page one. */
    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->resetPage();
        }
    }

    public function sortBy(string $field): void
    {
        if (! in_array($field, self::SORTABLE, true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset([
            'search', 'status', 'type', 'source',
            'department', 'missingLanguage', 'includeInactive',
        ]);

        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.documents.document-index', [
            'documents' => $this->documents(),
            'statuses' => AnalysisStatus::cases(),
            'types' => DocumentType::cases(),
            'languages' => LanguageCode::requiredOrder(),
            'sources' => DocumentSource::query()->orderBy('name')->get(['id', 'name']),
            'departments' => Document::query()
                ->whereNotNull('department')
                ->distinct()
                ->orderBy('department')
                ->pluck('department'),
        ]);
    }

    private function documents()
    {
        $query = Document::query()
            ->with([
                'source:id,name,type',
                // Table-qualified because latestOfMany() builds a self-join on
                // document_analyses, which makes a bare "document_id"
                // ambiguous to PostgreSQL. The columns are still listed rather
                // than selecting everything, because raw_result is a large
                // JSONB blob and this reads a page of rows at a time.
                'latestAnalysis:document_analyses.id,document_analyses.document_id,document_analyses.status,document_analyses.overall_score',

                // No qualification needed here: language_results is a plain
                // has-many with no self-join.
                'latestAnalysis.languageResults:id,document_analysis_id,language_code,detected,meets_threshold',
            ])
            ->search($this->search);

        if (! $this->includeInactive) {
            $query->active();
        }

        $status = AnalysisStatus::tryFrom($this->status);
        if ($status !== null) {
            $query->status($status);
        }

        $type = DocumentType::tryFrom($this->type);
        if ($type !== null) {
            $query->where('document_type', $type);
        }

        if ($this->source !== '') {
            $query->where('document_source_id', (int) $this->source);
        }

        if ($this->department !== '') {
            $query->where('department', $this->department);
        }

        $missing = LanguageCode::tryFrom($this->missingLanguage);
        if ($missing !== null) {
            $query->missingLanguage($missing);
        }

        $sortField = in_array($this->sortField, self::SORTABLE, true) ? $this->sortField : 'updated_at';
        $direction = $this->sortDirection === 'asc' ? 'asc' : 'desc';

        return $query
            ->orderBy($sortField, $direction)
            // Tiebreaker so pagination is deterministic when the sort column
            // holds duplicates - without it rows can repeat across pages.
            ->orderBy('id', 'desc')
            ->paginate($this->perPage);
    }
}
