<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LanguageCode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Language coverage of one section of a document.
 *
 * This is what turns "this SOP is missing Mandarin" into "section 4.2 is
 * missing Mandarin" - the difference between a finding a Document Controller
 * can act on and one they have to go hunting for (CLAUDE.md 7, 21).
 */
#[Fillable([
    'document_analysis_id', 'name', 'sequence', 'page_number',
    'total_characters', 'segment_count', 'language_characters',
    'missing_languages', 'short_languages', 'evaluated',
])]
class DocumentSection extends Model
{
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'page_number' => 'integer',
            'total_characters' => 'integer',
            'segment_count' => 'integer',
            'language_characters' => 'array',
            'missing_languages' => 'array',
            'short_languages' => 'array',
            'evaluated' => 'boolean',
        ];
    }

    /** @return BelongsTo<DocumentAnalysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(DocumentAnalysis::class, 'document_analysis_id');
    }

    /**
     * Sections with a real problem, in reading order.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithFindings(Builder $query): void
    {
        $query->where('evaluated', true)
            ->where(function (Builder $q) {
                $q->whereRaw("missing_languages <> '[]'::jsonb")
                    ->orWhereRaw("short_languages <> '[]'::jsonb");
            })
            ->orderBy('sequence');
    }

    public function charactersFor(LanguageCode $language): int
    {
        return (int) ($this->language_characters[mb_strtolower($language->value)] ?? 0);
    }

    public function isMissing(LanguageCode $language): bool
    {
        return in_array(mb_strtolower($language->value), $this->missing_languages ?? [], true);
    }

    public function isShort(LanguageCode $language): bool
    {
        return in_array(mb_strtolower($language->value), $this->short_languages ?? [], true);
    }

    /** True when every required language is present and none is short. */
    public function isComplete(): bool
    {
        return ($this->missing_languages ?? []) === [] && ($this->short_languages ?? []) === [];
    }
}
