<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'status'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'last_login_at' => 'datetime',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Relationships */
    /* ------------------------------------------------------------------ */

    /** @return HasMany<DocumentSource, $this> */
    public function documentSources(): HasMany
    {
        return $this->hasMany(DocumentSource::class, 'created_by');
    }

    /** @return HasMany<AuditLog, $this> */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /* ------------------------------------------------------------------ */
    /* Scopes */
    /* ------------------------------------------------------------------ */

    /** @param Builder<static> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', UserStatus::ACTIVE);
    }

    /* ------------------------------------------------------------------ */
    /* Authorisation helpers */
    /* ------------------------------------------------------------------ */

    /**
     * Whether this account is allowed to start a session at all.
     *
     * Checked on every request by the EnsureUserIsActive middleware, not just
     * at login, so deactivating an account ends its session immediately.
     */
    public function canLogIn(): bool
    {
        return $this->status->canLogIn();
    }

    public function hasRole(UserRole ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function atLeast(UserRole $minimum): bool
    {
        return $this->role->atLeast($minimum);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SUPER_ADMIN;
    }
}
