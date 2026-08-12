<?php

declare(strict_types=1);

namespace App\Services\Analyzer\DTO;

use App\Enums\LanguageCode;

/**
 * What the analyzer measured for one section of a document.
 *
 * The analyzer decides which languages are *absent* from a section, because
 * that is a fact about the text. It does not decide whether the section is
 * compliant - that still depends on the thresholds held in Laravel.
 */
final readonly class SectionFinding
{
    /**
     * @param  array<string, int>  $characters  meaningful characters per language code
     * @param  array<int, string>  $missing  language codes with no text at all
     * @param  array<int, string>  $short  present but disproportionately short
     */
    public function __construct(
        public string $name,
        public int $sequence,
        public ?int $page,
        public int $totalCharacters,
        public int $segmentCount,
        public array $characters,
        public array $missing,
        public array $short,
        public bool $evaluated,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload, int $fallbackSequence): self
    {
        $characters = [];

        foreach (LanguageCode::cases() as $language) {
            $key = mb_strtolower($language->value);
            $characters[$key] = (int) ($payload['characters'][$key] ?? 0);
        }

        return new self(
            name: (string) ($payload['name'] ?? 'Untitled section'),
            sequence: (int) ($payload['sequence'] ?? $fallbackSequence),
            page: isset($payload['page']) ? (int) $payload['page'] : null,
            totalCharacters: (int) ($payload['total_characters'] ?? 0),
            segmentCount: (int) ($payload['segment_count'] ?? 0),
            characters: $characters,
            missing: self::languageCodes($payload['missing'] ?? []),
            short: self::languageCodes($payload['short'] ?? []),
            evaluated: (bool) ($payload['evaluated'] ?? true),
        );
    }

    /**
     * Normalise a list of language codes, dropping anything unrecognised.
     *
     * @return array<int, string>
     */
    private static function languageCodes(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $codes = [];

        foreach ($values as $value) {
            $language = LanguageCode::tryFrom(mb_strtoupper((string) $value));

            if ($language !== null) {
                $codes[] = mb_strtolower($language->value);
            }
        }

        return array_values(array_unique($codes));
    }
}
