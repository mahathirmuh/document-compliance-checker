<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
{
    case SUPER_ADMIN = 'SUPER_ADMIN';
    case ADMIN = 'ADMIN';
    case DOCUMENT_CONTROLLER = 'DOCUMENT_CONTROLLER';
    case REVIEWER = 'REVIEWER';
    case VIEWER = 'VIEWER';

    public function label(): string
    {
        return match ($this) {
            self::SUPER_ADMIN => 'Super Admin',
            self::ADMIN => 'Admin',
            self::DOCUMENT_CONTROLLER => 'Document Controller',
            self::REVIEWER => 'Reviewer',
            self::VIEWER => 'Viewer',
        };
    }

    /**
     * Rank used for "at least this role" checks.
     *
     * Higher is more privileged. Keep the gaps so a role can be inserted
     * later without renumbering the whole ladder.
     */
    public function rank(): int
    {
        return match ($this) {
            self::SUPER_ADMIN => 50,
            self::ADMIN => 40,
            self::DOCUMENT_CONTROLLER => 30,
            self::REVIEWER => 20,
            self::VIEWER => 10,
        };
    }

    public function atLeast(self $minimum): bool
    {
        return $this->rank() >= $minimum->rank();
    }

    /** May create, edit and delete document sources, and trigger scans. */
    public function canManageSources(): bool
    {
        return $this->atLeast(self::ADMIN);
    }

    /** May upload documents and re-queue analyses. */
    public function canManageDocuments(): bool
    {
        return $this->atLeast(self::DOCUMENT_CONTROLLER);
    }

    /** May read the audit trail. */
    public function canViewAuditLog(): bool
    {
        return $this->atLeast(self::ADMIN);
    }

    /** May create and deactivate user accounts. */
    public function canManageUsers(): bool
    {
        return $this === self::SUPER_ADMIN;
    }
}
