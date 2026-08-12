<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Enums\DocumentType;
use App\Enums\LanguageCode;
use App\Models\Document;
use App\Models\DocumentSource;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\Analyzer\DTO\AnalysisResult;
use App\Services\Documents\DocumentAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The dashboard, rendered against a database that actually has documents.
 *
 * This suite exists because of a bug it would have caught. The status counter
 * used pluck(DB::raw('count(*)'), ...), which reads the value off the result
 * row by property name - and "count(*)" is not a usable property name. It
 * threw a 500 on every dashboard load.
 *
 * Every existing test rendered the dashboard against an empty table, where
 * pluck returns an empty collection without ever touching that property. The
 * page passed its tests and failed for the first real user. So the rule here
 * is: seed data, then render.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->viewer = User::factory()->viewer()->create();
    }

    #[Test]
    public function it_renders_with_documents_in_every_status(): void
    {
        $source = DocumentSource::factory()->create(['name' => 'SOP Shared Folder']);

        foreach (AnalysisStatus::cases() as $status) {
            Document::factory()->count(2)->create([
                'document_source_id' => $source->id,
                'source_type' => $source->type,
                'analysis_status' => $status,
                'document_type' => DocumentType::SOP,
                'department' => 'Quality',
            ]);
        }

        $this->actingAs($this->viewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Overall compliance')
            ->assertSee('SOP Shared Folder')
            ->assertSee('Quality');
    }

    #[Test]
    public function the_status_counters_show_real_totals(): void
    {
        Document::factory()->count(3)->create(['analysis_status' => AnalysisStatus::PASS]);
        Document::factory()->count(1)->create(['analysis_status' => AnalysisStatus::FAIL]);

        $this->actingAs($this->viewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Total', '4']);
    }

    #[Test]
    public function compliance_ignores_documents_that_have_no_verdict_yet(): void
    {
        // Counting PENDING would make the figure improve every time a worker
        // fell behind, which is exactly backwards.
        Document::factory()->count(1)->create(['analysis_status' => AnalysisStatus::PASS]);
        Document::factory()->count(1)->create(['analysis_status' => AnalysisStatus::FAIL]);
        Document::factory()->count(50)->create(['analysis_status' => AnalysisStatus::PENDING]);

        // One pass out of two graded documents; the 50 pending are excluded.
        // Rendered as "50%" - PHP drops the trailing .0 on conversion.
        $this->actingAs($this->viewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('50%');
    }

    #[Test]
    public function it_renders_with_analyses_and_language_results(): void
    {
        $version = DocumentVersion::factory()->for(Document::factory())->create();
        $service = app(DocumentAnalysisService::class);

        $analysis = $service->start($version);
        $service->complete($analysis, AnalysisResult::fromArray([
            'languages' => [
                'en' => ['detected' => true, 'character_count' => 500, 'coverage' => 40],
                'id' => ['detected' => true, 'character_count' => 480, 'coverage' => 38],
                'zh' => ['detected' => true, 'character_count' => 200, 'coverage' => 22],
            ],
            'analyzer_version' => '1.2.0',
        ]));

        $this->actingAs($this->viewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Language compliance')
            ->assertSee(LanguageCode::ZH->label());
    }

    #[Test]
    public function inactive_documents_are_left_out_of_the_counts(): void
    {
        Document::factory()->count(2)->create(['analysis_status' => AnalysisStatus::PASS]);
        Document::factory()->count(5)->inactive()->create(['analysis_status' => AnalysisStatus::PASS]);

        $this->actingAs($this->viewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('>7<', escape: false);
    }

    #[Test]
    public function it_still_renders_on_a_brand_new_installation(): void
    {
        $this->actingAs($this->viewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Overall compliance');
    }
}
