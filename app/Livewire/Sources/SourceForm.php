<?php

declare(strict_types=1);

namespace App\Livewire\Sources;

use App\Enums\DocumentSourceType;
use App\Exceptions\UnsafePathException;
use App\Models\DocumentSource;
use App\Services\Audit\AuditLogger;
use App\Services\Files\PathGuard;
use App\Services\Settings\SettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Create or edit a document source.
 *
 * The path is validated through PathGuard rather than a Laravel rule so the
 * form applies exactly the same check the scanner will: absolute, no relative
 * segments, not a system folder, and actually readable by the account the
 * application runs under. Catching an unreadable share here rather than at
 * 2am in a queue worker is the point (CLAUDE.md 10, 12).
 */
#[Layout('components.layouts.app')]
class SourceForm extends Component
{
    public ?DocumentSource $source = null;

    public string $name = '';

    public string $type = '';

    public string $path = '';

    public int $scanIntervalMinutes = 60;

    public bool $enabled = true;

    /* --- SharePoint-only, stored in `configuration` (never secrets) --- */

    public string $siteId = '';

    public string $driveId = '';

    public string $folderPath = '';

    public function mount(?DocumentSource $source = null, ?SettingsService $settings = null): void
    {
        $settings ??= app(SettingsService::class);

        if ($source?->exists) {
            Gate::authorize('update', $source);

            $this->source = $source;
            $this->name = $source->name;
            $this->type = $source->type->value;
            $this->path = (string) $source->path;
            $this->scanIntervalMinutes = $source->scan_interval_minutes;
            $this->enabled = $source->enabled;
            $this->siteId = (string) $source->config('site_id', '');
            $this->driveId = (string) $source->config('drive_id', '');
            $this->folderPath = (string) $source->config('folder_path', '');

            return;
        }

        Gate::authorize('create', DocumentSource::class);

        $this->type = DocumentSourceType::WINDOWS_SHARE->value;
        $this->scanIntervalMinutes = $settings->integer('default_scan_interval');
    }

    public function isSharePoint(): bool
    {
        return $this->type === DocumentSourceType::SHAREPOINT->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creatable = array_column(DocumentSourceType::creatable(), 'value');

        return [
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in($creatable)],
            'path' => [Rule::requiredIf(! $this->isSharePoint()), 'nullable', 'string', 'max:1000'],
            'scanIntervalMinutes' => ['required', 'integer', 'min:5', 'max:10080'],
            'enabled' => ['boolean'],
            'siteId' => [Rule::requiredIf($this->isSharePoint()), 'nullable', 'string', 'max:255'],
            'driveId' => ['nullable', 'string', 'max:255'],
            'folderPath' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function validationAttributes(): array
    {
        return [
            'scanIntervalMinutes' => 'scan interval',
            'siteId' => 'site ID',
            'driveId' => 'drive ID',
            'folderPath' => 'folder path',
        ];
    }

    public function save(PathGuard $pathGuard, AuditLogger $auditLogger)
    {
        $this->validate();

        $validatedPath = null;

        if (! $this->isSharePoint()) {
            try {
                $validatedPath = $pathGuard->validateSourceRoot($this->path);
            } catch (UnsafePathException $e) {
                $this->addError('path', $e->getMessage());

                return null;
            }
        }

        $attributes = [
            'name' => $this->name,
            'type' => $this->type,
            'path' => $validatedPath,
            'scan_interval_minutes' => $this->scanIntervalMinutes,
            'enabled' => $this->enabled,
            'configuration' => $this->isSharePoint()
                ? array_filter([
                    'site_id' => $this->siteId ?: null,
                    'drive_id' => $this->driveId ?: null,
                    'folder_path' => $this->folderPath ?: null,
                ])
                : null,
        ];

        if ($this->source?->exists) {
            Gate::authorize('update', $this->source);

            $original = $this->source->getOriginal();
            $this->source->update($attributes);
            $auditLogger->logChanges(AuditLogger::ACTION_SOURCE_UPDATED, $this->source, $original);

            session()->flash('status', "Source [{$this->source->name}] updated.");
        } else {
            Gate::authorize('create', DocumentSource::class);

            $source = DocumentSource::create([...$attributes, 'created_by' => auth()->id()]);
            $auditLogger->log(AuditLogger::ACTION_SOURCE_CREATED, $source, newValues: $attributes);

            session()->flash('status', "Source [{$source->name}] created. Run a scan to index its documents.");
        }

        return $this->redirectRoute('sources.index', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.sources.source-form', [
            'types' => DocumentSourceType::creatable(),
        ])->title($this->source?->exists ? 'Edit source' : 'Add source');
    }
}
