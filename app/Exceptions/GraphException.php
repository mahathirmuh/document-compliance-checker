<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A Microsoft Graph call failed.
 *
 * Messages on this exception are written for an operator and are safe to show
 * in the UI: they never carry a token, a client secret, a certificate path or
 * a full request URL. The underlying detail is logged instead (CLAUDE.md 30).
 */
class GraphException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,

        /** Graph's own error code, e.g. "itemNotFound" or "accessDenied". */
        public readonly ?string $graphCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public static function notConfigured(): self
    {
        return new self(
            'Microsoft Graph is not configured. Set the tenant, client and credential '
            .'values in the server environment before using a SharePoint source.',
        );
    }

    public static function authenticationFailed(?string $graphCode = null, ?Throwable $previous = null): self
    {
        return new self(
            'Could not authenticate to Microsoft Graph. Check the tenant ID, client ID '
            .'and that the certificate or secret has not expired.',
            status: 401,
            graphCode: $graphCode,
            previous: $previous,
        );
    }

    public static function accessDenied(): self
    {
        return new self(
            'Microsoft Graph refused access. The application registration most likely '
            .'lacks the required application permission, or admin consent has not been granted.',
            status: 403,
            graphCode: 'accessDenied',
        );
    }

    public static function notFound(string $what): self
    {
        return new self("The requested {$what} does not exist or is not visible to this application.", status: 404);
    }

    public static function throttled(): self
    {
        return new self(
            'Microsoft Graph is throttling this application. The scan will be retried later.',
            status: 429,
        );
    }

    /** True when retrying the same call later could reasonably succeed. */
    public function isTransient(): bool
    {
        return in_array($this->status, [429, 500, 502, 503, 504], true);
    }
}
