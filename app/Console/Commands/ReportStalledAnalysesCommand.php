<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AnalysisStatus;
use App\Models\Document;
use App\Models\DocumentAnalysis;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Reports work that has been sitting in the queue too long.
 *
 * A document stuck at PROCESSING means a worker died mid-analysis; a pile of
 * PENDING means no worker is running at all. Both look identical to a
 * Document Controller - "the dashboard says nothing" - so they are surfaced
 * to the log where an operator will see them (CLAUDE.md 18, 30).
 */
class ReportStalledAnalysesCommand extends Command
{
    protected $signature = 'documents:report-stalled
                            {--hours=6 : How long a document may sit before it counts as stalled}';

    protected $description = 'Report documents stuck in PENDING or PROCESSING';

    public function handle(): int
    {
        $hours = max((int) $this->option('hours'), 1);
        $cutoff = now()->subHours($hours);

        $stuckProcessing = DocumentAnalysis::query()
            ->where('status', AnalysisStatus::PROCESSING)
            ->where('started_at', '<', $cutoff)
            ->count();

        $stuckPending = Document::query()
            ->where('analysis_status', AnalysisStatus::PENDING)
            ->where('updated_at', '<', $cutoff)
            ->count();

        if ($stuckProcessing === 0 && $stuckPending === 0) {
            $this->info("No analyses have been stalled for more than {$hours} hours.");

            return self::SUCCESS;
        }

        $context = [
            'threshold_hours' => $hours,
            'stuck_processing' => $stuckProcessing,
            'stuck_pending' => $stuckPending,
        ];

        Log::warning('Stalled document analyses detected.', $context);

        $this->warn(sprintf(
            '%d analyses stuck in PROCESSING and %d documents stuck in PENDING for over %d hours. Check that a queue worker is running.',
            $stuckProcessing,
            $stuckPending,
            $hours,
        ));

        return self::SUCCESS;
    }
}
