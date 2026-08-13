<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Services\Audit\AuditLogger;
use App\Services\Settings\SettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Runtime settings (CLAUDE.md 23).
 *
 * Changing a threshold here re-grades every document analysed from that point
 * on, so each save is written to the audit trail with its before and after.
 * Historical results are untouched: LanguageResult stores the threshold that
 * was applied at the time.
 */
#[Layout('components.layouts.app')]
#[Title('Settings')]
class SettingsPanel extends Component
{
    /** @var array<string, mixed> */
    public array $values = [];

    public function mount(SettingsService $settings): void
    {
        Gate::authorize('manage-sources');

        foreach ($settings->effective() as $key => $value) {
            // The allowed-extension list edits as comma-separated text; a
            // JSON textarea would be a needless trap for an operator.
            $this->values[$key] = is_array($value) ? implode(', ', $value) : $value;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'values.min_chars_en' => ['required', 'integer', 'min:0', 'max:1000000'],
            'values.min_chars_id' => ['required', 'integer', 'min:0', 'max:1000000'],
            'values.min_chars_zh' => ['required', 'integer', 'min:0', 'max:1000000'],
            'values.min_compliance_score' => ['required', 'numeric', 'min:0', 'max:100'],
            'values.max_upload_kb' => ['required', 'integer', 'min:64', 'max:1048576'],
            'values.allowed_extensions' => ['required', 'string', 'max:255'],
            'values.temp_retention_hours' => ['required', 'integer', 'min:1', 'max:8760'],
            'values.default_scan_interval' => ['required', 'integer', 'min:5', 'max:10080'],
            'values.ocr_enabled' => ['boolean'],
            'values.ai_semantic_enabled' => ['boolean'],
            'values.rule_language_order_enabled' => ['boolean'],
            'values.rule_document_code_enabled' => ['boolean'],
            'values.rule_header_footer_enabled' => ['boolean'],
            'values.rule_cover_page_enabled' => ['boolean'],
            'values.rule_font_color_enabled' => ['boolean'],
            'values.rule_numeric_consistency_enabled' => ['boolean'],
        ];
    }

    public function save(SettingsService $settings, AuditLogger $auditLogger): void
    {
        Gate::authorize('manage-sources');

        $this->validate();

        $before = $settings->effective();

        foreach ($this->values as $key => $value) {
            $settings->set($key, $this->normalise($key, $value), auth()->id());
        }

        $after = $settings->effective();

        // Only the keys that actually moved reach the audit row.
        $changed = array_keys(array_filter(
            $after,
            fn ($value, $key) => ($before[$key] ?? null) !== $value,
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($changed !== []) {
            $auditLogger->log(
                AuditLogger::ACTION_SETTING_UPDATED,
                oldValues: array_intersect_key($before, array_flip($changed)),
                newValues: array_intersect_key($after, array_flip($changed)),
            );
        }

        session()->flash('status', $changed === []
            ? 'No settings were changed.'
            : sprintf('%d setting(s) updated.', count($changed)));
    }

    private function normalise(string $key, mixed $value): mixed
    {
        $type = SettingsService::DEFINITIONS[$key]['type'] ?? 'string';

        return match ($type) {
            'integer' => (int) $value,
            'float' => (float) $value,
            'boolean' => (bool) $value,
            'json' => array_values(array_filter(array_map(
                fn (string $part) => mb_strtolower(trim($part, " \t.")),
                explode(',', (string) $value),
            ))),
            default => $value,
        };
    }

    public function render(): View
    {
        return view('livewire.settings.settings-panel', [
            'definitions' => SettingsService::DEFINITIONS,
            'blockedExtensions' => (array) config('documents.extensions.blocked', []),
        ]);
    }
}
