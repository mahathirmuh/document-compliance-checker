<?php

declare(strict_types=1);

namespace Tests\Feature\Graph;

use App\Enums\DocumentSourceType;
use App\Enums\ScanStatus;
use App\Jobs\AnalyzeDocumentJob;
use App\Models\Document;
use App\Models\DocumentSource;
use App\Services\DocumentSources\DocumentSourceFactory;
use App\Services\Scanning\SourceScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Scanning a SharePoint library end to end, against a faked Graph.
 *
 * The interesting behaviour is change detection: SharePoint issues a cTag
 * that moves when content changes, so a repeat scan must queue nothing, and a
 * moved cTag must create a version - without ever downloading the file
 * (CLAUDE.md 9, 27 Phase 3).
 */
class SharePointScanTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_URL = 'https://login.microsoftonline.com/*';

    private DocumentSource $source;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Cache::flush();

        config()->set('microsoft_graph.tenant_id', 'tenant-123');
        config()->set('microsoft_graph.client_id', 'client-abc');
        config()->set('microsoft_graph.client_secret', 'dev-secret');
        config()->set('microsoft_graph.certificate.path', null);
        config()->set('microsoft_graph.max_retries', 2);
        config()->set('microsoft_graph.retry_base_delay_ms', 1);

        $this->source = DocumentSource::factory()->sharePoint()->create([
            'name' => 'Corporate SharePoint',
        ]);
        $this->source->update([
            'configuration' => [
                'site_id' => 'site-1',
                'drive_id' => 'drive-1',
                'folder_path' => 'General/SOP',
            ],
        ]);
    }

    #[Test]
    public function it_indexes_files_from_a_library(): void
    {
        $this->fakeGraph([
            $this->folderChildren([
                $this->fileItem('item-1', 'SOP-QA-001.docx', 'ctag-a', 2048),
                $this->fileItem('item-2', 'Policy-HR-002.pdf', 'ctag-b', 4096),
            ]),
        ]);

        $log = $this->scan();

        $this->assertSame(ScanStatus::COMPLETED, $log->status);
        $this->assertSame(2, $log->total_found);
        $this->assertSame(2, $log->new_files);
        $this->assertSame(2, $log->queued_for_analysis);

        $document = Document::where('source_item_id', 'item-1')->firstOrFail();

        $this->assertSame(DocumentSourceType::SHAREPOINT, $document->source_type);
        $this->assertSame('SOP-QA-001.docx', $document->file_name);
        $this->assertSame('ctag-a', $document->source_etag);

        // Never downloaded, so never hashed - the token is the evidence.
        $this->assertNull($document->file_hash);

        Queue::assertPushed(AnalyzeDocumentJob::class, 2);
    }

    #[Test]
    public function it_uses_the_graph_item_id_as_the_document_identity(): void
    {
        // A driveItem id survives renames and moves, which a path does not.
        $this->fakeGraph([
            $this->folderChildren([$this->fileItem('item-1', 'SOP-QA-001.docx', 'ctag-a')]),
        ]);

        $this->scan();

        $this->assertDatabaseHas('documents', ['source_item_id' => 'item-1']);
    }

    #[Test]
    public function a_repeat_scan_with_unchanged_ctags_queues_nothing(): void
    {
        $children = $this->folderChildren([
            $this->fileItem('item-1', 'SOP-QA-001.docx', 'ctag-a'),
            $this->fileItem('item-2', 'Policy-HR-002.pdf', 'ctag-b'),
        ]);

        $this->fakeGraph([$children, $children]);

        $this->scan();
        $second = $this->scan();

        $this->assertSame(2, $second->total_found);
        $this->assertSame(0, $second->new_files);
        $this->assertSame(0, $second->modified_files);
        $this->assertSame(2, $second->unchanged_files);
        $this->assertSame(0, $second->queued_for_analysis);

        $this->assertSame(1, Document::where('source_item_id', 'item-1')->firstOrFail()->versions()->count());
    }

    #[Test]
    public function a_moved_ctag_creates_a_new_version(): void
    {
        // The regression this guards: a remote file has no local bytes, so the
        // hash is null. Before the token check was made definitive, a changed
        // SharePoint document fell through to the hash comparison and was
        // reported unchanged.
        $this->fakeGraph([
            $this->folderChildren([$this->fileItem('item-1', 'SOP-QA-001.docx', 'ctag-a', 2048)]),
            $this->folderChildren([$this->fileItem('item-1', 'SOP-QA-001.docx', 'ctag-b', 2048)]),
        ]);

        $this->scan();
        $second = $this->scan();

        $this->assertSame(1, $second->modified_files);
        $this->assertSame(1, $second->queued_for_analysis);

        $document = Document::where('source_item_id', 'item-1')->firstOrFail();

        $this->assertSame(1, Document::count(), 'A changed file must not create a second document.');
        $this->assertSame(2, $document->versions()->count());
        $this->assertSame('ctag-b', $document->fresh()->source_etag);
    }

    #[Test]
    public function it_recurses_into_subfolders(): void
    {
        $this->fakeGraph([
            $this->folderChildren([
                $this->fileItem('item-1', 'Index.docx', 'ctag-a'),
                $this->folderItem('folder-1', 'Quality'),
            ]),
            $this->folderChildren([$this->fileItem('item-2', 'SOP-QA-009.docx', 'ctag-c')]),
        ]);

        $this->scan();

        $nested = Document::where('source_item_id', 'item-2')->firstOrFail();

        $this->assertSame('Quality/SOP-QA-009.docx', $nested->file_path);
        $this->assertSame('Quality', $nested->parent_path);
    }

    #[Test]
    public function it_follows_pagination(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'https://graph.microsoft.com/v1.0/drives/drive-1/root:/General/SOP:/children*' => Http::response([
                'value' => [$this->fileItem('item-1', 'Page1.docx', 'ctag-a')],
                '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/next-page',
            ]),
            'https://graph.microsoft.com/v1.0/next-page*' => Http::response([
                'value' => [$this->fileItem('item-2', 'Page2.docx', 'ctag-b')],
            ]),
        ]);

        $log = $this->scan();

        $this->assertSame(2, $log->total_found);
        $this->assertSame(2, Document::count());
    }

    #[Test]
    public function it_ignores_unsupported_extensions(): void
    {
        $this->fakeGraph([
            $this->folderChildren([
                $this->fileItem('item-1', 'SOP-QA-001.docx', 'ctag-a'),
                $this->fileItem('item-2', 'diagram.vsdx', 'ctag-b'),
                $this->fileItem('item-3', 'macro.exe', 'ctag-c'),
            ]),
        ]);

        $this->assertSame(1, $this->scan()->total_found);
    }

    #[Test]
    public function it_backs_off_and_retries_when_graph_throttles(): void
    {
        // Ignoring Retry-After escalates to a tenant-wide throttle affecting
        // every other Graph consumer in the organisation.
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'https://graph.microsoft.com/v1.0/drives/drive-1/root*' => Http::sequence()
                ->push(['error' => ['code' => 'activityLimitReached']], 429, ['Retry-After' => '0'])
                ->push($this->folderChildren([$this->fileItem('item-1', 'SOP-QA-001.docx', 'ctag-a')])),
        ]);

        $log = $this->scan();

        $this->assertSame(ScanStatus::COMPLETED, $log->status);
        $this->assertSame(1, $log->total_found);
    }

    #[Test]
    public function a_denied_scan_fails_without_marking_documents_missing(): void
    {
        // The document is seeded rather than produced by a first scan:
        // Http::fake() accumulates stubs rather than replacing them, so a
        // successful fake registered earlier would still win here.
        Document::factory()->create([
            'document_source_id' => $this->source->id,
            'source_type' => DocumentSourceType::SHAREPOINT,
            'source_item_id' => 'item-1',
            'is_active' => true,
        ]);

        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'https://graph.microsoft.com/*' => Http::response(['error' => ['code' => 'accessDenied']], 403),
        ]);

        $log = $this->scan();

        $this->assertSame(ScanStatus::FAILED, $log->status);

        // A permissions failure proves nothing about what is in the library,
        // so nothing may be marked missing on the strength of it.
        $this->assertTrue(Document::firstOrFail()->is_active);
        $this->assertSame(0, $log->deleted_files);
    }

    #[Test]
    public function a_deleted_file_is_deactivated_not_removed(): void
    {
        $this->fakeGraph([
            $this->folderChildren([
                $this->fileItem('item-1', 'SOP-QA-001.docx', 'ctag-a'),
                $this->fileItem('item-2', 'Policy-HR-002.pdf', 'ctag-b'),
            ]),
            $this->folderChildren([$this->fileItem('item-1', 'SOP-QA-001.docx', 'ctag-a')]),
        ]);

        $this->scan();
        $second = $this->scan();

        $this->assertSame(1, $second->deleted_files);

        $removed = Document::where('source_item_id', 'item-2')->firstOrFail();
        $this->assertFalse($removed->is_active);
        $this->assertNotNull($removed->missing_since);
    }

    #[Test]
    public function the_connection_test_reports_a_missing_graph_configuration(): void
    {
        config()->set('microsoft_graph.tenant_id', null);
        config()->set('microsoft_graph.client_secret', null);
        Http::fake();

        $result = app(DocumentSourceFactory::class)
            ->make($this->source)
            ->testConnection();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not configured', $result['message']);
    }

    #[Test]
    public function the_connection_test_succeeds_when_graph_answers(): void
    {
        $this->fakeGraph([
            $this->folderChildren([$this->fileItem('item-1', 'SOP-QA-001.docx', 'ctag-a')]),
        ]);

        $result = app(DocumentSourceFactory::class)
            ->make($this->source)
            ->testConnection();

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('client secret', $result['message']);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers */
    /* ------------------------------------------------------------------ */

    private function scan()
    {
        return app(SourceScanService::class)->scan($this->source->fresh());
    }

    /** @param array<int, array<string, mixed>> $responses successive children payloads */
    private function fakeGraph(array $responses): void
    {
        $sequence = Http::sequence();

        foreach ($responses as $response) {
            $sequence->push($response);
        }

        // Repeat the last payload rather than throwing, so a test that makes
        // one extra call fails on its assertion instead of on plumbing.
        $sequence->whenEmpty(Http::response(['value' => []]));

        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'https://graph.microsoft.com/*' => $sequence,
        ]);
    }

    /** @param array<int, array<string, mixed>> $items */
    private function folderChildren(array $items): array
    {
        return ['value' => $items];
    }

    /** @return array<string, mixed> */
    private function fileItem(string $id, string $name, string $cTag, int $size = 1024): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'size' => $size,
            'cTag' => $cTag,
            'eTag' => $cTag.'-etag',
            'file' => ['mimeType' => 'application/octet-stream'],
            'lastModifiedDateTime' => '2026-01-15T09:30:00Z',
            'webUrl' => "https://contoso.sharepoint.com/sites/DC/{$name}",
        ];
    }

    /** @return array<string, mixed> */
    private function folderItem(string $id, string $name): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'size' => 0,
            'folder' => ['childCount' => 1],
            'lastModifiedDateTime' => '2026-01-15T09:30:00Z',
        ];
    }
}
