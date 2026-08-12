<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ScanStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The record of one scan run.
 *
 * @property ScanStatus $status
 */
#[Fillable([
    'document_source_id', 'started_at', 'completed_at', 'duration_ms', 'status',
    'total_found', 'new_files', 'modified_files', 'unchanged_files',
    'deleted_files', 'skipped_files', 'queued_for_analysis', 'error_count',
    'message', 'triggered_by',
])]
class ScanLog extends Model
{
    protected function casts(): array
    {
        return [
            'status' => ScanStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'duration_ms' => 'integer',
            'total_found' => 'integer',
            'new_files' => 'integer',
            'modified_files' => 'integer',
            'unchanged_files' => 'integer',
            'deleted_files' => 'integer',
            'skipped_files' => 'integer',
            'queued_for_analysis' => 'integer',
            'error_count' => 'integer',
        ];
    }

    /** @return BelongsTo<DocumentSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(DocumentSource::class, 'document_source_id');
    }

    /** @return BelongsTo<User, $this> */
    public function trigger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function durationForHumans(): string
    {
        if ($this->duration_ms === null) {
            return '—';
        }

        return $this->duration_ms < 1000
            ? $this->duration_ms.' ms'
            : round($this->duration_ms / 1000, 1).' s';
    }
}
