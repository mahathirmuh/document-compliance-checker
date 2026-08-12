<?php

declare(strict_types=1);

namespace App\Enums;

enum ScanStatus: string
{
    case RUNNING = 'RUNNING';
    case COMPLETED = 'COMPLETED';

    /** Finished, but individual files failed along the way. */
    case COMPLETED_WITH_ERRORS = 'COMPLETED_WITH_ERRORS';

    /** Aborted - the source was unreachable or the scan itself threw. */
    case FAILED = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::RUNNING => 'Running',
            self::COMPLETED => 'Completed',
            self::COMPLETED_WITH_ERRORS => 'Completed with errors',
            self::FAILED => 'Failed',
        };
    }

    public function cssClasses(): string
    {
        return match ($this) {
            self::RUNNING => 'bg-blue-50 text-blue-700 ring-blue-600/20',
            self::COMPLETED => 'bg-green-50 text-green-700 ring-green-600/20',
            self::COMPLETED_WITH_ERRORS => 'bg-amber-50 text-amber-800 ring-amber-600/20',
            self::FAILED => 'bg-red-50 text-red-700 ring-red-600/20',
        };
    }
}
