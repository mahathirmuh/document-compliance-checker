<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-language findings for one analysis (CLAUDE.md 8.6).
 *
 * `word_count` is nullable on purpose: Chinese has no whitespace word
 * boundaries, so for ZH only `character_count` is meaningful. Nothing in the
 * application may rank or threshold Chinese on word count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('language_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_analysis_id')->constrained()->cascadeOnDelete();

            // App\Enums\LanguageCode backing value: EN | ID | ZH.
            $table->string('language_code', 8);

            $table->boolean('detected')->default(false);
            $table->unsignedInteger('character_count')->default(0);
            $table->unsignedInteger('word_count')->nullable();

            // Share of the document's total meaningful text, 0.00-100.00.
            $table->decimal('coverage_percent', 5, 2)->default(0);

            // Detector confidence, 0.0000-1.0000.
            $table->decimal('confidence', 5, 4)->nullable();

            // The threshold this result was judged against, copied in at write
            // time. Without it a later settings change would silently rewrite
            // history when the row is re-rendered.
            $table->unsignedInteger('threshold_applied')->nullable();
            $table->boolean('meets_threshold')->default(false);

            $table->timestamps();

            $table->unique(['document_analysis_id', 'language_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('language_results');
    }
};
