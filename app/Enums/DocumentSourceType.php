<?php

declare(strict_types=1);

namespace App\Enums;

enum DocumentSourceType: string
{
    /** A directory on a disk attached to the application server. */
    case WINDOWS_LOCAL = 'WINDOWS_LOCAL';

    /** A UNC path such as \\fileserver\DocumentControl\SOP. */
    case WINDOWS_SHARE = 'WINDOWS_SHARE';

    /** A NAS export mounted into the server filesystem. */
    case NAS = 'NAS';

    /** SharePoint or OneDrive reached through Microsoft Graph. */
    case SHAREPOINT = 'SHAREPOINT';

    /** Files pushed in through the web upload form. */
    case UPLOAD = 'UPLOAD';

    public function label(): string
    {
        return match ($this) {
            self::WINDOWS_LOCAL => 'Windows Local Folder',
            self::WINDOWS_SHARE => 'Windows Shared Folder',
            self::NAS => 'NAS / Mounted Folder',
            self::SHAREPOINT => 'SharePoint / OneDrive',
            self::UPLOAD => 'Manual Upload',
        };
    }

    /**
     * Whether this type is backed by a plain filesystem path.
     *
     * All three filesystem types share one adapter; they are kept as distinct
     * enum cases because operators reason about them differently and reports
     * are grouped by them.
     */
    public function isFilesystem(): bool
    {
        return in_array($this, [self::WINDOWS_LOCAL, self::WINDOWS_SHARE, self::NAS], true);
    }

    /** Whether a scheduled scan can walk this source looking for new files. */
    public function isScannable(): bool
    {
        return $this !== self::UPLOAD;
    }

    /** Types an administrator may create from the source management UI. */
    public static function creatable(): array
    {
        return [self::WINDOWS_LOCAL, self::WINDOWS_SHARE, self::NAS, self::SHAREPOINT];
    }

    /** Phase 3 delivers SharePoint; until then it can be registered but not scanned. */
    public function isImplemented(): bool
    {
        return $this !== self::SHAREPOINT;
    }
}
