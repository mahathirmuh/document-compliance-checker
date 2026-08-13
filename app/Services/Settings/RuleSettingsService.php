<?php

declare(strict_types=1);

namespace App\Services\Settings;

/**
 * Assembles the Document Control rule configuration sent to the analyzer.
 *
 * The rules live in the analyzer because they need the document's structure;
 * the decision about *which* rules apply lives here, because that is a
 * Document Control policy an administrator edits at runtime. Same split as
 * the language thresholds, and for the same reason: turning a rule on must
 * not require a deploy (CLAUDE.md 7, 23).
 */
class RuleSettingsService
{
    /**
     * Rules an administrator can turn on, and what each one is called.
     *
     * @var array<string, array{label: string, description: string}>
     */
    public const RULES = [
        'language_order' => [
            'label' => 'Language order',
            'description' => 'Translations must appear in the configured order within each section. '
                .'Only checked where a document declares its own sections.',
        ],
        'document_code' => [
            'label' => 'Document code and revision',
            'description' => 'A document code and revision must be present in the header, footer or '
                .'first page. Also fills in those fields when the document record has none.',
        ],
        'header_footer' => [
            'label' => 'Header and footer',
            'description' => 'The document must carry a header and a footer. Exact for Word files; '
                .'inferred from repetition for PDFs, which can confirm presence but not absence.',
        ],
        'cover_page' => [
            'label' => 'Cover page',
            'description' => 'The document must open with its code and a readable title.',
        ],
        'font_color' => [
            'label' => 'Font colour',
            'description' => 'Body text must use only permitted colours. Word documents only - a PDF '
                .'reports "not checked" rather than passing.',
        ],
        'numeric_consistency' => [
            'label' => 'Numeric consistency',
            'description' => 'A dose, temperature or limit stated in one language must appear in its '
                .'translations. Reads 6.5 and 6,5 as the same number. Not checked on text '
                .'recovered by OCR, which misreads digits.',
        ],
    ];

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * The payload for the analyzer's `rules` field.
     *
     * Returns an empty array when nothing is enabled, which the analyzer
     * treats as "run no rules" - the Phase 1-4 behaviour unchanged.
     *
     * @return array<string, array<string, mixed>>
     */
    public function payload(): array
    {
        $configured = (array) config('documents.rules', []);
        $payload = [];

        foreach (self::RULES as $rule => $meta) {
            $options = (array) ($configured[$rule] ?? []);
            $options['enabled'] = $this->isEnabled($rule);

            if (! $options['enabled']) {
                continue;
            }

            // A null pattern means "use the analyzer's default". Sending it
            // would override that default with nothing.
            $payload[$rule] = array_filter(
                $options,
                static fn ($value) => $value !== null,
            );
        }

        return $payload;
    }

    public function isEnabled(string $rule): bool
    {
        if (! array_key_exists($rule, self::RULES)) {
            return false;
        }

        // SettingsService falls back to the config default when no
        // administrator has made a decision yet - which for every rule is
        // "off".
        return $this->settings->boolean($this->settingKey($rule));
    }

    public function setEnabled(string $rule, bool $enabled, ?int $userId = null): void
    {
        if (! array_key_exists($rule, self::RULES)) {
            throw new \InvalidArgumentException("Unknown Document Control rule [{$rule}].");
        }

        $this->settings->set($this->settingKey($rule), $enabled, $userId);
    }

    /** @return array<string, bool> */
    public function enabledMap(): array
    {
        $map = [];

        foreach (array_keys(self::RULES) as $rule) {
            $map[$rule] = $this->isEnabled($rule);
        }

        return $map;
    }

    private function settingKey(string $rule): string
    {
        return "rule_{$rule}_enabled";
    }
}
