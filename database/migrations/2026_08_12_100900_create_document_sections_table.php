<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-section language coverage for one analysis (CLAUDE.md 7, 27 Phase 4).
 *
 * Additive: nothing existing is altered, so historical analyses simply have
 * no section rows and the detail page falls back to the document-level view.
 *
 * Counts are held in a JSONB map rather than one column per language. Three
 * columns would be faster to read but would make adding a fourth required
 * language a schema migration across every existing row, and PostgreSQL
 * indexes JSONB well enough to answer "which sections are missing Mandarin"
 * across the whole library.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_analysis_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            // Position in the document, so the UI can render sections in
            // reading order rather than alphabetically.
            $table->unsignedInteger('sequence');

            $table->unsignedInteger('page_number')->nullable();
            $table->unsignedInteger('total_characters')->default(0);
            $table->unsignedInteger('segment_count')->default(0);

            // {"en": 812, "id": 794, "zh": 240}
            $table->jsonb('language_characters')->nullable();

            // Languages absent from this section, e.g. ["zh"].
            $table->jsonb('missing_languages')->nullable();

            // Present but disproportionately short against the section's
            // longest language, compared on density-normalised lengths.
            $table->jsonb('short_languages')->nullable();

            // False for sections too small to reasonably carry three
            // translations. They are recorded for completeness but never
            // counted against the document.
            $table->boolean('evaluated')->default(true);

            $table->timestamps();

            $table->unique(['document_analysis_id', 'sequence']);
            $table->index(['document_analysis_id', 'evaluated']);
        });

        // Answers "show me every section still missing Mandarin" without a
        // full scan once the library is large.
        DB::statement('CREATE INDEX document_sections_missing_gin ON document_sections USING gin (missing_languages)');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sections');
    }
};
