<?php

declare(strict_types=1);

namespace App\Enums;

enum IssueSeverity: string
{
    case INFO = 'INFO';
    case WARNING = 'WARNING';
    case ERROR = 'ERROR';
    case CRITICAL = 'CRITICAL';

    public function label(): string
    {
        return match ($this) {
            self::INFO => 'Info',
            self::WARNING => 'Warning',
            self::ERROR => 'Error',
            self::CRITICAL => 'Critical',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::INFO => 1,
            self::WARNING => 2,
            self::ERROR => 3,
            self::CRITICAL => 4,
        };
    }

    public function cssClasses(): string
    {
        return match ($this) {
            self::INFO => 'bg-slate-100 text-slate-700 ring-slate-500/20',
            self::WARNING => 'bg-amber-50 text-amber-800 ring-amber-600/20',
            self::ERROR => 'bg-red-50 text-red-700 ring-red-600/20',
            self::CRITICAL => 'bg-red-600 text-white ring-red-700/40',
        };
    }
}
