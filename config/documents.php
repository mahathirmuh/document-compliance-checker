<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Document Control configuration
|--------------------------------------------------------------------------
|
| These are fallback defaults only. Anything an administrator is allowed to
| change at runtime lives in the `settings` table and is read through
| App\Services\Settings\SettingsService, which falls back to these values.
| Never hard-code a threshold anywhere else in the application.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Trilingual thresholds
    |--------------------------------------------------------------------------
    |
    | Minimum amount of meaningful text a language must contribute before it
    | counts as genuinely present. Chinese is counted in Han characters, not
    | words, because Chinese text has no whitespace word boundaries.
    |
    */

    'thresholds' => [
        'min_chars_en' => (int) env('DOCCHECK_MIN_CHARS_EN', 100),
        'min_chars_id' => (int) env('DOCCHECK_MIN_CHARS_ID', 100),
        'min_chars_zh' => (int) env('DOCCHECK_MIN_CHARS_ZH', 50),
        'min_compliance_score' => (float) env('DOCCHECK_MIN_COMPLIANCE_SCORE', 80),
    ],

    /*
    |--------------------------------------------------------------------------
    | Compliance score
    |--------------------------------------------------------------------------
    |
    | The score answers two questions, not one:
    |
    |   adequacy - did each language clear its own minimum?
    |   balance  - is the document actually trilingual, or overwhelmingly
    |              one language with the others just past the bar?
    |
    | Adequacy alone gives a document of 8,000 English and 900 Indonesian
    | characters the same 100% as a perfectly balanced one, because both
    | cleared every minimum. That reads as a clean bill of health for a
    | document nobody would call trilingual.
    |
    */

    'scoring' => [

        // A language is expected to carry at least this share of an even
        // split before it counts as fully represented. At 0.5 a language
        // needs half of a third of the document - deliberately lenient,
        // because translations are rarely the same length.
        'balance_tolerance' => (float) env('DOCCHECK_BALANCE_TOLERANCE', 0.5),

        // Chinese conveys roughly three times as much per character, so the
        // balance comparison is made on density-normalised lengths. Without
        // this a correctly translated Chinese section always looks
        // under-represented. Mirrors ANALYZER_CHINESE_DENSITY_FACTOR.
        'chinese_density_factor' => (float) env('DOCCHECK_CHINESE_DENSITY_FACTOR', 3.0),

    ],

    /*
    |--------------------------------------------------------------------------
    | File formats
    |--------------------------------------------------------------------------
    |
    | `scannable` is what a folder scan will pick up. `uploadable` is what a
    | user may push through the upload form - it is deliberately the narrower
    | list. `blocked` is an explicit deny list that is checked first and is
    | never overridable from the settings UI.
    |
    */

    'extensions' => [
        'scannable' => ['docx', 'pdf', 'xlsx', 'txt'],
        'uploadable' => ['docx', 'pdf', 'xlsx', 'txt'],
        'blocked' => [
            'exe', 'bat', 'cmd', 'ps1', 'psm1', 'msi', 'dll', 'js', 'jse',
            'vbs', 'vbe', 'com', 'scr', 'cpl', 'jar', 'hta', 'lnk', 'reg',
            'sh', 'php', 'phar', 'py', 'wsf', 'msc', 'pif',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Accepted MIME types per extension
    |--------------------------------------------------------------------------
    |
    | An extension is never trusted on its own. The detected MIME type of the
    | uploaded bytes must also appear in the list for that extension.
    |
    */

    'mime_types' => [
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
        ],
        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
        ],
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Upload limits
    |--------------------------------------------------------------------------
    */

    'upload' => [
        'max_size_kb' => (int) env('DOCCHECK_MAX_UPLOAD_KB', 65536),
        'disk' => env('DOCCHECK_UPLOAD_DISK', 'documents'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scanning
    |--------------------------------------------------------------------------
    |
    | `max_depth` bounds recursion so a mis-configured source (for example a
    | drive root) cannot walk an unbounded tree. `hash_chunk_bytes` keeps
    | hashing streamed rather than loading whole files into memory.
    |
    */

    'scan' => [
        'default_interval_minutes' => (int) env('DOCCHECK_DEFAULT_SCAN_INTERVAL', 60),
        'max_depth' => (int) env('DOCCHECK_SCAN_MAX_DEPTH', 12),
        'max_files_per_scan' => (int) env('DOCCHECK_SCAN_MAX_FILES', 20000),
        'hash_algorithm' => 'sha256',
        'hash_chunk_bytes' => 1048576,
    ],

    /*
    |--------------------------------------------------------------------------
    | Temporary files
    |--------------------------------------------------------------------------
    */

    'temporary' => [
        'retention_hours' => (int) env('DOCCHECK_TEMP_RETENTION_HOURS', 24),
        'disk' => 'temporary',
    ],

    /*
    |--------------------------------------------------------------------------
    | Python analyzer service
    |--------------------------------------------------------------------------
    |
    | Phase 1 ships with the analyzer disabled: documents are queued and left
    | at PENDING. Turning `enabled` on is all that is needed once the Phase 2
    | FastAPI service is running.
    |
    */

    'analyzer' => [
        'enabled' => (bool) env('ANALYZER_ENABLED', false),
        'base_url' => env('ANALYZER_BASE_URL', 'http://127.0.0.1:8001'),
        'api_key' => env('ANALYZER_API_KEY'),
        'timeout' => (int) env('ANALYZER_TIMEOUT', 120),
        'api_version' => 'v1',
    ],

    /*
    |--------------------------------------------------------------------------
    | Optional analysis features
    |--------------------------------------------------------------------------
    */

    'features' => [
        'ocr_enabled' => (bool) env('DOCCHECK_OCR_ENABLED', false),
        'ai_semantic_enabled' => (bool) env('DOCCHECK_AI_SEMANTIC_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Document Control rules
    |--------------------------------------------------------------------------
    |
    | Sent to the analyzer with each request. All off by default: a rule is a
    | statement about how this organisation's documents are supposed to look,
    | and that is a decision a Document Controller makes, not a default the
    | application should impose (CLAUDE.md 7, 27 Phase 5).
    |
    | These are the fallbacks; the live values come from the settings table.
    |
    */

    'rules' => [

        // Translations should appear in this order within each section. Only
        // checked where a document declares its own sections - a page break
        // says nothing about the order an author intended.
        'language_order' => [
            'enabled' => false,
            'order' => ['en', 'id', 'zh'],
        ],

        // Also extracts the code and revision, which are written back to the
        // document record when it has none.
        'document_code' => [
            'enabled' => false,
            'require_code' => true,
            'require_revision' => true,
            'code_pattern' => null,      // null uses the analyzer default
            'revision_pattern' => null,
        ],

        'header_footer' => [
            'enabled' => false,
            'require_header' => true,
            'require_footer' => true,
        ],

        'cover_page' => [
            'enabled' => false,
            'require_code' => true,
        ],

        // Word documents only. A PDF reports "not checked" rather than
        // passing, because an unreadable format must never look compliant.
        'font_color' => [
            'enabled' => false,
            'allowed' => ['000000'],
        ],

    ],

];
