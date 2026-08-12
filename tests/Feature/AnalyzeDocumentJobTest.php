<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Jobs\AnalyzeDocumentJob;
use App\Models\Document;
use App\Models\DocumentAnalysis;
use App\Models\DocumentVersion;
use App\Services\Analyzer\AnalyzerClient;
use App\Services\Documents\DocumentAnalysisService;
use App\Services\DocumentSources\DocumentSourceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Phase 1 queue placeholder (CLAUDE.md 36).
 *
 * The job, its retry policy and its failure handling are real; only the
 * analyser behind it is missing. These tests pin the behaviour that matters
 * today: with the analyser disabled the document waits at PENDING, and
 * nothing crashes.
 */
class AnalyzeDocumentJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function with_the_analyzer_disabled_the_document_stays_pending(): void
    {
        config()->set('documents.analyzer.enabled', false);

        $version = $this->version();

        (new AnalyzeDocumentJob($version->id))->handle(
            app(AnalyzerClient::class),
            app(DocumentAnalysisService::class),
            app(DocumentSourceFactory::class),
        );

        $this->assertSame(AnalysisStatus::PENDING, $version->document->refresh()->analysis_status);

        // No analysis row is opened for a run that never happened - an empty
        // PROCESSING record would look like a stalled worker.
        $this->assertSame(0, DocumentAnalysis::count());
    }

    #[Test]
    public function a_deleted_version_is_handled_without_throwing(): void
    {
        config()->set('documents.analyzer.enabled', false);

        $job = new AnalyzeDocumentJob(999_999);

        $job->handle(
            app(AnalyzerClient::class),
            app(DocumentAnalysisService::class),
            app(DocumentSourceFactory::class),
        );

        $this->assertSame(0, DocumentAnalysis::count());
    }

    #[Test]
    public function the_job_retries_a_few_times_and_then_gives_up(): void
    {
        $job = new AnalyzeDocumentJob(1);

        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300], $job->backoff);
    }

    #[Test]
    public function it_takes_an_id_rather_than_a_serialised_model(): void
    {
        // A version deleted between dispatch and execution must be handled by
        // the job, not blow up during deserialisation.
        $job = new AnalyzeDocumentJob(42, 7);

        $this->assertSame(42, $job->documentVersionId);
        $this->assertSame(7, $job->requestedBy);
    }

    private function version(): DocumentVersion
    {
        return DocumentVersion::factory()->for(Document::factory())->create();
    }
}
