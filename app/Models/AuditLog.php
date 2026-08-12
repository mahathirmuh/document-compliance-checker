<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An append-only audit entry.
 *
 * There is no `updated_at`: audit rows are written once and never touched
 * again. Nothing in the application should update or delete one.
 */
#[Fillable([
    'user_id', 'user_email', 'action', 'entity_type', 'entity_id',
    'old_values', 'new_values', 'ip_address', 'user_agent',
])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Falls back to the denormalised email once the account is gone. */
    public function actorName(): string
    {
        return $this->user?->name
            ?? $this->user_email
            ?? 'System';
    }
}
