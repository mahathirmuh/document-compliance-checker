<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Enums\DocumentType;
use App\Enums\LanguageCode;
use App\Livewire\Documents\DocumentIndex;
use App\Models\Document;
use App\Models\DocumentSource;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\Analyzer\DTO\AnalysisResult;
use App\Services\Documents\DocumentAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The document list, rendered against real data.
 *
 * Written after the same gap produced two 500s in a row: the list and the
 * dashboard were only ever asserted against an empty table, where the queries
 * that broke were never actually exercised. The rule is the same here - seed
 * documents, and give them analyses, before rendering.
 *
 * The specific trap this guards is that Document::latestAnalysis() uses
 * latestOfMany(), which builds a self-join on document_analyses. Any
 * constrained eager load on it must name its columns with the table, or
 * PostgreSQL rejects "document_id" as ambiguous - and only once a document
 * actually has an analysis to join to.
 */
class DocumentIndexTest extends TestCase
{
    use RefreshDatabase;

    private User $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = User::factory()->documentController()->create();
    }

    #[Test]
    public function it_renders_documents_that_have_analyses(): void
    {
        $this->analysedDocument();

        $this->actingAs($this->controller)
            ->get(route('documents.index'))
            ->assertOk();
    }

    #[Test]
    public function it_shows_the_per_language_columns(): void
    {
        $document = $this->analysedDocument();

        $this->actingAs($this->controller)
            ->get(route('documents.index'))
            ->assertOk()
            ->assertSee($document->file_name)
            ->assertSee('Yes');
    }

    #[Test]
    public function it_renders_a_mix_of_analysed_and_pending_documents(): void
    {
        $this->analysedDocument();
        Document::factory()->count(3)->create(['analysis_status' => AnalysisStatus::PENDING]);

        $this->actingAs($this->controller)
            ->get(route('documents.index'))
            ->assertOk();
    }

    #[Test]
    public function every_sortable_column_produces_a_valid_query(): void
    {
        // Sorting is applied to the same query that carries the self-join, so
        // each column is exercised rather than assumed.
        $this->analysedDocument();

        foreach ([
            'document_code', 'document_title', 'file_name', 'document_type',
            'compliance_score', 'analysis_status', 'source_last_modified_at',
            'last_analyzed_at', 'updated_at',
        ] as $field) {
            Livewire::actingAs($this->controller)
                ->test(DocumentIndex::class)
                ->call('sortBy', $field)
                ->assertOk();
        }
    }

    #[Test]
    public function filtering_by_a_missing_language_runs_against_the_join(): void
    {
        $this->analysedDocument(chineseCharacters: 0);

        Livewire::actingAs($this->controller)
            ->test(DocumentIndex::class)
            ->set('missingLanguage', LanguageCode::ZH->value)
            ->assertOk()
            ->assertSee('SOP-QA-001.docx');
    }

    #[Test]
    public function searching_escapes_like_wildcards(): void
    {
        // A user pasting a path fragment containing % or _ must not silently
        // broaden their own search.
        Document::factory()->create(['file_name' => 'SOP-QA-001.docx']);

        Livewire::actingAs($this->controller)
            ->test(DocumentIndex::class)
            ->set('search', '%')
            ->assertOk()
            ->assertDontSee('SOP-QA-001.docx');
    }

    #[Test]
    public function it_still_renders_on_an_empty_installation(): void
    {
        $this->actingAs($this->controller)
            ->get(route('documents.index'))
            ->assertOk()
            ->assertSee('No documents match these filters.');
    }

    /* ------------------------------------------------------------------ */

    private function analysedDocument(int $chineseCharacters = 200): Document
    {
        $source = DocumentSource::factory()->create();

        $document = Document::factory()->create([
            'document_source_id' => $source->id,
            'source_type' => $source->type,
            'file_name' => 'SOP-QA-001.docx',
            'document_code' => 'SOP-QA-001',
            'document_type' => DocumentType::SOP,
        ]);

        $version = DocumentVersion::factory()->for($document)->create();
        $service = app(DocumentAnalysisService::class);

        $service->complete($service->start($version), AnalysisResult::fromArray([
            'languages' => [
                'en' => ['detected' => true, 'character_count' => 500, 'coverage' => 40],
                'id' => ['detected' => true, 'character_count' => 480, 'coverage' => 38],
                'zh' => [
                    'detected' => $chineseCharacters > 0,
                    'character_count' => $chineseCharacters,
                    'coverage' => 22,
                ],
            ],
            'analyzer_version' => '1.2.0',
        ]));

        return $document->refresh();
    }
}
