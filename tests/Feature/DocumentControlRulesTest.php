<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IssueType;
use App\Enums\LanguageCode;
use App\Models\Document;
use App\Models\DocumentAnalysis;
use App\Models\DocumentVersion;
use App\Services\Analyzer\DTO\AnalysisResult;
use App\Services\Documents\DocumentAnalysisService;
use App\Services\Settings\RuleSettingsService;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Document Control rules as seen from Laravel (CLAUDE.md 27 Phase 5).
 *
 * The rules themselves are tested in the analyzer. What matters here is the
 * wiring: which rules are sent, what comes back, and what is done with it.
 */
class DocumentControlRulesTest extends TestCase
{
    use RefreshDatabase;

    private RuleSettingsService $rules;

    private DocumentAnalysisService $analyses;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rules = app(RuleSettingsService::class);
        $this->analyses = app(DocumentAnalysisService::class);

        $settings = app(SettingsService::class);
        $settings->set('min_chars_en', 100);
        $settings->set('min_chars_id', 100);
        $settings->set('min_chars_zh', 50);
    }

    #[Test]
    public function every_rule_is_off_by_default(): void
    {
        // A rule is a statement about how this organisation's documents are
        // supposed to look. That is a Document Controller's decision, not a
        // default to impose.
        $this->assertSame([], $this->rules->payload());

        foreach (array_keys(RuleSettingsService::RULES) as $rule) {
            $this->assertFalse($this->rules->isEnabled($rule), "{$rule} should default to off");
        }
    }

    #[Test]
    public function enabling_a_rule_puts_it_in_the_analyzer_payload(): void
    {
        $this->rules->setEnabled('document_code', true);

        $payload = $this->rules->payload();

        $this->assertArrayHasKey('document_code', $payload);
        $this->assertTrue($payload['document_code']['enabled']);
        $this->assertArrayNotHasKey('font_color', $payload);
    }

    #[Test]
    public function null_patterns_are_not_sent(): void
    {
        // A null means "use the analyzer's default"; sending it would
        // override that default with nothing.
        $this->rules->setEnabled('document_code', true);

        $payload = $this->rules->payload();

        $this->assertArrayNotHasKey('code_pattern', $payload['document_code']);
    }

    #[Test]
    public function configured_options_are_passed_through(): void
    {
        config()->set('documents.rules.font_color.allowed', ['000000', '1F497D']);
        $this->rules->setEnabled('font_color', true);

        $payload = $this->rules->payload();

        $this->assertSame(['000000', '1F497D'], $payload['font_color']['allowed']);
    }

    #[Test]
    public function an_unknown_rule_cannot_be_enabled(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->rules->setEnabled('teleportation', true);
    }

    #[Test]
    public function the_numeric_consistency_rule_can_be_switched_on(): void
    {
        // A rule the analyzer knows about but Laravel has no setting for can
        // never be enabled, so both sides have to be edited deliberately.
        $this->rules->setEnabled('numeric_consistency', true);

        $payload = $this->rules->payload();

        $this->assertArrayHasKey('numeric_consistency', $payload);
        $this->assertTrue($payload['numeric_consistency']['enabled']);
    }

    #[Test]
    public function a_numeric_mismatch_is_stored_as_a_located_issue(): void
    {
        // The defect this rule exists for: a dose that reads 5 ppm in one
        // language and 500 ppm in another. Every other check passes it.
        $analysis = $this->analyse(issues: [
            [
                'type' => 'NUMERIC_MISMATCH',
                'severity' => 'WARNING',
                'description' => "Section '3. Dosing': 5 ppm appears in the English text but not in the Indonesian.",
                'language' => 'id',
                'section' => '3. Dosing',
                'page' => 4,
                'metadata' => ['reference_language' => 'en', 'missing' => ['5 ppm']],
            ],
        ]);

        $issue = $analysis->issues->firstWhere('issue_type', IssueType::NUMERIC_MISMATCH);

        $this->assertNotNull($issue);
        $this->assertSame('3. Dosing', $issue->section_name);
        $this->assertSame(4, $issue->page_number);
        $this->assertSame(LanguageCode::ID, $issue->language_code);
        $this->assertSame('WARNING', $issue->severity->value);
    }

    #[Test]
    public function rule_outcomes_are_stored_with_the_analysis(): void
    {
        $analysis = $this->analyse(rules: [
            ['rule' => 'document_code', 'applicable' => true, 'passed' => true, 'finding_count' => 0],
            [
                'rule' => 'font_color',
                'applicable' => false,
                'passed' => false,
                'finding_count' => 0,
                'skipped_reason' => 'Font colour cannot be read from a PDF file.',
            ],
        ]);

        $stored = collect($analysis->rule_results)->keyBy('rule');

        $this->assertTrue($stored['document_code']['passed']);

        // The distinction that matters: not checked must stay visibly
        // different from checked and clean.
        $this->assertFalse($stored['font_color']['applicable']);
        $this->assertFalse($stored['font_color']['passed']);
        $this->assertStringContainsString('PDF', $stored['font_color']['skipped_reason']);
    }

    #[Test]
    public function rule_findings_become_located_issues(): void
    {
        $analysis = $this->analyse(issues: [[
            'type' => 'WRONG_LANGUAGE_ORDER',
            'severity' => 'WARNING',
            'description' => "Section '2. Responsibility' presents Chinese before English.",
            'section' => '2. Responsibility',
            'page' => 4,
        ]]);

        $issue = $analysis->issues->firstWhere('issue_type', IssueType::WRONG_LANGUAGE_ORDER);

        $this->assertNotNull($issue);
        $this->assertSame('2. Responsibility', $issue->section_name);
        $this->assertSame(4, $issue->page_number);
    }

    #[Test]
    public function the_extracted_code_and_revision_fill_in_a_blank_document(): void
    {
        $document = Document::factory()->create([
            'document_code' => null,
            'current_revision' => null,
        ]);

        $this->analyse(
            document: $document,
            metadata: ['document_code' => 'MTI-ENV-EVM-SOP-002', 'revision' => '006'],
        );

        $document->refresh();

        $this->assertSame('MTI-ENV-EVM-SOP-002', $document->document_code);
        $this->assertSame('006', $document->current_revision);
    }

    #[Test]
    public function extraction_never_overwrites_a_value_a_person_entered(): void
    {
        // Analyzer output is advisory, and an OCR-derived reading is exactly
        // the kind of value a Document Controller would be right to override
        // (CLAUDE.md 33).
        $document = Document::factory()->create([
            'document_code' => 'CORRECTED-BY-HAND',
            'current_revision' => 'Rev A',
        ]);

        $this->analyse(
            document: $document,
            metadata: ['document_code' => 'OCR-MISREAD-001', 'revision' => '999'],
        );

        $document->refresh();

        $this->assertSame('CORRECTED-BY-HAND', $document->document_code);
        $this->assertSame('Rev A', $document->current_revision);
    }

    #[Test]
    public function an_analyzer_that_reports_no_rules_stores_nothing(): void
    {
        // Analyzer 1.1 and earlier predate the rules field.
        $analysis = $this->analyse();

        $this->assertNull($analysis->rule_results);
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @param  array<int, array<string, mixed>>  $issues
     * @param  array<string, string>  $metadata
     */
    private function analyse(
        array $rules = [],
        array $issues = [],
        array $metadata = [],
        ?Document $document = null,
    ): DocumentAnalysis {
        $version = DocumentVersion::factory()
            ->for($document ?? Document::factory())
            ->create();

        $analysis = $this->analyses->start($version);

        return $this->analyses->complete($analysis, AnalysisResult::fromArray([
            'languages' => [
                'en' => ['detected' => true, 'character_count' => 500, 'coverage' => 40],
                'id' => ['detected' => true, 'character_count' => 480, 'coverage' => 38],
                'zh' => ['detected' => true, 'character_count' => 200, 'coverage' => 22],
            ],
            'issues' => $issues,
            'rules' => $rules,
            'metadata' => $metadata,
            'analyzer_version' => '1.2.0',
        ]));
    }
}
