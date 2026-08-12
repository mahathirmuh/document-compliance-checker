<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AnalysisStatus;
use App\Enums\LanguageCode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One analysis run over one document version.
 *
 * @property AnalysisStatus $status
 */
#[Fillable([
    'document_id', 'document_version_id', 'status', 'overall_score',
    'analyzer_version', 'started_at', 'completed_at', 'duration_ms',
    'error_message', 'raw_result', 'requested_by',
])]
class DocumentAnalysis extends Model
{
    protected function casts(): array
    {
        return [
            'status' => AnalysisStatus::class,
            'overall_score' => 'decimal:2',
            'raw_result' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'duration_ms' => 'integer',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Relationships                                                       */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<DocumentVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    /** @return HasMany<LanguageResult, $this> */
    public function languageResults(): HasMany
    {
        return $this->hasMany(LanguageResult::class);
    }

    /** @return HasMany<DocumentIssue, $this> */
    public function issues(): HasMany
    {
        return $this->hasMany(DocumentIssue::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Language results keyed by code, with every required language present.
     *
     * The detail page has to render a row for EN, ID and ZH whether or not
     * the analyser reported one, so a missing language reads as "not
     * detected" rather than silently vanishing from the table.
     *
     * @return array<string, ?LanguageResult>
     */
    public function languageResultMap(): array
    {
        $byCode = $this->languageResults->keyBy(
            fn (LanguageResult $result) => $result->language_code->value,
        );

        $map = [];

        foreach (LanguageCode::requiredOrder() as $language) {
            $map[$language->value] = $byCode->get($language->value);
        }

        return $map;
    }
}
