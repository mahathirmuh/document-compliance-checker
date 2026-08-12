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

];
