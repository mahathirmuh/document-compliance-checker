<?php

declare(strict_types=1);

namespace App\Enums;

enum IssueType: string
{
    /** A required language was not found at all. */
    case LANGUAGE_MISSING = 'LANGUAGE_MISSING';

    /** A required language was found but fell short of its threshold. */
    case LOW_LANGUAGE_COVERAGE = 'LOW_LANGUAGE_COVERAGE';

    /**
     * Every language cleared its own minimum, but one of them carries almost
     * all of the document. The minimums are absolute character counts and so
     * do not grow with the document; this is the gap they cannot see.
     */
    case UNBALANCED_LANGUAGES = 'UNBALANCED_LANGUAGES';

    /** Text extraction failed or produced unusable output. */
    case PARSER_ERROR = 'PARSER_ERROR';

    /** Almost no extractable text - most likely a scanned image. */
    case OCR_REQUIRED = 'OCR_REQUIRED';

    /** A section holds some languages but not all three. */
    case MISSING_SECTION_TRANSLATION = 'MISSING_SECTION_TRANSLATION';

    /** A section's translation is present but far shorter than its siblings. */
    case SHORT_TRANSLATION = 'SHORT_TRANSLATION';

    /**
     * A figure stated in one language is absent from its translations.
     *
     * The one content defect every other check passes cleanly: all three
     * languages present, balanced, in order, fully translated - and the dose
     * reads 5 ppm in English and 500 ppm in Indonesian.
     */
    case NUMERIC_MISMATCH = 'NUMERIC_MISMATCH';

    /* --- Raised by Document Control rules, only when the rule is enabled. --- */

    case WRONG_LANGUAGE_ORDER = 'WRONG_LANGUAGE_ORDER';
    case MISSING_DOCUMENT_CODE = 'MISSING_DOCUMENT_CODE';
    case MISSING_REVISION = 'MISSING_REVISION';
    case MISSING_HEADER = 'MISSING_HEADER';
    case MISSING_FOOTER = 'MISSING_FOOTER';
    case INVALID_COVER_PAGE = 'INVALID_COVER_PAGE';
    case WRONG_FONT_COLOR = 'WRONG_FONT_COLOR';

    /* --- Reserved; defined now so stored rows stay stable. --- */

    case TRANSLATION_MISMATCH = 'TRANSLATION_MISMATCH';
    case INVALID_TEMPLATE = 'INVALID_TEMPLATE';

    public function label(): string
    {
        return match ($this) {
            self::LANGUAGE_MISSING => 'Language Missing',
            self::LOW_LANGUAGE_COVERAGE => 'Low Language Coverage',
            self::UNBALANCED_LANGUAGES => 'Unbalanced Languages',
            self::PARSER_ERROR => 'Parser Error',
            self::OCR_REQUIRED => 'OCR Required',
            self::MISSING_SECTION_TRANSLATION => 'Section Not Translated',
            self::SHORT_TRANSLATION => 'Short Translation',
            self::NUMERIC_MISMATCH => 'Numeric Mismatch',
            self::TRANSLATION_MISMATCH => 'Translation Mismatch',
            self::WRONG_LANGUAGE_ORDER => 'Wrong Language Order',
            self::WRONG_FONT_COLOR => 'Wrong Font Colour',
            self::INVALID_TEMPLATE => 'Invalid Template',
            self::MISSING_DOCUMENT_CODE => 'Missing Document Code',
            self::MISSING_REVISION => 'Missing Revision',
            self::MISSING_HEADER => 'Missing Header',
            self::MISSING_FOOTER => 'Missing Footer',
            self::INVALID_COVER_PAGE => 'Cover Page Problem',
        };
    }

    public function defaultSeverity(): IssueSeverity
    {
        return match ($this) {
            self::LANGUAGE_MISSING => IssueSeverity::CRITICAL,
            self::LOW_LANGUAGE_COVERAGE => IssueSeverity::WARNING,
            self::UNBALANCED_LANGUAGES => IssueSeverity::WARNING,
            self::PARSER_ERROR => IssueSeverity::ERROR,
            self::OCR_REQUIRED => IssueSeverity::WARNING,

            // A section with no translation is a real gap a Document
            // Controller must close; a short one is advisory, because a
            // legitimately terse translation looks identical from here
            // (CLAUDE.md 33).
            self::MISSING_SECTION_TRANSLATION => IssueSeverity::WARNING,
            self::SHORT_TRANSLATION => IssueSeverity::INFO,

            // WARNING rather than ERROR, despite being the most consequential
            // finding here. The rule compares extracted text, and extraction
            // is imperfect on the formats these documents arrive in - so this
            // asks a human to check a figure, it does not assert one is wrong.
            self::NUMERIC_MISMATCH => IssueSeverity::WARNING,

            self::TRANSLATION_MISMATCH => IssueSeverity::WARNING,
            self::WRONG_LANGUAGE_ORDER => IssueSeverity::WARNING,
            self::WRONG_FONT_COLOR => IssueSeverity::INFO,
            self::INVALID_TEMPLATE => IssueSeverity::ERROR,
            self::MISSING_DOCUMENT_CODE => IssueSeverity::WARNING,
            self::MISSING_REVISION => IssueSeverity::WARNING,
            self::MISSING_HEADER => IssueSeverity::WARNING,
            self::MISSING_FOOTER => IssueSeverity::WARNING,
            self::INVALID_COVER_PAGE => IssueSeverity::INFO,
        };
    }
}
