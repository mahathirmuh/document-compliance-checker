<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\DocumentSourceType;
use App\Models\DocumentSource;
use App\Models\User;

/**
 * Who may do what to a document source.
 *
 * Sources are the most sensitive object in the system: whoever can create one
 * decides which folders the application reads. That is deliberately limited
 * to ADMIN and above (CLAUDE.md 12, 22).
 */
class DocumentSourcePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->canManageSources();
    }

    public function view(User $user, DocumentSource $source): bool
    {
        return $user->role->canManageSources();
    }

    public function create(User $user): bool
    {
        return $user->role->canManageSources();
    }

    public function update(User $user, DocumentSource $source): bool
    {
        // The upload source is created by the application and has no
        // meaningful settings; letting it be edited would only allow it to be
        // broken.
        return $user->role->canManageSources()
            && $source->type !== DocumentSourceType::UPLOAD;
    }

    /**
     * Deleting a source cascades to every document indexed from it, taking
     * their compliance history with them, so it is SUPER_ADMIN only
     * (CLAUDE.md 35.20).
     */
    public function delete(User $user, DocumentSource $source): bool
    {
        return $user->isSuperAdmin()
            && $source->type !== DocumentSourceType::UPLOAD;
    }

    /** Trigger an immediate scan. */
    public function scan(User $user, DocumentSource $source): bool
    {
        return $user->role->canManageSources() && $source->isScannable();
    }

    public function testConnection(User $user, DocumentSource $source): bool
    {
        return $user->role->canManageSources();
    }

    /**
     * See the raw path or SharePoint identifiers.
     *
     * A UNC path maps the internal network, so it stays behind the source
     * screens rather than appearing in the document list.
     */
    public function viewLocation(User $user, DocumentSource $source): bool
    {
        return $user->role->canManageSources();
    }
}
