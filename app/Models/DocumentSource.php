<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentSourceType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A registered origin for documents.
 *
 * The model deliberately knows nothing about *how* to read a source; that is
 * the job of the adapter resolved by DocumentSourceFactory.
 *
 * @property DocumentSourceType $type
 */
#[Fillable([
    'name', 'type', 'path', 'configuration', 'enabled',
    'scan_interval_minutes', 'created_by',
])]
class DocumentSource extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'type' => DocumentSourceType::class,
            'configuration' => 'array',
            'enabled' => 'boolean',
            'scan_interval_minutes' => 'integer',
            'last_scan_at' => 'datetime',
            'last_successful_scan_at' => 'datetime',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Relationships                                                       */
    /* ------------------------------------------------------------------ */

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /** @return HasMany<ScanLog, $this> */
    public function scanLogs(): HasMany
    {
        return $this->hasMany(ScanLog::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ------------------------------------------------------------------ */
    /* Scopes                                                              */
    /* ------------------------------------------------------------------ */

    /** @param Builder<static> $query */
    public function scopeEnabled(Builder $query): void
    {
        $query->where('enabled', true);
    }

    /**
     * Sources whose scan interval has elapsed.
     *
     * A source that has never been scanned is always due.
     *
     * @param Builder<static> $query
     */
    public function scopeDueForScan(Builder $query): void
    {
        $query->enabled()
            ->whereIn('type', array_map(
                fn (DocumentSourceType $t) => $t->value,
                array_filter(
                    DocumentSourceType::cases(),
                    fn (DocumentSourceType $t) => $t->isScannable() && $t->isImplemented(),
                ),
            ))
            ->where(function (Builder $q) {
                $q->whereNull('last_scan_at')
                    ->orWhereRaw("last_scan_at <= now() - (scan_interval_minutes * interval '1 minute')");
            });
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Read a value out of the type-specific configuration blob.
     *
     * Only non-sensitive identifiers ever live here (CLAUDE.md 11); secrets
     * are read from the environment by the Graph services instead.
     */
    public function config(string $key, mixed $default = null): mixed
    {
        return data_get($this->configuration, $key, $default);
    }

    public function isScannable(): bool
    {
        return $this->enabled
            && $this->type->isScannable()
            && $this->type->isImplemented();
    }

    /**
     * What to show operators in place of a raw path.
     *
     * UNC paths are not secret, but they map the internal network, so the
     * document list shows this instead - the full path stays behind the
     * source management screens (CLAUDE.md 12).
     */
    public function displayLocation(): string
    {
        return match (true) {
            $this->type === DocumentSourceType::UPLOAD => 'Manual upload',
            $this->type === DocumentSourceType::SHAREPOINT => (string) $this->config('folder_path', 'SharePoint'),
            default => basename((string) $this->path) ?: (string) $this->path,
        };
    }
}
