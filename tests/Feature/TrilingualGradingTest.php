<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Enums\IssueType;
use App\Enums\LanguageCode;
use App\Models\Document;
use App\Models\DocumentAnalysis;
use App\Models\DocumentVersion;
use App\Services\Analyzer\DTO\AnalysisResult;
use App\Services\Documents\DocumentAnalysisService;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The verdict matrix from CLAUDE.md 29.
 *
 * Grading lives in Laravel rather than the analyser, so these rules are fully
 * testable in Phase 1 - before the Python service exists - by feeding
 * DocumentAnalysisService the measurements an analyser would have produced.
 */
class TrilingualGradingTest extends TestCase
{
    use RefreshDatabase;

    private DocumentAnalysisService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(DocumentAnalysisService::class);

        // Pin the thresholds so the expectations below do not silently change
        // meaning if the shipped defaults are retuned.
        $settings = app(SettingsService::class);
        $settings->set('min_chars_en', 100);
        $settings->set('min_chars_id', 100);
        $settings->set('min_chars_zh', 50);
    }

    #[Test]
    public function all_three_languages_above_threshold_pass(): void
    {
        $analysis = $this->grade(['en' => 500, 'id' => 480, 'zh' => 300]);

        $this->assertSame(AnalysisStatus::PASS, $analysis->status);
        $this->assertSame('100.00', (string) $analysis->overall_score);
        $this->assertCount(0, $analysis->issues);
    }

    #[Test]
    public function english_only_fails(): void
    {
        $analysis = $this->grade(['en' => 500, 'id' => 0, 'zh' => 0]);

        $this->assertSame(AnalysisStatus::FAIL, $analysis->status);
    }

    #[Test]
    public function indonesian_only_fails(): void
    {
        $this->assertSame(
            AnalysisStatus::FAIL,
            $this->grade(['en' => 0, 'id' => 500, 'zh' => 0])->status,
        );
    }

    #[Test]
    public function mandarin_only_fails(): void
    {
        $this->assertSame(
            AnalysisStatus::FAIL,
            $this->grade(['en' => 0, 'id' => 0, 'zh' => 500])->status,
        );
    }

    #[Test]
    public function english_and_indonesian_without_mandarin_fails(): void
    {
        $this->assertSame(
            AnalysisStatus::FAIL,
            $this->grade(['en' => 500, 'id' => 500, 'zh' => 0])->status,
        );
    }

    #[Test]
    public function english_and_mandarin_without_indonesian_fails(): void
    {
        $this->assertSame(
            AnalysisStatus::FAIL,
            $this->grade(['en' => 500, 'id' => 0, 'zh' => 300])->status,
        );
    }

    #[Test]
    public function indonesian_and_mandarin_without_english_fails(): void
    {
        $this->assertSame(
            AnalysisStatus::FAIL,
            $this->grade(['en' => 0, 'id' => 500, 'zh' => 300])->status,
        );
    }

    #[Test]
    public function all_languages_present_but_one_under_threshold_is_partial(): void
    {
        $analysis = $this->grade(['en' => 500, 'id' => 40, 'zh' => 300]);

        $this->assertSame(AnalysisStatus::PARTIAL, $analysis->status);

        $issue = $analysis->issues->firstWhere('issue_type', IssueType::LOW_LANGUAGE_COVERAGE);
        $this->assertNotNull($issue);
        $this->assertSame(LanguageCode::ID, $issue->language_code);
    }

    #[Test]
    public function a_single_chinese_word_is_not_enough_to_pass(): void
    {
        // The rule that stops a token translation counting as coverage
        // (CLAUDE.md 6).
        $analysis = $this->grade(['en' => 4000, 'id' => 3900, 'zh' => 3]);

        $this->assertSame(AnalysisStatus::PARTIAL, $analysis->status);
        $this->assertNotSame(AnalysisStatus::PASS, $analysis->status);
    }

    #[Test]
    public function a_document_with_no_extractable_text_needs_review_not_failure(): void
    {
        // A scanned PDF must never be reported as a translation failure
        // (CLAUDE.md 16).
        $analysis = $this->grade(['en' => 0, 'id' => 0, 'zh' => 0]);

        $this->assertSame(AnalysisStatus::REVIEW_REQUIRED, $analysis->status);
    }

    #[Test]
    public function an_ocr_required_issue_forces_review_even_with_some_text(): void
    {
        $analysis = $this->grade(
            ['en' => 120, 'id' => 110, 'zh' => 60],
            issues: [['type' => 'OCR_REQUIRED', 'description' => 'Very little extractable text.']],
        );

        $this->assertSame(AnalysisStatus::REVIEW_REQUIRED, $analysis->status);
    }

    #[Test]
    public function a_missing_language_raises_a_critical_issue(): void
    {
        $analysis = $this->grade(['en' => 500, 'id' => 500, 'zh' => 0]);

        $issue = $analysis->issues->firstWhere('issue_type', IssueType::LANGUAGE_MISSING);

        $this->assertNotNull($issue);
        $this->assertSame(LanguageCode::ZH, $issue->language_code);
        $this->assertSame('CRITICAL', $issue->severity->value);
    }

    #[Test]
    public function the_verdict_is_mirrored_onto_the_document(): void
    {
        $analysis = $this->grade(['en' => 500, 'id' => 500, 'zh' => 300]);
        $document = $analysis->document->refresh();

        $this->assertSame(AnalysisStatus::PASS, $document->analysis_status);
        $this->assertSame('100.00', (string) $document->compliance_score);
        $this->assertNotNull($document->last_analyzed_at);
    }

    #[Test]
    public function the_threshold_applied_is_stored_with_each_result(): void
    {
        // Without this, changing a threshold later would silently rewrite how
        // a historical result reads.
        $analysis = $this->grade(['en' => 500, 'id' => 500, 'zh' => 300]);

        $english = $analysis->languageResults->firstWhere('language_code', LanguageCode::EN);

        $this->assertSame(100, $english->threshold_applied);
        $this->assertTrue($english->meets_threshold);
    }

    #[Test]
    public function every_language_is_graded_on_characters_not_words(): void
    {
        // CLAUDE.md 6 states all three minimums in characters. A document with
        // plenty of English characters but few words - long compound terms,
        // tables of codes - must still pass.
        $version = $this->version();
        $analysis = $this->service->start($version);

        $result = $this->service->complete($analysis, AnalysisResult::fromArray([
            'languages' => [
                'en' => ['detected' => true, 'character_count' => 400, 'word_count' => 12, 'coverage' => 40],
                'id' => ['detected' => true, 'character_count' => 400, 'word_count' => 11, 'coverage' => 40],
                'zh' => ['detected' => true, 'character_count' => 200, 'word_count' => 0, 'coverage' => 20],
            ],
        ]));

        $this->assertSame(AnalysisStatus::PASS, $result->status);
    }

    #[Test]
    public function chinese_records_no_word_count(): void
    {
        $analysis = $this->grade(['en' => 500, 'id' => 500, 'zh' => 60]);

        $chinese = $analysis->languageResults->firstWhere('language_code', LanguageCode::ZH);

        $this->assertNull($chinese->word_count);
        $this->assertSame(60, $chinese->character_count);
        $this->assertTrue($chinese->meets_threshold);
    }

    #[Test]
    public function a_failed_analysis_records_an_error_without_leaking_internals(): void
    {
        $version = $this->version();
        $analysis = $this->service->start($version);

        $this->service->fail($analysis, 'The document could not be analysed.');

        $analysis->refresh();

        $this->assertSame(AnalysisStatus::ERROR, $analysis->status);
        $this->assertSame(AnalysisStatus::ERROR, $analysis->document->refresh()->analysis_status);
        $this->assertNotNull($analysis->issues->firstWhere('issue_type', IssueType::PARSER_ERROR));
    }

    /* ------------------------------------------------------------------ */

    /**
     * Grade a synthetic analyser response.
     *
     * @param  array<string, int>  $counts  meaningful text per language
     * @param  array<int, array<string, mixed>>  $issues
     */
    private function grade(array $counts, array $issues = []): DocumentAnalysis
    {
        $version = $this->version();
        $analysis = $this->service->start($version);

        $total = max(array_sum($counts), 1);

        $languages = [];

        foreach ($counts as $code => $count) {
            $languages[$code] = [
                'detected' => $count > 0,
                'character_count' => $count,
                'word_count' => (int) round($count / 5),
                'coverage' => round(($count / $total) * 100, 2),
                'confidence' => $count > 0 ? 0.97 : 0.0,
            ];
        }

        return $this->service->complete($analysis, AnalysisResult::fromArray([
            'languages' => $languages,
            'issues' => $issues,
            'analyzer_version' => 'test-1.0',
        ]));
    }

    private function version(): DocumentVersion
    {
        $document = Document::factory()->create();

        return DocumentVersion::factory()->for($document)->create();
    }
}
