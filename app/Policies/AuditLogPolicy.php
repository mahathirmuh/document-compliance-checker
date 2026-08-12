<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

/**
 * The audit trail is read-only to everyone, including SUPER_ADMIN.
 *
 * There are no update or delete abilities here on purpose: a trail an
 * administrator can edit is not a trail (CLAUDE.md 8.9).
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->canViewAuditLog();
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $user->role->canViewAuditLog();
    }
}
