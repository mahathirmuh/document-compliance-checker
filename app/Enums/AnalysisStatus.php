<?php

declare(strict_types=1);

namespace App\Enums;

enum AnalysisStatus: string
{
    /** Discovered but not yet picked up by a worker. */
    case PENDING = 'PENDING';

    /** A worker is currently analysing this version. */
    case PROCESSING = 'PROCESSING';

    /** All required languages present and above their coverage thresholds. */
    case PASS = 'PASS';

    /** All required languages present, but at least one is under threshold. */
    case PARTIAL = 'PARTIAL';

    /** At least one required language was not detected at all. */
    case FAIL = 'FAIL';

    /** Analysed, but something needs a human decision (e.g. OCR required). */
    case REVIEW_REQUIRED = 'REVIEW_REQUIRED';

    /** The document could not be analysed. */
    case ERROR = 'ERROR';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::PROCESSING => 'Processing',
            self::PASS => 'Pass',
            self::PARTIAL => 'Partial',
            self::FAIL => 'Fail',
            self::REVIEW_REQUIRED => 'Review Required',
            self::ERROR => 'Error',
        };
    }

    /**
     * Tailwind classes for the status pill.
     *
     * Colour is decoration only - every pill also renders label() as text,
     * so the status stays readable without relying on colour (CLAUDE.md 34).
     */
    public function cssClasses(): string
    {
        return match ($this) {
            self::PENDING => 'bg-slate-100 text-slate-700 ring-slate-500/20',
            self::PROCESSING => 'bg-blue-50 text-blue-700 ring-blue-600/20',
            self::PASS => 'bg-green-50 text-green-700 ring-green-600/20',
            self::PARTIAL => 'bg-amber-50 text-amber-800 ring-amber-600/20',
            self::FAIL => 'bg-red-50 text-red-700 ring-red-600/20',
            self::REVIEW_REQUIRED => 'bg-purple-50 text-purple-700 ring-purple-600/20',
            self::ERROR => 'bg-zinc-800 text-zinc-100 ring-zinc-500/40',
        };
    }

    /** True once the analyser has finished with this version, whatever the result. */
    public function isTerminal(): bool
    {
        return ! in_array($this, [self::PENDING, self::PROCESSING], true);
    }

    /** Statuses that are safe to re-queue for another analysis run. */
    public function isRetryable(): bool
    {
        return in_array($this, [self::ERROR, self::REVIEW_REQUIRED, self::FAIL, self::PARTIAL], true);
    }

    /** @return array<int, self> */
    public static function dashboardOrder(): array
    {
        return [
            self::PASS,
            self::PARTIAL,
            self::FAIL,
            self::REVIEW_REQUIRED,
            self::PENDING,
            self::PROCESSING,
            self::ERROR,
        ];
    }
}
