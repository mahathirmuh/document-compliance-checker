<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runtime-editable application settings.
 *
 * Business thresholds must never be hard-coded (CLAUDE.md 23). Every value
 * here shadows a default in config/documents.php; if a key is absent the
 * config default applies, so the table can stay sparse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();

            // 'integer' | 'float' | 'boolean' | 'string' | 'json' - tells the
            // settings service how to cast `value` back out of text.
            $table->string('type', 16)->default('string');

            $table->string('group', 64)->default('general');
            $table->string('label');
            $table->text('description')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
