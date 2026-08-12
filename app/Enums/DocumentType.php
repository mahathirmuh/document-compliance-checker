<?php

declare(strict_types=1);

namespace App\Enums;

enum DocumentType: string
{
    case SOP = 'SOP';
    case POLICY = 'POLICY';
    case WORK_INSTRUCTION = 'WORK_INSTRUCTION';
    case GUIDELINE = 'GUIDELINE';
    case MANUAL = 'MANUAL';
    case FORM = 'FORM';
    case RECORD = 'RECORD';
    case REPORT = 'REPORT';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::SOP => 'SOP',
            self::POLICY => 'Policy',
            self::WORK_INSTRUCTION => 'Work Instruction',
            self::GUIDELINE => 'Guideline',
            self::MANUAL => 'Manual',
            self::FORM => 'Form',
            self::RECORD => 'Record',
            self::REPORT => 'Report',
            self::OTHER => 'Other',
        };
    }

    /**
     * Filename fragments that hint at a document type.
     *
     * This is only a first guess used to pre-fill the type when a file is
     * discovered - a Document Controller can always correct it, and the
     * stored value is never overwritten by a later scan.
     *
     * @return array<int, string>
     */
    public function filenameHints(): array
    {
        return match ($this) {
            self::SOP => ['sop', 'standard operating'],
            self::POLICY => ['policy', 'kebijakan'],
            self::WORK_INSTRUCTION => ['wi-', 'wi_', 'work instruction', 'instruksi kerja'],
            self::GUIDELINE => ['guideline', 'panduan', 'pedoman'],
            self::MANUAL => ['manual', 'buku panduan'],
            self::FORM => ['form', 'formulir', 'frm-', 'frm_'],
            self::RECORD => ['record', 'rekaman', 'catatan'],
            self::REPORT => ['report', 'laporan'],
            self::OTHER => [],
        };
    }

    /**
     * Best-effort type guess from a file name.
     *
     * Order matters: the longest, most specific hints are checked first so
     * that "Work Instruction Form" does not resolve to FORM.
     */
    public static function guessFromFileName(string $fileName): self
    {
        $haystack = mb_strtolower($fileName);

        $candidates = [
            self::WORK_INSTRUCTION,
            self::GUIDELINE,
            self::POLICY,
            self::MANUAL,
            self::REPORT,
            self::RECORD,
            self::SOP,
            self::FORM,
        ];

        foreach ($candidates as $type) {
            foreach ($type->filenameHints() as $hint) {
                if (str_contains($haystack, $hint)) {
                    return $type;
                }
            }
        }

        return self::OTHER;
    }
}
