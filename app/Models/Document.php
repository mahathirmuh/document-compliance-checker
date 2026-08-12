<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AnalysisStatus;
use App\Enums\DocumentSourceType;
use App\Enums\DocumentType;
use App\Enums\LanguageCode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A document discovered in - or uploaded to - a source.
 *
 * `analysis_status`, `compliance_score` and `last_analyzed_at` mirror the
 * newest analysis so the list and dashboard can be answered from one table.
 * They are only ever written by DocumentAnalysisService; nothing else should
 * set them, or the mirror drifts from the analyses it summarises.
 *
 * @property AnalysisStatus $analysis_status
 * @property DocumentSourceType $source_type
 * @property ?DocumentType $document_type
 */
#[Fillable([
    'document_source_id', 'source_type', 'source_item_id', 'drive_id', 'site_id',
    'parent_path', 'file_path', 'file_name', 'original_file_name', 'extension',
    'mime_type', 'file_size', 'document_code', 'document_title', 'document_type',
    'department', 'current_revision', 'file_hash', 'source_etag',
    'source_last_modified_at', 'analysis_status', 'is_active',
])]
class Document extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'source_type' => DocumentSourceType::class,
            'document_type' => DocumentType::class,
            'analysis_status' => AnalysisStatus::class,
            'file_size' => 'integer',
            'compliance_score' => 'decimal:2',
            'source_last_modified_at' => 'datetime',
            'last_analyzed_at' => 'datetime',
            'missing_since' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Relationships                                                       */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<DocumentSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(DocumentSource::class, 'document_source_id');
    }

    /** @return HasMany<DocumentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class);
    }

    /** @return HasOne<DocumentVersion, $this> */
    public function currentVersion(): HasOne
    {
        return $this->hasOne(DocumentVersion::class)->where('is_current', true);
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

    /* ------------------------------------------------------------------ */
    /* Scopes                                                              */
    /* ------------------------------------------------------------------ */

    /** @param Builder<static> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param Builder<static> $query */
    public function scopeStatus(Builder $query, AnalysisStatus $status): void
    {
        $query->where('analysis_status', $status);
    }

    /**
     * Documents where the newest analysis found the given language absent or
     * short of its threshold.
     *
     * @param Builder<static> $query
     */
    public function scopeMissingLanguage(Builder $query, LanguageCode $language): void
    {
        $query->whereHas('latestAnalysis.languageResults', function (Builder $q) use ($language) {
            $q->where('language_code', $language->value)
                ->where('meets_threshold', false);
        });
    }

    /**
     * Free-text search over the fields a Document Controller actually knows:
     * the code, the title, and the file name (CLAUDE.md 20).
     *
     * @param Builder<static> $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        // Escape LIKE wildcards so a user pasting a path fragment containing
        // "%" or "_" does not accidentally broaden their own search.
        $escaped = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        $query->where(function (Builder $q) use ($escaped) {
            $q->where('document_code', 'ilike', $escaped)
                ->orWhere('document_title', 'ilike', $escaped)
                ->orWhere('file_name', 'ilike', $escaped);
        });
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    public function displayTitle(): string
    {
        return $this->document_title ?: $this->file_name;
    }

    public function humanFileSize(): string
    {
        $bytes = (int) $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];

        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), $power === 0 ? 0 : 1).' '.$units[$power];
    }
}
