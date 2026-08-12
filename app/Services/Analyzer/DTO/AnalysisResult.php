<?php

declare(strict_types=1);

namespace App\Services\Analyzer\DTO;

use App\Enums\IssueSeverity;
use App\Enums\IssueType;
use App\Enums\LanguageCode;

/**
 * A parsed analyser response.
 *
 * Note what this class does *not* do: it never decides PASS / PARTIAL / FAIL.
 * The analyser reports measurements; the verdict is a Document Control policy
 * decision that belongs to DocumentAnalysisService, where the configurable
 * thresholds live. Keeping the two apart means a threshold change re-grades
 * documents without touching the Python service (CLAUDE.md 6, 15).
 */
final readonly class AnalysisResult
{
    /**
     * @param  array<string, LanguageFinding>  $languages  keyed by language code
     * @param  array<int, array{type: IssueType, severity: IssueSeverity, description: string, language: ?LanguageCode, page: ?int, section: ?string, metadata: ?array<string, mixed>}>  $issues
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public array $languages,
        public array $issues,
        public ?string $analyzerVersion,
        public ?float $reportedScore,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $languages = [];

        foreach (LanguageCode::cases() as $language) {
            $key = mb_strtolower($language->value);
            $languagePayload = $payload['languages'][$key] ?? $payload['languages'][$language->value] ?? null;

            // A language the analyser omitted is recorded as absent rather
            // than skipped, so the detail page always shows all three rows.
            $languages[$language->value] = LanguageFinding::fromArray(
                $language,
                is_array($languagePayload) ? $languagePayload : [],
            );
        }

        return new self(
            languages: $languages,
            issues: self::parseIssues($payload['issues'] ?? []),
            analyzerVersion: isset($payload['analyzer_version']) ? (string) $payload['analyzer_version'] : null,
            reportedScore: isset($payload['overall_score']) ? (float) $payload['overall_score'] : null,
            raw: $payload,
        );
    }

    public function language(LanguageCode $code): LanguageFinding
    {
        return $this->languages[$code->value];
    }

    /** Total meaningful text across all languages, used to sanity-check emptiness. */
    public function totalMeaningfulCount(): int
    {
        return array_sum(array_map(
            fn (LanguageFinding $finding) => $finding->meaningfulCount(),
            $this->languages,
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function parseIssues(mixed $issues): array
    {
        if (! is_array($issues)) {
            return [];
        }

        $parsed = [];

        foreach ($issues as $issue) {
            if (! is_array($issue)) {
                continue;
            }

            $type = IssueType::tryFrom((string) ($issue['type'] ?? ''));

            // An unrecognised issue type is dropped rather than guessed at:
            // inventing a severity for it would misreport compliance.
            if ($type === null) {
                continue;
            }

            $parsed[] = [
                'type' => $type,
                'severity' => IssueSeverity::tryFrom((string) ($issue['severity'] ?? '')) ?? $type->defaultSeverity(),
                'description' => (string) ($issue['description'] ?? $type->label()),
                'language' => LanguageCode::tryFrom(mb_strtoupper((string) ($issue['language'] ?? ''))),
                'page' => isset($issue['page']) ? (int) $issue['page'] : null,
                'section' => isset($issue['section']) ? (string) $issue['section'] : null,
                'metadata' => is_array($issue['metadata'] ?? null) ? $issue['metadata'] : null,
            ];
        }

        return $parsed;
    }
}
