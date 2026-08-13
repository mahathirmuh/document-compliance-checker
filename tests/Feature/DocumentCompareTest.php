<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LanguageCode;
use App\Livewire\Documents\DocumentCompare;
use App\Models\Document;
use App\Models\DocumentSource;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\Analyzer\DTO\DocumentExtraction;
use App\Services\Documents\DocumentComparisonService;
use App\Services\Files\FileHashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The side-by-side view (CLAUDE.md 21, 33).
 *
 * Answers the question the coverage table cannot: which passage is the
 * translation of which. The application makes no claim about meaning - it
 * pairs the text up and puts a person in front of it.
 */
class DocumentCompareTest extends TestCase
{
    use RefreshDatabase;

    private const CHINESE = '本程序适用于全体员工。';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'doccompare-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);

        config()->set('documents.analyzer.enabled', true);
        config()->set('documents.analyzer.base_url', 'http://analyzer.test');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->root);

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* The DTO */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function it_reads_the_lower_case_language_keys_the_analyzer_sends(): void
    {
        // The analyzer speaks "en"; LanguageCode is "EN". Getting this wrong
        // produces three silently empty columns rather than an error.
        $extraction = DocumentExtraction::fromArray($this->payload());

        $section = $extraction->sections[0];

        $this->assertSame(['This procedure applies to all employees.'], $section->segmentsFor(LanguageCode::EN));
        $this->assertSame(['Prosedur ini berlaku untuk seluruh karyawan.'], $section->segmentsFor(LanguageCode::ID));
        $this->assertSame([self::CHINESE], $section->segmentsFor(LanguageCode::ZH));
    }

    #[Test]
    public function a_missing_language_is_normalised_to_the_enum_casing(): void
    {
        $extraction = DocumentExtraction::fromArray($this->payload(missing: ['id']));

        $this->assertSame(['ID'], $extraction->sections[0]->missing);
        $this->assertTrue($extraction->sections[0]->isMissing(LanguageCode::ID));
        $this->assertFalse($extraction->sections[0]->isMissing(LanguageCode::EN));
    }

    #[Test]
    public function a_section_with_no_text_at_all_is_dropped(): void
    {
        $extraction = DocumentExtraction::fromArray([
            'parser' => 'docx',
            'analyzer_version' => '1.3.0',
            'sections' => [
                ['name' => 'Empty', 'sequence' => 1, 'blocks' => [], 'unassigned' => [], 'missing' => []],
            ],
        ]);

        $this->assertTrue($extraction->isEmpty());
    }

    #[Test]
    public function the_row_count_follows_the_longest_language(): void
    {
        // The columns are not a paragraph-by-paragraph correspondence, so the
        // shorter ones simply run out rather than being padded.
        $extraction = DocumentExtraction::fromArray($this->payload(
            english: ['One.', 'Two.', 'Three.'],
            indonesian: ['Satu.'],
        ));

        $this->assertSame(3, $extraction->sections[0]->rowCount());
    }

    /* ------------------------------------------------------------------ */
    /* The page */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_first_render_fetches_nothing_and_says_what_it_is_doing(): void
    {
        // Reading a scanned document means OCR. A real 13-page scan took 83
        // seconds, and doing that inside the initial render is a browser that
        // looks like it has hung.
        Http::fake(['*/api/v1/extract' => Http::response($this->payload())]);

        Livewire::actingAs($this->viewer())
            ->test(DocumentCompare::class, ['document' => $this->localDocument()])
            ->assertSee('Reading the document')
            ->assertDontSee('This procedure applies to all employees.');

        Http::assertNothingSent();
    }

    #[Test]
    public function it_shows_the_three_languages_side_by_side(): void
    {
        Http::fake(['*/api/v1/extract' => Http::response($this->payload())]);

        Livewire::actingAs($this->viewer())
            ->test(DocumentCompare::class, ['document' => $this->localDocument()])
            ->call('load')
            ->assertOk()
            ->assertSee('This procedure applies to all employees.')
            ->assertSee('Prosedur ini berlaku untuk seluruh karyawan.')
            ->assertSee(self::CHINESE);
    }

    #[Test]
    public function it_flags_a_section_that_is_missing_a_language(): void
    {
        Http::fake(['*/api/v1/extract' => Http::response($this->payload(missing: ['id']))]);

        Livewire::actingAs($this->viewer())
            ->test(DocumentCompare::class, ['document' => $this->localDocument()])
            ->call('load')
            ->assertSee('No Indonesian');
    }

    #[Test]
    public function text_that_belongs_to_no_language_is_shown_rather_than_hidden(): void
    {
        // A reviewer reading three columns assumes they are seeing the whole
        // section. Quietly dropping a line is how a gap goes unnoticed.
        Http::fake(['*/api/v1/extract' => Http::response($this->payload(unassigned: ['P-101 / TK-204']))]);

        Livewire::actingAs($this->viewer())
            ->test(DocumentCompare::class, ['document' => $this->localDocument()])
            ->call('load')
            ->assertSee('P-101 / TK-204')
            ->assertSee('Not attributable to a language');
    }

    #[Test]
    public function a_truncated_document_says_so(): void
    {
        Http::fake(['*/api/v1/extract' => Http::response($this->payload(truncated: true))]);

        Livewire::actingAs($this->viewer())
            ->test(DocumentCompare::class, ['document' => $this->localDocument()])
            ->call('load')
            ->assertSee('You are not looking at all of it');
    }

    #[Test]
    public function the_gaps_filter_hides_complete_sections(): void
    {
        Http::fake(['*/api/v1/extract' => Http::response($this->payload())]);

        Livewire::actingAs($this->viewer())
            ->test(DocumentCompare::class, ['document' => $this->localDocument()])
            ->call('load')
            ->assertSee('This procedure applies to all employees.')
            ->set('onlyGaps', true)
            ->assertDontSee('This procedure applies to all employees.')
            ->assertSee('Every section contains all three languages.');
    }

    #[Test]
    public function it_explains_itself_when_the_analyzer_is_switched_off(): void
    {
        config()->set('documents.analyzer.enabled', false);

        Livewire::actingAs($this->viewer())
            ->test(DocumentCompare::class, ['document' => $this->localDocument()])
            ->call('load')
            ->assertSee('The document analyzer is switched off');
    }

    #[Test]
    public function an_analyzer_outage_does_not_break_the_page(): void
    {
        // Failing to render a review aid must never take down the document.
        Http::fake(['*/api/v1/extract' => Http::response('nope', 500)]);

        Livewire::actingAs($this->viewer())
            ->test(DocumentCompare::class, ['document' => $this->localDocument()])
            ->call('load')
            ->assertOk()
            ->assertSee('The file could not be read from its source');
    }

    #[Test]
    public function a_document_with_no_version_says_so(): void
    {
        $document = Document::factory()->create();

        Livewire::actingAs($this->viewer())
            ->test(DocumentCompare::class, ['document' => $document])
            ->call('load')
            ->assertSee('no stored version');
    }

    /* ------------------------------------------------------------------ */
    /* Caching and storage */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_file_is_read_once_and_then_served_from_cache(): void
    {
        Http::fake(['*/api/v1/extract' => Http::response($this->payload())]);

        $document = $this->localDocument();
        $service = app(DocumentComparisonService::class);

        $service->extract($document);
        $service->extract($document);

        Http::assertSentCount(1);
    }

    #[Test]
    public function a_cached_read_survives_a_store_that_serialises(): void
    {
        // This is the one that matters, and its absence let a real defect
        // ship. The suite runs on the `array` store, which hands objects
        // straight back without serialising - so caching the DTO passed here
        // and failed in production, where the database store serialised it
        // and read it back as __PHP_Incomplete_Class. The first view of a
        // document worked; every one for the next fifteen minutes reported it
        // as unreadable.
        config()->set('cache.default', 'database');

        Http::fake(['*/api/v1/extract' => Http::response($this->payload())]);

        $document = $this->localDocument();
        $service = app(DocumentComparisonService::class);

        $first = $service->extract($document);
        $second = $service->extract($document);

        Http::assertSentCount(1);

        $this->assertNotNull($second, 'The cached read must return a usable result, not null.');
        $this->assertSame($first->sectionCount(), $second->sectionCount());
        $this->assertSame(
            ['This procedure applies to all employees.'],
            $second->sections[0]->segmentsFor(LanguageCode::EN),
        );
    }

    #[Test]
    public function the_cache_holds_plain_data_rather_than_an_object(): void
    {
        // Objects in a cache are a standing trap: they break on a serialising
        // driver, and a later change to the DTO's shape turns every entry
        // written before the deploy into a failure.
        config()->set('cache.default', 'database');

        Http::fake(['*/api/v1/extract' => Http::response($this->payload())]);

        app(DocumentComparisonService::class)->extract($this->localDocument());

        $stored = DB::table('cache')->where('key', 'like', '%doccheck.compare%')->value('value');

        $this->assertNotNull($stored);
        $this->assertStringNotContainsString('DocumentExtraction', (string) $stored);
    }

    #[Test]
    public function extraction_is_given_a_longer_budget_than_analysis(): void
    {
        // OCR is minutes, not seconds. Sharing the 120-second analysis
        // timeout produced "the file could not be read" on a document that
        // was perfectly readable, just slow.
        $this->assertGreaterThan(
            (int) config('documents.analyzer.timeout'),
            (int) config('documents.analyzer.extract_timeout'),
        );
    }

    #[Test]
    public function refreshing_reads_the_file_again(): void
    {
        Http::fake(['*/api/v1/extract' => Http::response($this->payload())]);

        Livewire::actingAs($this->viewer())
            ->test(DocumentCompare::class, ['document' => $this->localDocument()])
            ->call('load')
            ->call('refresh')
            ->assertSee('read again from its source');

        $this->assertGreaterThan(1, count(Http::recorded()));
    }

    #[Test]
    public function no_document_text_is_written_to_the_database(): void
    {
        // The application holds metadata and measurements, never a second
        // copy of a controlled document (CLAUDE.md 8.3, 12).
        Http::fake(['*/api/v1/extract' => Http::response($this->payload())]);

        $document = $this->localDocument();

        $extraction = app(DocumentComparisonService::class)->extract($document);

        // The text really did flow through, so the assertions below are not
        // passing merely because nothing happened.
        $this->assertSame(
            ['This procedure applies to all employees.'],
            $extraction->sections[0]->segmentsFor(LanguageCode::EN),
        );

        // Nothing was written anywhere. Sections are recorded by an analysis,
        // never by a read for review.
        $this->assertSame(0, DB::table('document_sections')->count());
        $this->assertSame(0, DB::table('document_analyses')->count());

        // And no column on the document itself picked the text up.
        $this->assertStringNotContainsString(
            'applies to all employees',
            json_encode($document->fresh()->getAttributes(), JSON_THROW_ON_ERROR),
        );
    }

    /* ------------------------------------------------------------------ */
    /* Authorisation */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_page_is_behind_authentication(): void
    {
        $this->get(route('documents.compare', $this->localDocument()))
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function a_signed_in_viewer_may_open_it(): void
    {
        Http::fake(['*/api/v1/extract' => Http::response($this->payload())]);

        $this->actingAs($this->viewer())
            ->get(route('documents.compare', $this->localDocument()))
            ->assertOk();
    }

    /* ------------------------------------------------------------------ */

    private function viewer(): User
    {
        return User::factory()->create();
    }

    /**
     * A document backed by a real file inside a real local source, so the
     * source adapter resolves a path the way it would in production.
     */
    private function localDocument(): Document
    {
        $path = $this->root.DIRECTORY_SEPARATOR.'procedure.txt';
        file_put_contents($path, 'placeholder - the analyzer response is faked');

        $source = DocumentSource::factory()->atPath($this->root)->create();

        $document = Document::factory()->for($source, 'source')->create([
            'source_type' => $source->type,
            'source_item_id' => app(FileHashService::class)->sourceItemId($path, $this->root),
            'file_name' => 'procedure.txt',
            'extension' => 'txt',
        ]);

        DocumentVersion::factory()->for($document)->create(['version_number' => 1]);

        return $document->refresh();
    }

    /**
     * @param  array<int, string>|null  $english
     * @param  array<int, string>|null  $indonesian
     * @param  array<int, string>  $missing
     * @param  array<int, string>  $unassigned
     * @return array<string, mixed>
     */
    private function payload(
        ?array $english = null,
        ?array $indonesian = null,
        array $missing = [],
        array $unassigned = [],
        bool $truncated = false,
    ): array {
        $english ??= ['This procedure applies to all employees.'];
        $indonesian ??= ['Prosedur ini berlaku untuk seluruh karyawan.'];

        return [
            'parser' => 'txt',
            'analyzer_version' => '1.3.0',
            'truncated' => $truncated,
            'page_count' => null,
            'duration_ms' => 12,
            'sections' => [
                [
                    'name' => '1. Scope',
                    'sequence' => 1,
                    'page' => null,
                    'blocks' => [
                        'en' => ['characters' => 34, 'segments' => $english],
                        'id' => ['characters' => 38, 'segments' => $indonesian],
                        'zh' => ['characters' => 10, 'segments' => [self::CHINESE]],
                    ],
                    'unassigned' => $unassigned,
                    'missing' => $missing,
                ],
            ],
        ];
    }
}
