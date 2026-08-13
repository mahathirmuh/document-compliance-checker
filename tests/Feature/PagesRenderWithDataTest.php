<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Enums\DocumentType;
use App\Enums\ScanStatus;
use App\Models\Document;
use App\Models\DocumentSource;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\Analyzer\DTO\AnalysisResult;
use App\Services\Audit\AuditLogger;
use App\Services\Documents\DocumentAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every page, rendered against a database that is not empty.
 *
 * This exists because the same gap shipped two separate 500s. The
 * authorisation tests hit every route, but always against empty tables - and
 * the queries that broke were ones that only run, or only join, once there is
 * something to join to. A page can be green across the whole suite and fail
 * for the first real user.
 *
 * So: one realistic dataset, every route, for a role that can see it. This is
 * a smoke test and deliberately shallow - it asserts the page renders at all.
 * What each page *says* is asserted in its own suite.
 */
class PagesRenderWithDataTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->superAdmin()->create();
        $this->seedRealisticLibrary();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function pages(): array
    {
        return [
            'dashboard' => ['dashboard'],
            'documents' => ['documents.index'],
            'upload' => ['documents.upload'],
            'sources' => ['sources.index'],
            'add source' => ['sources.create'],
            'settings' => ['settings.index'],
            'audit log' => ['audit.index'],
        ];
    }

    #[Test]
    #[DataProvider('pages')]
    public function it_renders(string $route): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route($route))
            ->assertOk();
    }

    #[Test]
    public function the_document_detail_page_renders(): void
    {
        $document = Document::query()->whereNotNull('last_analyzed_at')->firstOrFail();

        $this->actingAs($this->superAdmin)
            ->get(route('documents.show', $document))
            ->assertOk();
    }

    #[Test]
    public function the_compare_page_renders(): void
    {
        // Renders with the analyzer switched off, which is the phpunit.xml
        // default: the page must still come back 200 and say why there is
        // nothing to show.
        $document = Document::query()->whereNotNull('last_analyzed_at')->firstOrFail();

        $this->actingAs($this->superAdmin)
            ->get(route('documents.compare', $document))
            ->assertOk()
            ->assertSee('Nothing to compare');
    }

    #[Test]
    public function the_scan_history_page_renders(): void
    {
        $source = DocumentSource::query()->firstOrFail();

        $this->actingAs($this->superAdmin)
            ->get(route('sources.scans', $source))
            ->assertOk();
    }

    #[Test]
    public function the_source_edit_page_renders(): void
    {
        $source = DocumentSource::query()->where('type', '!=', 'UPLOAD')->firstOrFail();

        $this->actingAs($this->superAdmin)
            ->get(route('sources.edit', $source))
            ->assertOk();
    }

    /* ------------------------------------------------------------------ */

    /**
     * A small library with the shapes that have actually caused failures:
     * documents with analyses, documents without, several statuses, a scan
     * log, and an audit entry.
     */
    private function seedRealisticLibrary(): void
    {
        $source = DocumentSource::factory()->create(['name' => 'SOP Shared Folder']);

        $source->scanLogs()->create([
            'started_at' => now()->subMinutes(5),
            'completed_at' => now()->subMinutes(4),
            'status' => ScanStatus::COMPLETED_WITH_ERRORS,
            'total_found' => 12,
            'new_files' => 3,
            'error_count' => 1,
            'message' => '12 found · 3 new · 1 errors',
        ]);

        foreach (AnalysisStatus::cases() as $status) {
            Document::factory()->create([
                'document_source_id' => $source->id,
                'source_type' => $source->type,
                'analysis_status' => $status,
                'document_type' => DocumentType::SOP,
                'department' => 'Quality',
            ]);
        }

        // One document carried all the way through a real analysis, so the
        // latestOfMany self-join has something to join to.
        $analysed = Document::factory()->create([
            'document_source_id' => $source->id,
            'source_type' => $source->type,
            'department' => 'Environment',
        ]);

        $version = DocumentVersion::factory()->for($analysed)->create();
        $service = app(DocumentAnalysisService::class);

        $service->complete($service->start($version), AnalysisResult::fromArray([
            'languages' => [
                'en' => ['detected' => true, 'character_count' => 500, 'coverage' => 45],
                'id' => ['detected' => true, 'character_count' => 480, 'coverage' => 43],
                'zh' => ['detected' => true, 'character_count' => 120, 'coverage' => 12],
            ],
            'sections' => [[
                'name' => '1. Scope',
                'sequence' => 1,
                'total_characters' => 1100,
                'segment_count' => 4,
                'characters' => ['en' => 500, 'id' => 480, 'zh' => 120],
                'missing' => [],
                'short' => [],
                'evaluated' => true,
            ]],
            'issues' => [[
                'type' => 'SHORT_TRANSLATION',
                'severity' => 'INFO',
                'description' => 'Chinese may be incomplete.',
                'language' => 'zh',
                'section' => '1. Scope',
            ]],
            'rules' => [
                ['rule' => 'document_code', 'applicable' => true, 'passed' => true, 'finding_count' => 0],
                [
                    'rule' => 'font_color',
                    'applicable' => false,
                    'passed' => false,
                    'finding_count' => 0,
                    'skipped_reason' => 'Font colour cannot be read from a PDF file.',
                ],
            ],
            'analyzer_version' => '1.2.0',
        ]));

        app(AuditLogger::class)->log(
            AuditLogger::ACTION_SOURCE_CREATED,
            $source,
            newValues: ['name' => $source->name],
            user: $this->superAdmin,
        );
    }
}
