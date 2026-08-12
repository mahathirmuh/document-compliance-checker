<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Microsoft Graph
|--------------------------------------------------------------------------
|
| Application (app-only) authentication against Microsoft Entra ID
| (CLAUDE.md 11). Every value here is a credential or an endpoint - nothing
| in this file may be echoed to a UI, written to a log, or stored in the
| document_sources table.
|
| Certificate authentication is preferred in production: a certificate can be
| rotated on a schedule and cannot be copy-pasted out of a config screen the
| way a secret can. Client secret support exists for development.
|
*/

return [

    'tenant_id' => env('MS_GRAPH_TENANT_ID'),
    'client_id' => env('MS_GRAPH_CLIENT_ID'),

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | Whichever is present wins, certificate first. Both blank means Graph is
    | simply not configured, which the application treats as "SharePoint
    | sources cannot be scanned" rather than as an error.
    |
    */

    'certificate' => [
        'path' => env('MS_GRAPH_CERTIFICATE_PATH'),
        'password' => env('MS_GRAPH_CERTIFICATE_PASSWORD'),
    ],

    'client_secret' => env('MS_GRAPH_CLIENT_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Endpoints
    |--------------------------------------------------------------------------
    |
    | Overridable for sovereign clouds (GCC High, China, Germany) and for
    | pointing tests at a stub.
    |
    */

    'login_base_url' => env('MS_GRAPH_LOGIN_BASE_URL', 'https://login.microsoftonline.com'),
    'graph_base_url' => env('MS_GRAPH_BASE_URL', 'https://graph.microsoft.com'),
    'graph_version' => env('MS_GRAPH_VERSION', 'v1.0'),
    'scope' => env('MS_GRAPH_SCOPE', 'https://graph.microsoft.com/.default'),

    /*
    |--------------------------------------------------------------------------
    | HTTP behaviour
    |--------------------------------------------------------------------------
    |
    | Graph throttles aggressively on large libraries and answers 429 with a
    | Retry-After header. Honouring it is not optional: ignoring it gets the
    | application tenant-throttled, which affects every other Graph consumer
    | in the organisation, not just this scan.
    |
    */

    'timeout' => (int) env('MS_GRAPH_TIMEOUT', 60),
    'connect_timeout' => (int) env('MS_GRAPH_CONNECT_TIMEOUT', 10),
    'max_retries' => (int) env('MS_GRAPH_MAX_RETRIES', 4),
    'retry_base_delay_ms' => (int) env('MS_GRAPH_RETRY_BASE_DELAY_MS', 1000),
    'max_retry_after_seconds' => (int) env('MS_GRAPH_MAX_RETRY_AFTER', 120),

    /*
    |--------------------------------------------------------------------------
    | Paging and traversal
    |--------------------------------------------------------------------------
    */

    'page_size' => (int) env('MS_GRAPH_PAGE_SIZE', 200),
    'max_depth' => (int) env('MS_GRAPH_MAX_DEPTH', 12),

    /*
    |--------------------------------------------------------------------------
    | Token cache
    |--------------------------------------------------------------------------
    |
    | Graph app tokens last an hour. They are cached so a scan of a thousand
    | files does not request a thousand tokens, and expired slightly early so
    | a long request cannot start with a token that dies mid-flight.
    |
    */

    'token_cache_key' => 'msgraph.app_token',
    'token_expiry_leeway_seconds' => (int) env('MS_GRAPH_TOKEN_LEEWAY', 300),

];
