<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Enums\UserRole;
use App\Exceptions\RejectedUploadException;
use App\Jobs\AnalyzeDocumentJob;
use App\Models\User;
use App\Services\Documents\DocumentUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * Upload validation (CLAUDE.md 13).
 *
 * The interesting cases are the ones where the extension lies: a renamed
 * executable, an arbitrary zip wearing a .docx suffix, a text file claiming
 * to be a PDF. Each layer of the check is exercised separately.
 */
class DocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    private DocumentUploadService $service;

    private User $uploader;

    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('documents');

        $this->service = app(DocumentUploadService::class);
        $this->uploader = User::factory()->role(UserRole::DOCUMENT_CONTROLLER)->create();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_accepts_a_valid_docx_and_queues_it_for_analysis(): void
    {
        $document = $this->service->store($this->docx('SOP-QA-001.docx'), $this->uploader);

        $this->assertSame('SOP-QA-001.docx', $document->file_name);
        $this->assertSame('docx', $document->extension);
        $this->assertSame(AnalysisStatus::PENDING, $document->analysis_status);
        $this->assertNotNull($document->file_hash);

        Queue::assertPushed(AnalyzeDocumentJob::class, 1);
    }

    #[Test]
    public function it_stores_the_file_under_a_generated_name(): void
    {
        // The name the user chose must never become a path on disk.
        $document = $this->service->store($this->docx('../../evil name.docx'), $this->uploader);
        $storedPath = $document->currentVersion->stored_path;

        $this->assertStringNotContainsString('evil', $storedPath);
        $this->assertStringNotContainsString('..', $storedPath);
        $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/[0-9A-Z]{26}\.docx$#', $storedPath);

        // The original name survives as metadata only, sanitised.
        $this->assertSame('evil name.docx', $document->original_file_name);

        Storage::disk('documents')->assertExists($storedPath);
    }

    #[Test]
    public function it_rejects_an_executable(): void
    {
        $this->expectException(RejectedUploadException::class);
        $this->expectExceptionMessage('Executable and script files may not be uploaded.');

        $this->service->store($this->rawFile('payload.exe', 'MZ binary'), $this->uploader);
    }

    #[Test]
    public function it_rejects_an_extension_that_is_not_allowed(): void
    {
        $this->expectException(RejectedUploadException::class);

        $this->service->store($this->rawFile('slides.pptx', 'anything'), $this->uploader);
    }

    #[Test]
    public function it_rejects_a_text_file_renamed_as_a_pdf(): void
    {
        // Magic-byte check: a real PDF starts with "%PDF-".
        $this->expectException(RejectedUploadException::class);

        $this->service->store($this->rawFile('report.pdf', 'this is not a pdf'), $this->uploader);
    }

    #[Test]
    public function it_rejects_an_arbitrary_zip_renamed_as_a_docx(): void
    {
        // The zip signature passes, so this is what the OOXML member check
        // exists for.
        $this->expectException(RejectedUploadException::class);

        $this->service->store($this->zipWithout('archive.docx'), $this->uploader);
    }

    #[Test]
    public function it_rejects_an_xlsx_renamed_as_a_docx(): void
    {
        $this->expectException(RejectedUploadException::class);

        $this->service->store($this->xlsx('spreadsheet.docx'), $this->uploader);
    }

    #[Test]
    public function it_accepts_a_valid_pdf(): void
    {
        $document = $this->service->store(
            $this->rawFile('manual.pdf', "%PDF-1.7\n%\xE2\xE3\xCF\xD3\ntrailer"),
            $this->uploader,
        );

        $this->assertSame('pdf', $document->extension);
    }

    #[Test]
    public function it_rejects_a_file_over_the_size_limit(): void
    {
        config()->set('documents.upload.max_size_kb', 1);

        $this->expectException(RejectedUploadException::class);

        $this->service->store($this->rawFile('big.txt', str_repeat('a', 4096)), $this->uploader);
    }

    #[Test]
    public function it_creates_the_first_version_as_current(): void
    {
        $document = $this->service->store($this->docx('SOP.docx'), $this->uploader);
        $version = $document->currentVersion;

        $this->assertSame(1, $version->version_number);
        $this->assertTrue($version->is_current);
        $this->assertSame($document->file_hash, $version->file_hash);
    }

    #[Test]
    public function the_upload_source_is_a_singleton(): void
    {
        $first = $this->service->uploadSource();
        $second = $this->service->uploadSource();

        $this->assertTrue($first->is($second));
    }

    /* ------------------------------------------------------------------ */

    private function docx(string $name): UploadedFile
    {
        return $this->buildZip($name, ['word/document.xml' => '<w:document/>']);
    }

    private function xlsx(string $name): UploadedFile
    {
        return $this->buildZip($name, ['xl/workbook.xml' => '<workbook/>']);
    }

    private function zipWithout(string $name): UploadedFile
    {
        return $this->buildZip($name, ['readme.txt' => 'not an office document']);
    }

    /**
     * @param  array<string, string>  $members
     */
    private function buildZip(string $name, array $members): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl').'.zip';
        $this->tempFiles[] = $path;

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($members as $member => $contents) {
            $zip->addFromString($member, $contents);
        }

        $zip->close();

        return new UploadedFile($path, $name, null, null, true);
    }

    private function rawFile(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        $this->tempFiles[] = $path;

        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, null, null, true);
    }
}
