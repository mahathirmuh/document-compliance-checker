<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

/**
 * Who may do what to a document record.
 *
 * Reading the compliance status is open to every active user - that is the
 * point of the application. Everything that changes state, costs queue time,
 * or hands out file contents is restricted.
 */
class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canLogIn();
    }

    public function view(User $user, Document $document): bool
    {
        return $user->canLogIn();
    }

    public function upload(User $user): bool
    {
        return $user->role->canManageDocuments();
    }

    /** Re-queue a document for analysis. */
    public function reanalyze(User $user, Document $document): bool
    {
        return $user->role->canManageDocuments()
            && $document->analysis_status->isRetryable();
    }

    /** Correct the document code, title, type or revision. */
    public function updateMetadata(User $user, Document $document): bool
    {
        return $user->role->canManageDocuments();
    }

    /**
     * Download the file itself.
     *
     * Restricted more tightly than viewing the compliance result: the result
     * is a status, the file is the controlled document.
     */
    public function download(User $user, Document $document): bool
    {
        return $user->role->canManageDocuments();
    }
}
