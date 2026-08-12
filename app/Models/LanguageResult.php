<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LanguageCode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one analysis found for one language.
 *
 * @property LanguageCode $language_code
 */
#[Fillable([
    'document_analysis_id', 'language_code', 'detected', 'character_count',
    'word_count', 'coverage_percent', 'confidence', 'threshold_applied',
    'meets_threshold',
])]
class LanguageResult extends Model
{
    protected function casts(): array
    {
        return [
            'language_code' => LanguageCode::class,
            'detected' => 'boolean',
            'character_count' => 'integer',
            'word_count' => 'integer',
            'coverage_percent' => 'decimal:2',
            'confidence' => 'decimal:4',
            'threshold_applied' => 'integer',
            'meets_threshold' => 'boolean',
        ];
    }

    /** @return BelongsTo<DocumentAnalysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(DocumentAnalysis::class, 'document_analysis_id');
    }

    /**
     * The count this language's threshold was applied to.
     *
     * Characters, for every language (CLAUDE.md 6). `word_count` is stored
     * for Latin-script languages as a readability aid and is null for
     * Chinese, which has no whitespace word boundaries (CLAUDE.md 8.6) - it
     * must never drive a verdict.
     */
    public function meaningfulCount(): int
    {
        return (int) $this->character_count;
    }

    public function confidencePercent(): ?float
    {
        return $this->confidence === null ? null : round((float) $this->confidence * 100, 1);
    }
}
