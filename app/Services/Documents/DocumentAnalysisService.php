<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Enums\AnalysisStatus;
use App\Enums\IssueType;
use App\Enums\LanguageCode;
use App\Jobs\AnalyzeDocumentJob;
use App\Models\Document;
use App\Models\DocumentAnalysis;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\Analyzer\DTO\AnalysisResult;
use App\Services\Analyzer\DTO\LanguageFinding;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Owns the analysis lifecycle and the trilingual verdict.
 *
 * The analyser measures; this class grades. Every threshold it applies comes
 * from SettingsService, so a Document Controller can retune what "enough
 * Indonesian" means without a deploy, and no rule is hard-coded anywhere else
 * (CLAUDE.md 6, 25).
 *
 * It is also the only writer of the denormalised status columns on
 * `documents`. Nothing else may set analysis_status, compliance_score or
 * last_analyzed_at, or the summary drifts from the analyses it summarises.
 */
class DocumentAnalysisService
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Put a version in the queue.
     *
     * Analysis is never performed inline - a large scanned PDF can take
     * minutes, and doing that inside a web request would hold a PHP worker
     * hostage (CLAUDE.md 17, 35.11).
     */
    public function queue(Document $document, DocumentVersion $version, ?User $requestedBy = null): void
    {
        $document->forceFill([
            'analysis_status' => AnalysisStatus::PENDING,
        ])->save();

        AnalyzeDocumentJob::dispatch($version->id, $requestedBy?->id);
    }

    /** Open an analysis record and mark the document as in flight. */
    public function start(DocumentVersion $version, ?int $requestedBy = null): DocumentAnalysis
    {
        return DB::transaction(function () use ($version, $requestedBy): DocumentAnalysis {
            $analysis = $version->analyses()->create([
                'document_id' => $version->document_id,
                'status' => AnalysisStatus::PROCESSING,
                'started_at' => now(),
                'requested_by' => $requestedBy,
            ]);

            $this->mirrorToDocument($version->document, AnalysisStatus::PROCESSING, null, null);

            return $analysis;
        });
    }

    /**
     * Apply an analyser result: grade it, store the detail, mirror the verdict.
     */
    public function complete(DocumentAnalysis $analysis, AnalysisResult $result): DocumentAnalysis
    {
        return DB::transaction(function () use ($analysis, $result): DocumentAnalysis {
            $thresholds = $this->thresholds();
            $graded = [];

            foreach (LanguageCode::requiredOrder() as $language) {
                $finding = $result->language($language);
                $threshold = $thresholds[$language->value];
                $meets = $finding->detected && $finding->meaningfulCount() >= $threshold;

                $analysis->languageResults()->create([
                    'language_code' => $language,
                    'detected' => $finding->detected,
                    'character_count' => $finding->characterCount,
                    'word_count' => $finding->wordCount,
                    'coverage_percent' => $finding->coveragePercent,
                    'confidence' => $finding->confidence,
                    'threshold_applied' => $threshold,
                    'meets_threshold' => $meets,
                ]);

                $graded[$language->value] = ['finding' => $finding, 'meets' => $meets, 'threshold' => $threshold];
            }

            $this->recordSections($analysis, $result);
            $this->recordIssues($analysis, $result, $graded);

            $status = $this->determineStatus($result, $graded);
            $score = $this->calculateScore($graded);

            $analysis->update([
                'status' => $status,
                'overall_score' => $score,
                'analyzer_version' => $result->analyzerVersion,
                'completed_at' => now(),
                // Carbon returns a float here; the column is an integer.
                'duration_ms' => $this->elapsedMs($analysis),
                'raw_result' => $result->raw,
                'rule_results' => $result->ruleOutcomes ?: null,
            ]);

            $this->applyExtractedMetadata($analysis->document, $result);

            $analysis->version->update(['analyzed_at' => now()]);
            $this->mirrorToDocument($analysis->document, $status, $score, now());

            return $analysis->refresh();
        });
    }

    /**
     * Record that analysis failed.
     *
     * The message is written by the caller and is safe to display; raw
     * exception text is logged separately so a stack trace containing a UNC
     * path or a token never reaches the UI (CLAUDE.md 30, 35.18).
     */
    public function fail(DocumentAnalysis $analysis, string $safeMessage): DocumentAnalysis
    {
        return DB::transaction(function () use ($analysis, $safeMessage): DocumentAnalysis {
            $analysis->update([
                'status' => AnalysisStatus::ERROR,
                'error_message' => $safeMessage,
                'completed_at' => now(),
                // Carbon returns a float here; the column is an integer.
                'duration_ms' => $this->elapsedMs($analysis),
            ]);

            $analysis->issues()->create([
                'issue_type' => IssueType::PARSER_ERROR,
                'severity' => IssueType::PARSER_ERROR->defaultSeverity(),
                'description' => $safeMessage,
            ]);

            $this->mirrorToDocument($analysis->document, AnalysisStatus::ERROR, null, now());

            return $analysis;
        });
    }

    /**
     * Return a version to the queue without recording a verdict.
     *
     * Used when the analyser is simply unavailable. The document stays
     * PENDING because nothing was learned about it - marking it ERROR would
     * put an infrastructure outage into a document's compliance history.
     */
    public function releaseWithoutVerdict(DocumentAnalysis $analysis, string $reason): void
    {
        Log::warning('Analysis released without a verdict.', [
            'document_analysis_id' => $analysis->id,
            'document_id' => $analysis->document_id,
            'reason' => $reason,
        ]);

        DB::transaction(function () use ($analysis): void {
            $document = $analysis->document;
            $analysis->delete();
            $this->mirrorToDocument($document, AnalysisStatus::PENDING, null, null);
        });
    }

    /* ------------------------------------------------------------------ */
    /* Grading */
    /* ------------------------------------------------------------------ */

    /**
     * Apply the CLAUDE.md 6 verdict rules.
     *
     * @param  array<string, array{finding: LanguageFinding, meets: bool, threshold: int}>  $graded
     */
    private function determineStatus(AnalysisResult $result, array $graded): AnalysisStatus
    {
        // A document with essentially no extractable text is almost always a
        // scan. Reporting FAIL there would blame the document for a parser
        // limitation, so it goes to a human instead (CLAUDE.md 16).
        if ($result->totalMeaningfulCount() === 0 || $this->hasOcrIssue($result)) {
            return AnalysisStatus::REVIEW_REQUIRED;
        }

        $missing = array_filter($graded, fn (array $g) => ! $g['finding']->detected);

        if ($missing !== []) {
            return AnalysisStatus::FAIL;
        }

        $belowThreshold = array_filter($graded, fn (array $g) => ! $g['meets']);

        return $belowThreshold === [] ? AnalysisStatus::PASS : AnalysisStatus::PARTIAL;
    }

    /**
     * Overall compliance score, 0-100.
     *
     * Each language contributes how far it got towards its own threshold,
     * capped at 100% so a document padded with English cannot compensate for
     * absent Mandarin. The three then average, which makes a missing language
     * cost a third of the score outright.
     *
     * @param  array<string, array{finding: LanguageFinding, meets: bool, threshold: int}>  $graded
     */
    private function calculateScore(array $graded): float
    {
        if ($graded === []) {
            return 0.0;
        }

        $total = 0.0;

        foreach ($graded as $entry) {
            $threshold = max($entry['threshold'], 1);
            $ratio = min($entry['finding']->meaningfulCount() / $threshold, 1.0);
            $total += $ratio;
        }

        return round(($total / count($graded)) * 100, 2);
    }

    /**
     * @param  array<string, array{finding: LanguageFinding, meets: bool, threshold: int}>  $graded
     */
    private function recordIssues(DocumentAnalysis $analysis, AnalysisResult $result, array $graded): void
    {
        // Issues the analyser raised itself - parser problems, OCR hints.
        foreach ($result->issues as $issue) {
            $analysis->issues()->create([
                'issue_type' => $issue['type'],
                'severity' => $issue['severity'],
                'description' => $issue['description'],
                'language_code' => $issue['language'],
                'page_number' => $issue['page'],
                'section_name' => $issue['section'],
                'metadata' => $issue['metadata'],
            ]);
        }

        // Issues that follow from applying our thresholds to its measurements.
        foreach ($graded as $code => $entry) {
            $language = LanguageCode::from($code);
            $finding = $entry['finding'];

            if (! $finding->detected) {
                $analysis->issues()->create([
                    'issue_type' => IssueType::LANGUAGE_MISSING,
                    'severity' => IssueType::LANGUAGE_MISSING->defaultSeverity(),
                    'language_code' => $language,
                    'description' => sprintf('%s was not detected in this document.', $language->label()),
                    'metadata' => ['threshold' => $entry['threshold']],
                ]);

                continue;
            }

            if (! $entry['meets']) {
                $analysis->issues()->create([
                    'issue_type' => IssueType::LOW_LANGUAGE_COVERAGE,
                    'severity' => IssueType::LOW_LANGUAGE_COVERAGE->defaultSeverity(),
                    'language_code' => $language,
                    'description' => sprintf(
                        '%s is present but has only %d of the required %d characters.',
                        $language->label(),
                        $finding->meaningfulCount(),
                        $entry['threshold'],
                    ),
                    'metadata' => [
                        'threshold' => $entry['threshold'],
                        'actual' => $finding->meaningfulCount(),
                    ],
                ]);
            }
        }
    }

    /**
     * Store the per-section breakdown.
     *
     * Written verbatim from the analyzer: which languages a section contains
     * is a fact about the text, not a policy decision, so unlike the
     * document-level verdict there is nothing here to re-grade (CLAUDE.md 7).
     */
    private function recordSections(DocumentAnalysis $analysis, AnalysisResult $result): void
    {
        foreach ($result->sections as $section) {
            $analysis->sections()->create([
                'name' => mb_substr($section->name, 0, 255),
                'sequence' => $section->sequence,
                'page_number' => $section->page,
                'total_characters' => $section->totalCharacters,
                'segment_count' => $section->segmentCount,
                'language_characters' => $section->characters,
                'missing_languages' => $section->missing,
                'short_languages' => $section->short,
                'evaluated' => $section->evaluated,
            ]);
        }
    }

    /**
     * Fill in the document code and revision the analyzer read out of the file.
     *
     * Only ever fills a blank. A Document Controller who corrected a code by
     * hand must keep that correction: the same rule that stops a folder scan
     * overwriting a curated title applies here, and an OCR-derived reading is
     * exactly the kind of value a human would be right to override
     * (CLAUDE.md 33 - analyzer output is advisory).
     */
    private function applyExtractedMetadata(Document $document, AnalysisResult $result): void
    {
        $updates = [];

        $code = $result->extractedMetadata['document_code'] ?? null;
        $revision = $result->extractedMetadata['revision'] ?? null;

        if ($code !== null && blank($document->document_code)) {
            $updates['document_code'] = mb_substr($code, 0, 255);
        }

        if ($revision !== null && blank($document->current_revision)) {
            $updates['current_revision'] = mb_substr($revision, 0, 32);
        }

        if ($updates !== []) {
            $document->forceFill($updates)->save();
        }
    }

    private function hasOcrIssue(AnalysisResult $result): bool
    {
        foreach ($result->issues as $issue) {
            if ($issue['type'] === IssueType::OCR_REQUIRED) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, int> */
    private function thresholds(): array
    {
        $thresholds = [];

        foreach (LanguageCode::cases() as $language) {
            $thresholds[$language->value] = $this->settings->integer($language->thresholdSettingKey());
        }

        return $thresholds;
    }

    /** Whole milliseconds since the analysis opened, for the integer column. */
    private function elapsedMs(DocumentAnalysis $analysis): ?int
    {
        return $analysis->started_at === null
            ? null
            : (int) round($analysis->started_at->diffInMilliseconds(now()));
    }

    private function mirrorToDocument(
        Document $document,
        AnalysisStatus $status,
        ?float $score,
        ?\DateTimeInterface $analyzedAt,
    ): void {
        $document->forceFill(array_filter([
            'analysis_status' => $status,
            'compliance_score' => $score,
            'last_analyzed_at' => $analyzedAt,
        ], fn ($value) => $value !== null))->save();
    }
}
