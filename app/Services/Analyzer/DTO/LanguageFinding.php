<?php

declare(strict_types=1);

namespace App\Services\Analyzer\DTO;

use App\Enums\LanguageCode;

/**
 * What the analyser reported for one language.
 *
 * Deliberately a plain value object rather than the Eloquent row: it is the
 * parsed wire format, and keeping the two separate is what stops a change in
 * the Python response shape from rippling into the schema (CLAUDE.md 14).
 */
final readonly class LanguageFinding
{
    public function __construct(
        public LanguageCode $language,
        public bool $detected,
        public int $characterCount,
        public ?int $wordCount,
        public float $coveragePercent,
        public ?float $confidence,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(LanguageCode $language, array $payload): self
    {
        // Chinese is never given a word count: whitespace does not separate
        // words in Han script, so any number here would be noise.
        $wordCount = $language->isCharacterCounted()
            ? null
            : (isset($payload['word_count']) ? (int) $payload['word_count'] : null);

        return new self(
            language: $language,
            detected: (bool) ($payload['detected'] ?? false),
            characterCount: (int) ($payload['character_count'] ?? 0),
            wordCount: $wordCount,
            coveragePercent: round((float) ($payload['coverage'] ?? $payload['coverage_percent'] ?? 0), 2),
            confidence: isset($payload['confidence']) ? round((float) $payload['confidence'], 4) : null,
        );
    }

    /** The count this language is judged on. */
    public function meaningfulCount(): int
    {
        return $this->language->isCharacterCounted()
            ? $this->characterCount
            : ($this->wordCount ?? $this->characterCount);
    }
}
