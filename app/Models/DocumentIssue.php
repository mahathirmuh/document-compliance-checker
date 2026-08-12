<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IssueSeverity;
use App\Enums\IssueType;
use App\Enums\LanguageCode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single finding raised by an analysis.
 *
 * @property IssueType $issue_type
 * @property IssueSeverity $severity
 * @property ?LanguageCode $language_code
 */
#[Fillable([
    'document_analysis_id', 'page_number', 'section_name', 'paragraph_index',
    'issue_type', 'language_code', 'severity', 'description', 'metadata',
])]
class DocumentIssue extends Model
{
    protected function casts(): array
    {
        return [
            'issue_type' => IssueType::class,
            'severity' => IssueSeverity::class,
            'language_code' => LanguageCode::class,
            'page_number' => 'integer',
            'paragraph_index' => 'integer',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<DocumentAnalysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(DocumentAnalysis::class, 'document_analysis_id');
    }

    /**
     * Human-readable location, or a dash when the issue is document-level.
     *
     * Phase 1 raises only document-level issues; the page and section columns
     * start being populated by the Phase 4 per-section rules.
     */
    public function displayLocation(): string
    {
        $parts = array_filter([
            $this->page_number ? 'Page '.$this->page_number : null,
            $this->section_name,
        ]);

        return $parts === [] ? '—' : implode(' · ', $parts);
    }
}
