<?php

declare(strict_types=1);

use App\Enums\ScanStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per scan run of one source (CLAUDE.md 8.8).
 *
 * The counters are what tells an operator that change detection is working:
 * a healthy repeat scan of an untouched folder should report everything as
 * unchanged and queue nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_source_id')->constrained()->cascadeOnDelete();

            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('status', 32)->default(ScanStatus::RUNNING->value);

            $table->unsignedInteger('total_found')->default(0);
            $table->unsignedInteger('new_files')->default(0);
            $table->unsignedInteger('modified_files')->default(0);
            $table->unsignedInteger('unchanged_files')->default(0);
            $table->unsignedInteger('deleted_files')->default(0);
            $table->unsignedInteger('skipped_files')->default(0);
            $table->unsignedInteger('queued_for_analysis')->default(0);
            $table->unsignedInteger('error_count')->default(0);

            // Operator-facing summary. Sanitised before it is written: never
            // an exception dump, never a credential.
            $table->text('message')->nullable();

            // Whether a human pressed "Scan now" rather than the scheduler.
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['document_source_id', 'started_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_logs');
    }
};
