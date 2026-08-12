<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One runtime-editable setting.
 *
 * Values are stored as text and cast on read according to `type`, so the
 * table can hold thresholds, flags and lists without a column per shape.
 * Read through SettingsService rather than touching this model directly -
 * that is where the config fallback and caching live.
 */
#[Fillable(['key', 'value', 'type', 'group', 'label', 'description', 'updated_by'])]
class Setting extends Model
{
    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** The stored text, cast to the shape declared by `type`. */
    public function typedValue(): mixed
    {
        if ($this->value === null) {
            return null;
        }

        return match ($this->type) {
            'integer' => (int) $this->value,
            'float' => (float) $this->value,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOL),
            'json' => json_decode($this->value, true),
            default => $this->value,
        };
    }

    /** Serialise a PHP value into the text column according to `type`. */
    public static function encode(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => $value ? '1' : '0',
            'json' => json_encode($value, JSON_THROW_ON_ERROR),
            default => (string) $value,
        };
    }
}
