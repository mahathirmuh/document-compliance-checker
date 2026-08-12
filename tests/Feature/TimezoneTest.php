<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Services\Analyzer\DTO\AnalysisResult;
use App\Services\Documents\DocumentAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Timestamps must agree with the wall clock at the site (CLAUDE.md 34).
 *
 * Written after the interface showed a document as modified at 15:40 while
 * the clock on the wall said 23:40. config/app.php hardcoded 'UTC' and
 * ignored APP_TIMEZONE entirely, so the setting in .env had never done
 * anything - every timestamp in the application was eight hours out.
 */
class TimezoneTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_application_timezone_is_read_from_the_environment(): void
    {
        // The regression itself: a hardcoded value here silently discards the
        // deployment's setting.
        $this->assertSame(
            env('APP_TIMEZONE', 'UTC'),
            config('app.timezone'),
            'config/app.php must read APP_TIMEZONE rather than hardcoding a zone.',
        );
    }

    #[Test]
    public function it_is_not_left_at_utc_for_this_deployment(): void
    {
        // Not a general rule - a UTC deployment is legitimate. But this one
        // is on-premise for a single Indonesian site, and UTC is what the
        // reported bug looked like.
        $this->assertNotSame('UTC', config('app.timezone'));
    }

    #[Test]
    public function stored_timestamps_are_written_in_the_configured_zone(): void
    {
        config()->set('app.timezone', 'Asia/Makassar');
        date_default_timezone_set('Asia/Makassar');

        $document = Document::factory()->create();

        $stored = $document->fresh()->created_at;
        $expected = Carbon::now('Asia/Makassar');

        $this->assertSame(
            $expected->format('Y-m-d H'),
            $stored->format('Y-m-d H'),
            'A row written now should carry the local hour, not the UTC one.',
        );
    }

    #[Test]
    public function an_analysis_records_the_local_time_it_finished(): void
    {
        config()->set('app.timezone', 'Asia/Makassar');
        date_default_timezone_set('Asia/Makassar');

        $version = DocumentVersion::factory()->for(Document::factory())->create();
        $service = app(DocumentAnalysisService::class);

        $service->complete(
            $service->start($version),
            AnalysisResult::fromArray([
                'languages' => [
                    'en' => ['detected' => true, 'character_count' => 500, 'coverage' => 40],
                    'id' => ['detected' => true, 'character_count' => 480, 'coverage' => 38],
                    'zh' => ['detected' => true, 'character_count' => 200, 'coverage' => 22],
                ],
                'analyzer_version' => 'test',
            ]),
        );

        $analyzedAt = $version->document->fresh()->last_analyzed_at;

        $this->assertLessThanOrEqual(
            120,
            abs($analyzedAt->diffInSeconds(Carbon::now('Asia/Makassar'))),
            '"Last analyzed" must match the clock a Document Controller is looking at.',
        );
    }
}
