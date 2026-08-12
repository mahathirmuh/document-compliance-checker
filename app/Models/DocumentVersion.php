<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An immutable snapshot of one document at one point in time.
 *
 * Rows here are never updated in place except to flip `is_current` when a
 * newer version supersedes them; the analysis history hanging off each
 * version has to stay exactly as it was recorded (CLAUDE.md 8.4).
 */
#[Fillable([
    'document_id', 'version_number', 'revision_label', 'file_hash',
    'source_etag', 'file_size', 'source_last_modified_at', 'detected_at',
    'stored_path', 'is_current',
])]
class DocumentVersion extends Model
{
    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'file_size' => 'integer',
            'source_last_modified_at' => 'datetime',
            'detected_at' => 'datetime',
            'analyzed_at' => 'datetime',
            'is_current' => 'boolean',
        ];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return HasMany<DocumentAnalysis, $this> */
    public function analyses(): HasMany
    {
        return $this->hasMany(DocumentAnalysis::class);
    }

    /** @return HasOne<DocumentAnalysis, $this> */
    public function latestAnalysis(): HasOne
    {
        return $this->hasOne(DocumentAnalysis::class)->latestOfMany();
    }

    /**
     * What to show in the version history column.
     *
     * Prefers the revision the document itself declares, falling back to the
     * application's own counter when the document does not declare one.
     */
    public function displayRevision(): string
    {
        return $this->revision_label ?: 'v'.$this->version_number;
    }
}
