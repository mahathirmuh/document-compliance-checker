<?php

declare(strict_types=1);

namespace App\Services\Analyzer\DTO;

use App\Enums\LanguageCode;

/**
 * One section of a document, split by the language each piece is written in.
 *
 * The unit a reviewer reads. Everything here is text the analyzer returned
 * for this request and nothing is persisted: the application deliberately
 * never becomes a second copy of a controlled document (CLAUDE.md 12).
 */
final readonly class ExtractedSection
{
    /**
     * @param  array<string, array<int, string>>  $segments  language code => text, in document order
     * @param  array<string, int>  $characters  language code => meaningful character count
     * @param  array<int, string>  $unassigned  text belonging to no language
     * @param  array<int, string>  $missing  language codes absent from this section
     */
    public function __construct(
        public string $name,
        public int $sequence,
        public ?int $page,
        public array $segments,
        public array $characters,
        public array $unassigned,
        public array $missing,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $blocks = (array) ($payload['blocks'] ?? []);
        $segments = [];
        $characters = [];

        foreach (LanguageCode::requiredOrder() as $language) {
            // The analyzer speaks lower case; LanguageCode is upper. Both are
            // accepted and everything is keyed by the enum value from here on,
            // the same convention AnalysisResult uses.
            $block = (array) (
                $blocks[mb_strtolower($language->value)]
                ?? $blocks[$language->value]
                ?? []
            );

            $segments[$language->value] = array_values(array_map(
                static fn ($text) => (string) $text,
                (array) ($block['segments'] ?? []),
            ));
            $characters[$language->value] = (int) ($block['characters'] ?? 0);
        }

        return new self(
            name: (string) ($payload['name'] ?? ''),
            sequence: (int) ($payload['sequence'] ?? 0),
            page: isset($payload['page']) ? (int) $payload['page'] : null,
            segments: $segments,
            characters: $characters,
            unassigned: array_values(array_map(
                static fn ($text) => (string) $text,
                (array) ($payload['unassigned'] ?? []),
            )),
            // Normalised to the enum's own casing so the view can turn one
            // back into a LanguageCode without guessing.
            missing: array_values(array_filter(array_map(
                static fn ($code) => LanguageCode::tryFrom(mb_strtoupper((string) $code))?->value,
                (array) ($payload['missing'] ?? []),
            ))),
        );
    }

    /** @return array<int, string> */
    public function segmentsFor(LanguageCode $language): array
    {
        return $this->segments[$language->value] ?? [];
    }

    public function charactersFor(LanguageCode $language): int
    {
        return $this->characters[$language->value] ?? 0;
    }

    public function isMissing(LanguageCode $language): bool
    {
        return in_array($language->value, $this->missing, strict: true);
    }

    /**
     * How many rows this section needs when shown as three columns.
     *
     * The languages rarely have the same number of paragraphs, so the table
     * is as tall as the longest of them and the shorter columns simply run
     * out. Padding them to equal length would imply a paragraph-by-paragraph
     * correspondence that the alignment does not claim.
     */
    public function rowCount(): int
    {
        return max(array_map('count', $this->segments) ?: [0]);
    }

    public function isEmpty(): bool
    {
        return $this->rowCount() === 0 && $this->unassigned === [];
    }
}
