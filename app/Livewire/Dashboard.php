<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\AnalysisStatus;
use App\Enums\LanguageCode;
use App\Enums\ScanStatus;
use App\Models\Document;
use App\Models\DocumentAnalysis;
use App\Models\LanguageResult;
use App\Models\ScanLog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Compliance overview (CLAUDE.md 19).
 *
 * Every panel is a grouped aggregate rather than a collection walk, so the
 * page cost does not grow with the size of the document library.
 */
#[Layout('components.layouts.app')]
#[Title('Dashboard')]
class Dashboard extends Component
{
    public function render(): View
    {
        return view('livewire.dashboard', [
            'statusCounts' => $this->statusCounts(),
            'totalDocuments' => array_sum($this->statusCounts()),
            'compliancePercent' => $this->compliancePercent(),
            'byType' => $this->countsBy('document_type'),
            'bySource' => $this->countsBySource(),
            'byDepartment' => $this->countsBy('department'),
            'languageCompliance' => $this->languageCompliance(),
            'recentAnalyses' => $this->recentAnalyses(),
            'failedScans' => $this->failedScans(),
        ]);
    }

    /**
     * Document counts per status, with every status present at zero.
     *
     * A status missing from the grid would read as "no such state exists"
     * rather than "nothing is in it".
     *
     * @return array<string, int>
     */
    private function statusCounts(): array
    {
        static $counts = null;

        if ($counts !== null) {
            return $counts;
        }

        $raw = Document::query()
            ->active()
            ->groupBy('analysis_status')
            ->pluck(DB::raw('count(*)'), 'analysis_status')
            ->all();

        $counts = [];

        foreach (AnalysisStatus::dashboardOrder() as $status) {
            $counts[$status->value] = (int) ($raw[$status->value] ?? 0);
        }

        return $counts;
    }

    /**
     * Share of graded documents that passed.
     *
     * Only documents that actually reached a verdict count. Including
     * PENDING would make the figure improve every time a worker fell behind,
     * which is exactly backwards.
     */
    private function compliancePercent(): ?float
    {
        $counts = $this->statusCounts();

        $graded = $counts[AnalysisStatus::PASS->value]
            + $counts[AnalysisStatus::PARTIAL->value]
            + $counts[AnalysisStatus::FAIL->value]
            + $counts[AnalysisStatus::REVIEW_REQUIRED->value];

        if ($graded === 0) {
            return null;
        }

        return round(($counts[AnalysisStatus::PASS->value] / $graded) * 100, 1);
    }

    /** @return array<string, int> */
    private function countsBy(string $column): array
    {
        return Document::query()
            ->active()
            ->whereNotNull($column)
            ->groupBy($column)
            ->orderByDesc(DB::raw('count(*)'))
            ->limit(10)
            ->pluck(DB::raw('count(*)'), $column)
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /** @return array<string, int> */
    private function countsBySource(): array
    {
        return Document::query()
            ->active()
            ->join('document_sources', 'document_sources.id', '=', 'documents.document_source_id')
            ->groupBy('document_sources.name')
            ->orderByDesc(DB::raw('count(*)'))
            ->limit(10)
            ->pluck(DB::raw('count(*)'), 'document_sources.name')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * How many documents satisfy each required language.
     *
     * Counted against the newest analysis per document only - older analyses
     * describe versions that have already been superseded.
     *
     * @return array<string, array{code: string, label: string, meets: int, total: int}>
     */
    private function languageCompliance(): array
    {
        $latestAnalysisIds = DocumentAnalysis::query()
            ->select(DB::raw('max(id)'))
            ->whereIn('status', [
                AnalysisStatus::PASS->value,
                AnalysisStatus::PARTIAL->value,
                AnalysisStatus::FAIL->value,
                AnalysisStatus::REVIEW_REQUIRED->value,
            ])
            ->groupBy('document_id');

        $rows = LanguageResult::query()
            ->whereIn('document_analysis_id', $latestAnalysisIds)
            ->groupBy('language_code')
            ->select([
                'language_code',
                DB::raw('count(*) as total'),
                DB::raw('sum(case when meets_threshold then 1 else 0 end) as meets'),
            ])
            ->get()
            ->keyBy('language_code');

        $compliance = [];

        foreach (LanguageCode::requiredOrder() as $language) {
            $row = $rows->get($language->value);

            $compliance[$language->value] = [
                'code' => $language->value,
                'label' => $language->label(),
                'meets' => (int) ($row->meets ?? 0),
                'total' => (int) ($row->total ?? 0),
            ];
        }

        return $compliance;
    }

    private function recentAnalyses()
    {
        return DocumentAnalysis::query()
            ->with('document:id,file_name,document_title,document_code')
            ->whereNotNull('completed_at')
            ->latest('completed_at')
            ->limit(8)
            ->get();
    }

    private function failedScans()
    {
        return ScanLog::query()
            ->with('source:id,name,type')
            ->whereIn('status', [ScanStatus::FAILED, ScanStatus::COMPLETED_WITH_ERRORS])
            ->latest('started_at')
            ->limit(5)
            ->get();
    }
}
