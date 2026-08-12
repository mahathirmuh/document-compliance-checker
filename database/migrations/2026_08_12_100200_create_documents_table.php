<?php

declare(strict_types=1);

use App\Enums\AnalysisStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per document discovered in a source (CLAUDE.md 8.3).
 *
 * Only metadata and a reference are stored - the file itself stays in its
 * source, which remains the system of record.
 *
 * Identity note: `source_item_id` is whatever the source calls the item.
 * For SharePoint that is the Graph driveItem id; for a filesystem source it
 * is a SHA-256 of the path relative to the source root. Both give a stable,
 * bounded-length key, which is what the unique index below needs - a raw UNC
 * path is neither bounded nor reliably comparable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_source_id')->constrained()->cascadeOnDelete();

            // Denormalised from the source so document queries and reports do
            // not have to join just to filter or group by source type.
            $table->string('source_type', 32);

            $table->string('source_item_id', 128);

            /* --- SharePoint / Graph coordinates (null for filesystem sources) --- */
            $table->string('drive_id')->nullable();
            $table->string('site_id')->nullable();

            /* --- Location --- */
            $table->text('parent_path')->nullable();
            $table->text('file_path');
            $table->string('file_name');
            $table->string('original_file_name')->nullable();
            $table->string('extension', 16);
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->default(0);

            /* --- Document Control metadata (parsed or entered by hand) --- */
            $table->string('document_code')->nullable();
            $table->string('document_title')->nullable();
            $table->string('document_type', 32)->nullable();
            $table->string('department')->nullable();
            $table->string('current_revision', 32)->nullable();

            /* --- Change detection (CLAUDE.md 9) --- */
            $table->string('file_hash', 64)->nullable();
            $table->string('source_etag')->nullable();
            $table->timestamp('source_last_modified_at')->nullable();

            /* --- Latest analysis, denormalised for list and dashboard reads --- */
            $table->string('analysis_status', 24)->default(AnalysisStatus::PENDING->value);
            $table->decimal('compliance_score', 5, 2)->nullable();
            $table->timestamp('last_analyzed_at')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('missing_since')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // A file is identified within its source, not globally: the same
            // document may legitimately be registered under two sources.
            $table->unique(['document_source_id', 'source_item_id']);

            $table->index(['analysis_status', 'is_active']);
            $table->index(['document_type', 'analysis_status']);
            $table->index(['source_type', 'is_active']);
            $table->index('document_code');
            $table->index('file_hash');
            $table->index('last_analyzed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
