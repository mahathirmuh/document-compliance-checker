<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Individual findings raised by an analysis (CLAUDE.md 8.7).
 *
 * Location columns are all nullable: a Phase 1 finding is document-level,
 * while Phase 4 rules will pin issues to a page, section, paragraph or table
 * cell. Adding those rules should not need a schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_analysis_id')->constrained()->cascadeOnDelete();

            /* --- Where (all optional) --- */
            $table->unsignedInteger('page_number')->nullable();
            $table->string('section_name')->nullable();
            $table->unsignedInteger('paragraph_index')->nullable();

            /* --- What --- */
            $table->string('issue_type', 48);
            $table->string('language_code', 8)->nullable();
            $table->string('severity', 16);
            $table->text('description');

            // Structured detail for the UI - counts, thresholds, excerpts.
            // Must never carry secrets or bulk document text.
            $table->jsonb('metadata')->nullable();

            $table->timestamps();

            $table->index(['document_analysis_id', 'severity']);
            $table->index('issue_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_issues');
    }
};
