<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Single read path for every runtime-editable business value.
 *
 * Resolution order is: the `settings` table, then the matching default in
 * config/documents.php. Nothing outside this class may read a threshold from
 * config directly, so there is exactly one place where "what is the minimum
 * English character count" gets answered (CLAUDE.md 23, 25).
 */
class SettingsService
{
    private const CACHE_KEY = 'doccheck.settings';

    private const CACHE_TTL_SECONDS = 300;

    /**
     * Every setting an administrator may change, and where its default lives.
     *
     * A key absent from this map is not a setting - it is a typo, and get()
     * will say so rather than quietly returning null.
     *
     * @var array<string, array{type: string, config: string, group: string, label: string, description: string}>
     */
    public const DEFINITIONS = [
        'min_chars_en' => [
            'type' => 'integer',
            'config' => 'documents.thresholds.min_chars_en',
            'group' => 'thresholds',
            'label' => 'Minimum English characters',
            'description' => 'Meaningful English text required before English counts as present.',
        ],
        'min_chars_id' => [
            'type' => 'integer',
            'config' => 'documents.thresholds.min_chars_id',
            'group' => 'thresholds',
            'label' => 'Minimum Indonesian characters',
            'description' => 'Meaningful Indonesian text required before Indonesian counts as present.',
        ],
        'min_chars_zh' => [
            'type' => 'integer',
            'config' => 'documents.thresholds.min_chars_zh',
            'group' => 'thresholds',
            'label' => 'Minimum Chinese characters',
            'description' => 'Han characters required before Mandarin counts as present. Counted as characters, never words.',
        ],
        'min_compliance_score' => [
            'type' => 'float',
            'config' => 'documents.thresholds.min_compliance_score',
            'group' => 'thresholds',
            'label' => 'Minimum compliance score',
            'description' => 'Overall score a document must reach to be reported as compliant.',
        ],
        'max_upload_kb' => [
            'type' => 'integer',
            'config' => 'documents.upload.max_size_kb',
            'group' => 'uploads',
            'label' => 'Maximum upload size (KB)',
            'description' => 'Rejected above this size. Must stay at or below the PHP upload_max_filesize.',
        ],
        'allowed_extensions' => [
            'type' => 'json',
            'config' => 'documents.extensions.uploadable',
            'group' => 'uploads',
            'label' => 'Allowed upload extensions',
            'description' => 'Extensions accepted by the upload form. The blocked list always wins over this.',
        ],
        'temp_retention_hours' => [
            'type' => 'integer',
            'config' => 'documents.temporary.retention_hours',
            'group' => 'housekeeping',
            'label' => 'Temporary file retention (hours)',
            'description' => 'How long a temporary working copy survives before cleanup removes it.',
        ],
        'default_scan_interval' => [
            'type' => 'integer',
            'config' => 'documents.scan.default_interval_minutes',
            'group' => 'scanning',
            'label' => 'Default scan interval (minutes)',
            'description' => 'Applied to new sources. Each source can override it.',
        ],
        'ocr_enabled' => [
            'type' => 'boolean',
            'config' => 'documents.features.ocr_enabled',
            'group' => 'analysis',
            'label' => 'OCR enabled',
            'description' => 'Run OCR over scanned PDFs. Requires the Phase 4 analyser.',
        ],
        'ai_semantic_enabled' => [
            'type' => 'boolean',
            'config' => 'documents.features.ai_semantic_enabled',
            'group' => 'analysis',
            'label' => 'AI semantic check enabled',
            'description' => 'Advisory translation-similarity checking. Results never overwrite document content.',
        ],

        /*
         * Document Control rules (CLAUDE.md 27 Phase 5).
         *
         * All off by default. A rule is a statement about how this
         * organisation's documents are supposed to look, which is a Document
         * Controller's decision rather than a default to impose.
         */
        'rule_language_order_enabled' => [
            'type' => 'boolean',
            'config' => 'documents.rules.language_order.enabled',
            'group' => 'rules',
            'label' => 'Check language order',
            'description' => 'Translations must appear in EN → ID → ZH order within each section. Only checked where a document declares its own sections.',
        ],
        'rule_document_code_enabled' => [
            'type' => 'boolean',
            'config' => 'documents.rules.document_code.enabled',
            'group' => 'rules',
            'label' => 'Check document code and revision',
            'description' => 'Requires a code and revision in the header, footer or first page. Also fills those fields in when the document record has none.',
        ],
        'rule_header_footer_enabled' => [
            'type' => 'boolean',
            'config' => 'documents.rules.header_footer.enabled',
            'group' => 'rules',
            'label' => 'Check header and footer',
            'description' => 'Exact for Word files. For PDFs it is inferred from repetition, which can confirm a header exists but never that one is absent.',
        ],
        'rule_cover_page_enabled' => [
            'type' => 'boolean',
            'config' => 'documents.rules.cover_page.enabled',
            'group' => 'rules',
            'label' => 'Check cover page',
            'description' => 'The document must open with its code and a readable title.',
        ],
        'rule_font_color_enabled' => [
            'type' => 'boolean',
            'config' => 'documents.rules.font_color.enabled',
            'group' => 'rules',
            'label' => 'Check font colour',
            'description' => 'Word documents only. A PDF reports "not checked" rather than passing, so an unreadable format never looks compliant.',
        ],
    ];

    public function get(string $key, mixed $default = null): mixed
    {
        $definition = self::DEFINITIONS[$key] ?? null;

        if ($definition === null) {
            throw new \InvalidArgumentException("Unknown setting [{$key}].");
        }

        $stored = $this->all();

        if (array_key_exists($key, $stored) && $stored[$key] !== null) {
            return $stored[$key];
        }

        return $default ?? config($definition['config']);
    }

    public function integer(string $key): int
    {
        return (int) $this->get($key);
    }

    public function float(string $key): float
    {
        return (float) $this->get($key);
    }

    public function boolean(string $key): bool
    {
        return (bool) $this->get($key);
    }

    /** @return array<int, string> */
    public function list(string $key): array
    {
        $value = $this->get($key);

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * Persist an override.
     *
     * Writing the config default back is still recorded as an override; that
     * is deliberate, so the settings screen can show that a human chose the
     * value rather than inheriting it.
     */
    public function set(string $key, mixed $value, ?int $userId = null): void
    {
        $definition = self::DEFINITIONS[$key] ?? null;

        if ($definition === null) {
            throw new \InvalidArgumentException("Unknown setting [{$key}].");
        }

        Setting::updateOrCreate(
            ['key' => $key],
            [
                'value' => Setting::encode($value, $definition['type']),
                'type' => $definition['type'],
                'group' => $definition['group'],
                'label' => $definition['label'],
                'description' => $definition['description'],
                'updated_by' => $userId,
            ],
        );

        $this->flush();
    }

    /**
     * Effective values for every defined key, overrides applied.
     *
     * @return array<string, mixed>
     */
    public function effective(): array
    {
        $values = [];

        foreach (array_keys(self::DEFINITIONS) as $key) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Stored overrides only, keyed by setting name.
     *
     * @return array<string, mixed>
     */
    private function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            return Setting::query()
                ->get()
                ->mapWithKeys(fn (Setting $setting) => [$setting->key => $setting->typedValue()])
                ->all();
        });
    }
}
