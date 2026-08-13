<?php

declare(strict_types=1);

namespace App\Services\Analyzer\DTO;

/**
 * A document's text, paired up by section and language.
 *
 * Carries no verdict and no measurement - it exists so a Document Controller
 * can read the three languages next to each other and judge the translation
 * themselves. That is the semantic check the system deliberately does not
 * make on its own (CLAUDE.md 33).
 */
final readonly class DocumentExtraction
{
    /**
     * @param  array<int, ExtractedSection>  $sections
     */
    public function __construct(
        public array $sections,
        public bool $truncated,
        public ?int $pageCount,
        public string $parser,
        public string $analyzerVersion,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $sections = array_map(
            static fn (array $section) => ExtractedSection::fromArray($section),
            array_values(array_filter(
                (array) ($payload['sections'] ?? []),
                'is_array',
            )),
        );

        return new self(
            // A section with no text at all contributes nothing to a
            // side-by-side reading and would only add an empty card.
            sections: array_values(array_filter(
                $sections,
                static fn (ExtractedSection $section) => ! $section->isEmpty(),
            )),
            truncated: (bool) ($payload['truncated'] ?? false),
            pageCount: isset($payload['page_count']) ? (int) $payload['page_count'] : null,
            parser: (string) ($payload['parser'] ?? 'unknown'),
            analyzerVersion: (string) ($payload['analyzer_version'] ?? 'unknown'),
        );
    }

    public function isEmpty(): bool
    {
        return $this->sections === [];
    }

    public function sectionCount(): int
    {
        return count($this->sections);
    }
}
