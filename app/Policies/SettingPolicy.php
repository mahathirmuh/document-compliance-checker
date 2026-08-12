<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Setting;
use App\Models\User;

/**
 * Settings decide what "compliant" means, so changing one silently re-grades
 * the whole library. Restricted to ADMIN and above, and every change is
 * audited.
 */
class SettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->canManageSources();
    }

    public function update(User $user, Setting $setting): bool
    {
        return $user->role->canManageSources();
    }
}
