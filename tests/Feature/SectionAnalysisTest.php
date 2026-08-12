<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IssueType;
use App\Enums\LanguageCode;
use App\Enums\UserRole;
use App\Models\Document;
use App\Models\DocumentAnalysis;
use App\Models\DocumentSection;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\Analyzer\DTO\AnalysisResult;
use App\Services\Documents\DocumentAnalysisService;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Storing and surfacing per-section coverage (CLAUDE.md 7, 21, 27 Phase 4).
 */
class SectionAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private DocumentAnalysisService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(DocumentAnalysisService::class);

        $settings = app(SettingsService::class);
        $settings->set('min_chars_en', 100);
        $settings->set('min_chars_id', 100);
        $settings->set('min_chars_zh', 50);
    }

    #[Test]
    public function sections_are_stored_in_reading_order(): void
    {
        $analysis = $this->analyse([
            $this->section('1. Scope', 1, ['en' => 400, 'id' => 380, 'zh' => 130]),
            $this->section('2. Responsibility', 2, ['en' => 300, 'id' => 290, 'zh' => 0], missing: ['zh']),
        ]);

        $sections = $analysis->sections;

        $this->assertCount(2, $sections);
        $this->assertSame(['1. Scope', '2. Responsibility'], $sections->pluck('name')->all());
        $this->assertSame([1, 2], $sections->pluck('sequence')->all());
    }

    #[Test]
    public function a_sections_missing_language_is_queryable(): void
    {
        // The point of a table rather than a JSON blob: "show me every section
        // still missing Mandarin" has to be answerable across the library.
        $this->analyse([
            $this->section('1. Scope', 1, ['en' => 400, 'id' => 380, 'zh' => 130]),
            $this->section('2. Responsibility', 2, ['en' => 300, 'id' => 290, 'zh' => 0], missing: ['zh']),
        ]);

        $incomplete = DocumentSection::query()->withFindings()->get();

        $this->assertCount(1, $incomplete);
        $this->assertSame('2. Responsibility', $incomplete->first()->name);
        $this->assertTrue($incomplete->first()->isMissing(LanguageCode::ZH));
        $this->assertFalse($incomplete->first()->isMissing(LanguageCode::EN));
    }

    #[Test]
    public function per_language_character_counts_are_stored_per_section(): void
    {
        $analysis = $this->analyse([
            $this->section('1. Scope', 1, ['en' => 400, 'id' => 380, 'zh' => 130]),
        ]);

        $section = $analysis->sections->first();

        $this->assertSame(400, $section->charactersFor(LanguageCode::EN));
        $this->assertSame(380, $section->charactersFor(LanguageCode::ID));
        $this->assertSame(130, $section->charactersFor(LanguageCode::ZH));
    }

    #[Test]
    public function a_short_translation_is_recorded_separately_from_a_missing_one(): void
    {
        $analysis = $this->analyse([
            $this->section('1. Scope', 1, ['en' => 900, 'id' => 850, 'zh' => 20], short: ['zh']),
        ]);

        $section = $analysis->sections->first();

        $this->assertFalse($section->isMissing(LanguageCode::ZH));
        $this->assertTrue($section->isShort(LanguageCode::ZH));
        $this->assertFalse($section->isComplete());
    }

    #[Test]
    public function a_section_too_small_to_evaluate_is_recorded_but_reports_nothing(): void
    {
        $analysis = $this->analyse([
            $this->section('Cover page', 1, ['en' => 20, 'id' => 0, 'zh' => 0], evaluated: false),
        ]);

        $section = $analysis->sections->first();

        $this->assertFalse($section->evaluated);
        $this->assertSame(0, DocumentSection::query()->withFindings()->count());
    }

    #[Test]
    public function located_issues_from_the_analyzer_are_stored_with_their_section(): void
    {
        $analysis = $this->analyse(
            sections: [$this->section('2. Responsibility', 1, ['en' => 300, 'id' => 290, 'zh' => 0], missing: ['zh'])],
            issues: [[
                'type' => 'MISSING_SECTION_TRANSLATION',
                'severity' => 'WARNING',
                'description' => "Section '2. Responsibility' has no Chinese text.",
                'language' => 'zh',
                'section' => '2. Responsibility',
                'page' => 4,
            ]],
        );

        $issue = $analysis->issues->firstWhere('issue_type', IssueType::MISSING_SECTION_TRANSLATION);

        $this->assertNotNull($issue);
        $this->assertSame('2. Responsibility', $issue->section_name);
        $this->assertSame(4, $issue->page_number);
        $this->assertSame(LanguageCode::ZH, $issue->language_code);
        $this->assertStringContainsString('Page 4', $issue->displayLocation());
    }

    #[Test]
    public function a_short_translation_issue_is_advisory_not_an_error(): void
    {
        // A legitimately terse translation looks identical from here, so this
        // must not read as a compliance failure (CLAUDE.md 33).
        $analysis = $this->analyse(
            sections: [$this->section('1. Scope', 1, ['en' => 900, 'id' => 850, 'zh' => 20], short: ['zh'])],
            issues: [[
                'type' => 'SHORT_TRANSLATION',
                'description' => 'The translation may be incomplete.',
                'language' => 'zh',
                'section' => '1. Scope',
            ]],
        );

        $issue = $analysis->issues->firstWhere('issue_type', IssueType::SHORT_TRANSLATION);

        $this->assertNotNull($issue);
        $this->assertSame('INFO', $issue->severity->value);
    }

    #[Test]
    public function an_analyzer_that_reports_no_sections_still_works(): void
    {
        // Analyzer 1.0 predates the sections field; a document analysed by it
        // must still render, just without the breakdown.
        $analysis = $this->analyse([]);

        $this->assertCount(0, $analysis->sections);
        $this->assertNotNull($analysis->status);
    }

    #[Test]
    public function the_detail_page_shows_the_section_breakdown(): void
    {
        $analysis = $this->analyse([
            $this->section('1. Scope', 1, ['en' => 400, 'id' => 380, 'zh' => 130]),
            $this->section('2. Responsibility', 2, ['en' => 300, 'id' => 290, 'zh' => 0], missing: ['zh']),
        ]);

        $this->actingAs(User::factory()->role(UserRole::DOCUMENT_CONTROLLER)->create())
            ->get(route('documents.show', $analysis->document_id))
            ->assertOk()
            ->assertSee('Coverage by section')
            ->assertSee('2. Responsibility')
            ->assertSee('Mandarin missing');
    }

    #[Test]
    public function re_analysing_does_not_duplicate_sections(): void
    {
        // Sections belong to an analysis, not a document, so a re-run gets its
        // own set and the previous one stays intact with its history.
        $first = $this->analyse([$this->section('1. Scope', 1, ['en' => 400, 'id' => 380, 'zh' => 130])]);
        $second = $this->analyse([$this->section('1. Scope', 1, ['en' => 400, 'id' => 380, 'zh' => 130])]);

        $this->assertCount(1, $first->sections);
        $this->assertCount(1, $second->sections);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, DocumentSection::count());
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @param  array<int, array<string, mixed>>  $issues
     */
    private function analyse(array $sections, array $issues = []): DocumentAnalysis
    {
        $version = DocumentVersion::factory()->for(Document::factory())->create();
        $analysis = $this->service->start($version);

        return $this->service->complete($analysis, AnalysisResult::fromArray([
            'languages' => [
                'en' => ['detected' => true, 'character_count' => 700, 'coverage' => 40],
                'id' => ['detected' => true, 'character_count' => 670, 'coverage' => 38],
                'zh' => ['detected' => true, 'character_count' => 130, 'coverage' => 22],
            ],
            'sections' => $sections,
            'issues' => $issues,
            'analyzer_version' => '1.1.0',
        ]));
    }

    /**
     * @param  array<string, int>  $characters
     * @param  array<int, string>  $missing
     * @param  array<int, string>  $short
     * @return array<string, mixed>
     */
    private function section(
        string $name,
        int $sequence,
        array $characters,
        array $missing = [],
        array $short = [],
        bool $evaluated = true,
    ): array {
        return [
            'name' => $name,
            'sequence' => $sequence,
            'total_characters' => array_sum($characters),
            'segment_count' => 3,
            'characters' => $characters,
            'missing' => $missing,
            'short' => $short,
            'evaluated' => $evaluated,
        ];
    }
}
