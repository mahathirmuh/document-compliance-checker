<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Enums\DocumentSourceType;
use App\Enums\ScanStatus;
use App\Jobs\AnalyzeDocumentJob;
use App\Models\Document;
use App\Models\DocumentSource;
use App\Services\Scanning\SourceScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * End-to-end folder scanning against a real temporary directory.
 *
 * These are the CLAUDE.md 37 acceptance criteria: files are found, repeat
 * scans do not duplicate unchanged documents, and a modified file creates a
 * new version rather than overwriting the old one.
 */
class FolderScanTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private DocumentSource $source;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'scan-'.bin2hex(random_bytes(6));
        mkdir($this->root.DIRECTORY_SEPARATOR.'Quality', 0o777, true);

        $this->source = DocumentSource::factory()
            ->type(DocumentSourceType::WINDOWS_LOCAL)
            ->atPath($this->root)
            ->create();
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);

        parent::tearDown();
    }

    #[Test]
    public function it_indexes_supported_files_and_records_a_scan_log(): void
    {
        $this->writeFile('SOP-QA-001.docx');
        $this->writeFile('Quality/Policy-HR-002.pdf');
        $this->writeFile('notes.txt');

        $log = $this->scan();

        $this->assertSame(ScanStatus::COMPLETED, $log->status);
        $this->assertSame(3, $log->total_found);
        $this->assertSame(3, $log->new_files);
        $this->assertSame(0, $log->unchanged_files);
        $this->assertSame(3, $log->queued_for_analysis);
        $this->assertSame(3, Document::count());
    }

    #[Test]
    public function it_ignores_unsupported_extensions(): void
    {
        $this->writeFile('SOP-QA-001.docx');
        $this->writeFile('macro.exe');
        $this->writeFile('image.png');

        $log = $this->scan();

        $this->assertSame(1, $log->total_found);
        $this->assertSame(1, Document::count());
    }

    #[Test]
    public function it_ignores_office_lock_files(): void
    {
        // Word leaves these behind while a document is open; indexing one
        // creates a record that vanishes the moment the author closes it.
        $this->writeFile('SOP-QA-001.docx');
        $this->writeFile('~$SOP-QA-001.docx');

        $this->assertSame(1, $this->scan()->total_found);
    }

    #[Test]
    public function a_second_scan_of_an_untouched_folder_changes_nothing(): void
    {
        $this->writeFile('SOP-QA-001.docx');
        $this->scan();

        $second = $this->scan();

        $this->assertSame(1, $second->total_found);
        $this->assertSame(0, $second->new_files);
        $this->assertSame(0, $second->modified_files);
        $this->assertSame(1, $second->unchanged_files);
        $this->assertSame(0, $second->queued_for_analysis);

        $this->assertSame(1, Document::count());
        $this->assertSame(1, Document::first()->versions()->count());
    }

    #[Test]
    public function a_modified_file_creates_a_new_version_and_keeps_the_old_one(): void
    {
        $this->writeFile('SOP-QA-001.docx', 'original contents');
        $this->scan();

        $document = Document::first();
        $firstVersion = $document->currentVersion;

        // Move the timestamp so the cheap fingerprint check registers a change
        // the way a real edit would.
        $this->writeFile('SOP-QA-001.docx', 'revised contents, materially longer');
        touch($this->root.DIRECTORY_SEPARATOR.'SOP-QA-001.docx', time() + 60);
        clearstatcache();

        $log = $this->scan();

        $this->assertSame(1, $log->modified_files);
        $this->assertSame(1, $log->queued_for_analysis);

        $document->refresh();

        $this->assertSame(1, Document::count(), 'A modified file must not create a second document.');
        $this->assertSame(2, $document->versions()->count());
        $this->assertSame(2, $document->currentVersion->version_number);

        $this->assertFalse($firstVersion->refresh()->is_current);
        $this->assertNotSame($firstVersion->file_hash, $document->currentVersion->file_hash);
    }

    #[Test]
    public function a_file_that_disappears_is_deactivated_not_deleted(): void
    {
        $this->writeFile('SOP-QA-001.docx');
        $this->scan();

        unlink($this->root.DIRECTORY_SEPARATOR.'SOP-QA-001.docx');

        $log = $this->scan();

        $this->assertSame(1, $log->deleted_files);

        $document = Document::withTrashed()->first();

        $this->assertNotNull($document, 'Compliance history must survive a file disappearing.');
        $this->assertFalse($document->is_active);
        $this->assertNotNull($document->missing_since);
    }

    #[Test]
    public function a_file_that_comes_back_is_reactivated(): void
    {
        $this->writeFile('SOP-QA-001.docx');
        $this->scan();
        unlink($this->root.DIRECTORY_SEPARATOR.'SOP-QA-001.docx');
        $this->scan();

        $this->writeFile('SOP-QA-001.docx');
        $this->scan();

        $document = Document::first();

        $this->assertTrue($document->is_active);
        $this->assertNull($document->missing_since);
        $this->assertSame(1, Document::count());
    }

    #[Test]
    public function new_documents_are_queued_for_analysis_and_left_pending(): void
    {
        $this->writeFile('SOP-QA-001.docx');
        $this->scan();

        Queue::assertPushed(AnalyzeDocumentJob::class, 1);

        $this->assertSame(AnalysisStatus::PENDING, Document::first()->analysis_status);
    }

    #[Test]
    public function an_unreachable_source_fails_the_scan_without_touching_documents(): void
    {
        $this->writeFile('SOP-QA-001.docx');
        $this->scan();

        $this->source->update(['path' => $this->root.DIRECTORY_SEPARATOR.'does-not-exist']);

        $log = $this->scan();

        $this->assertSame(ScanStatus::FAILED, $log->status);
        $this->assertSame(1, $log->error_count);

        // Critically, the existing document must NOT have been marked missing:
        // a failed scan proves nothing about what is in the folder.
        $this->assertTrue(Document::first()->is_active);
    }

    #[Test]
    public function it_guesses_a_document_type_from_the_file_name(): void
    {
        $this->writeFile('SOP-QA-001.docx');
        $this->writeFile('Work Instruction Packing.docx');
        $this->scan();

        $this->assertSame('SOP', Document::where('file_name', 'SOP-QA-001.docx')->first()->document_type->value);
        $this->assertSame(
            'WORK_INSTRUCTION',
            Document::where('file_name', 'Work Instruction Packing.docx')->first()->document_type->value,
        );
    }

    /* ------------------------------------------------------------------ */

    private function scan()
    {
        return app(SourceScanService::class)->scan($this->source->fresh());
    }

    private function writeFile(string $relativePath, string $contents = 'contents'): void
    {
        $path = $this->root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }

        file_put_contents($path, $contents);
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path.DIRECTORY_SEPARATOR.$entry;
            is_dir($child) ? $this->deleteTree($child) : @unlink($child);
        }

        @rmdir($path);
    }
}
