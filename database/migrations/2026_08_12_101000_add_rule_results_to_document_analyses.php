<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which Document Control rules ran, and what they concluded (CLAUDE.md 27
 * Phase 5).
 *
 * A column rather than a table: the individual findings already become
 * document_issues rows, which is where the queryable detail belongs. What is
 * left is a small per-analysis summary - whether each rule ran at all, and
 * why not if it did not. That is read whole, with its analysis, and never
 * joined or aggregated across documents.
 *
 * The "did not run" part is the reason this is stored rather than derived.
 * Font colour cannot be read from a scanned PDF, and an analysis with no
 * font-colour issues has to stay distinguishable from one where the check
 * never happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_analyses', function (Blueprint $table) {
            $table->jsonb('rule_results')->nullable()->after('raw_result');
        });
    }

    public function down(): void
    {
        Schema::table('document_analyses', function (Blueprint $table) {
            $table->dropColumn('rule_results');
        });
    }
};
