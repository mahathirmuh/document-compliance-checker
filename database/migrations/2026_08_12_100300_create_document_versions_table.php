<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An immutable snapshot of a document at one point in time (CLAUDE.md 8.4).
 *
 * A changed file never overwrites its predecessor: a new version row is
 * created and analysed, so historical results stay intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();

            // Monotonic per document, starting at 1. This is the application's
            // own counter, distinct from `revision_label` which is whatever
            // revision the document itself claims (e.g. "Rev. 03").
            $table->unsignedInteger('version_number');
            $table->string('revision_label', 32)->nullable();

            $table->string('file_hash', 64)->nullable();
            $table->string('source_etag')->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->timestamp('source_last_modified_at')->nullable();

            // When the scanner first saw this state of the file, as opposed to
            // when the source says it changed.
            $table->timestamp('detected_at');
            $table->timestamp('analyzed_at')->nullable();

            // A stored copy for UPLOAD sources, where there is no external
            // system of record to re-read the bytes from. Null otherwise.
            $table->text('stored_path')->nullable();

            $table->boolean('is_current')->default(true);
            $table->timestamps();

            $table->unique(['document_id', 'version_number']);
            $table->index(['document_id', 'is_current']);
            $table->index('file_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
    }
};
