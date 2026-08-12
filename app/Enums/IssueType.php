<?php

declare(strict_types=1);

namespace App\Enums;

enum IssueType: string
{
    /** A required language was not found at all. */
    case LANGUAGE_MISSING = 'LANGUAGE_MISSING';

    /** A required language was found but fell short of its threshold. */
    case LOW_LANGUAGE_COVERAGE = 'LOW_LANGUAGE_COVERAGE';

    /** Text extraction failed or produced unusable output. */
    case PARSER_ERROR = 'PARSER_ERROR';

    /** Almost no extractable text - most likely a scanned image. */
    case OCR_REQUIRED = 'OCR_REQUIRED';

    /* --- Reserved for later phases; defined now so stored rows stay stable. --- */

    case TRANSLATION_MISMATCH = 'TRANSLATION_MISMATCH';
    case WRONG_LANGUAGE_ORDER = 'WRONG_LANGUAGE_ORDER';
    case WRONG_FONT_COLOR = 'WRONG_FONT_COLOR';
    case INVALID_TEMPLATE = 'INVALID_TEMPLATE';
    case MISSING_DOCUMENT_CODE = 'MISSING_DOCUMENT_CODE';
    case MISSING_REVISION = 'MISSING_REVISION';

    public function label(): string
    {
        return match ($this) {
            self::LANGUAGE_MISSING => 'Language Missing',
            self::LOW_LANGUAGE_COVERAGE => 'Low Language Coverage',
            self::PARSER_ERROR => 'Parser Error',
            self::OCR_REQUIRED => 'OCR Required',
            self::TRANSLATION_MISMATCH => 'Translation Mismatch',
            self::WRONG_LANGUAGE_ORDER => 'Wrong Language Order',
            self::WRONG_FONT_COLOR => 'Wrong Font Colour',
            self::INVALID_TEMPLATE => 'Invalid Template',
            self::MISSING_DOCUMENT_CODE => 'Missing Document Code',
            self::MISSING_REVISION => 'Missing Revision',
        };
    }

    public function defaultSeverity(): IssueSeverity
    {
        return match ($this) {
            self::LANGUAGE_MISSING => IssueSeverity::CRITICAL,
            self::LOW_LANGUAGE_COVERAGE => IssueSeverity::WARNING,
            self::PARSER_ERROR => IssueSeverity::ERROR,
            self::OCR_REQUIRED => IssueSeverity::WARNING,
            self::TRANSLATION_MISMATCH => IssueSeverity::WARNING,
            self::WRONG_LANGUAGE_ORDER => IssueSeverity::WARNING,
            self::WRONG_FONT_COLOR => IssueSeverity::INFO,
            self::INVALID_TEMPLATE => IssueSeverity::ERROR,
            self::MISSING_DOCUMENT_CODE => IssueSeverity::WARNING,
            self::MISSING_REVISION => IssueSeverity::WARNING,
        };
    }
}
