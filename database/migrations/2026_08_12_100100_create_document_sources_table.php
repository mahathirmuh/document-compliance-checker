<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A registered place documents come from (CLAUDE.md 8.2).
 *
 * `configuration` holds only non-sensitive, type-specific identifiers - for
 * SharePoint that means site_id / drive_id / folder path, never a secret.
 * Credentials live in the environment or a secret store.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // App\Enums\DocumentSourceType backing value.
            $table->string('type', 32);

            // Filesystem path or UNC path. Null for SHAREPOINT and UPLOAD,
            // which are addressed through `configuration` instead.
            $table->text('path')->nullable();

            $table->jsonb('configuration')->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('scan_interval_minutes')->default(60);
            $table->timestamp('last_scan_at')->nullable();
            $table->timestamp('last_successful_scan_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'enabled']);
            $table->index('last_scan_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sources');
    }
};
