<?php

declare(strict_types=1);

use App\Enums\AnalysisStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One analysis run over one document version (CLAUDE.md 8.5).
 *
 * A version can be analysed more than once - after a parser fix, or when a
 * Document Controller re-queues it - so this is deliberately not unique per
 * version. `analyzer_version` records which analyser produced the row, which
 * is what makes results comparable across those re-runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_version_id')->constrained()->cascadeOnDelete();

            $table->string('status', 24)->default(AnalysisStatus::PENDING->value);
            $table->decimal('overall_score', 5, 2)->nullable();
            $table->string('analyzer_version', 32)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            // Safe to surface in the UI: written by the application, never a
            // raw exception dump, so it cannot leak paths or credentials.
            $table->text('error_message')->nullable();

            // Verbatim analyser payload, kept so a future rule can be applied
            // retroactively without re-parsing the source document.
            $table->jsonb('raw_result')->nullable();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['document_id', 'created_at']);
            $table->index(['document_version_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_analyses');
    }
};
