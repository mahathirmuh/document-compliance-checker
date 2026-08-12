<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Writes the administrative audit trail (CLAUDE.md 8.9, 12).
 *
 * Every value that goes into old_values / new_values passes through redact(),
 * so a secret that should never have been in a model attribute in the first
 * place still cannot reach the audit table - which is readable by any admin
 * and is the one table nobody ever prunes.
 */
class AuditLogger
{
    /**
     * Attribute names whose values are replaced with a marker.
     *
     * Matched as substrings and case-insensitively, so `client_secret`,
     * `MS_GRAPH_CLIENT_SECRET` and `secretKey` are all caught.
     *
     * @var array<int, string>
     */
    private const REDACTED_KEYS = [
        'password', 'secret', 'token', 'credential', 'certificate',
        'api_key', 'apikey', 'private_key', 'remember_token', 'authorization',
    ];

    private const REDACTED_MARKER = '[redacted]';

    /* --- Action names. Kept as constants so reports can group on them. --- */

    public const ACTION_LOGIN = 'auth.login';

    public const ACTION_LOGIN_FAILED = 'auth.login_failed';

    public const ACTION_LOGOUT = 'auth.logout';

    public const ACTION_SOURCE_CREATED = 'source.created';

    public const ACTION_SOURCE_UPDATED = 'source.updated';

    public const ACTION_SOURCE_DELETED = 'source.deleted';

    public const ACTION_SOURCE_SCAN_REQUESTED = 'source.scan_requested';

    public const ACTION_DOCUMENT_UPLOADED = 'document.uploaded';

    public const ACTION_DOCUMENT_REANALYZE_REQUESTED = 'document.reanalyze_requested';

    public const ACTION_SETTING_UPDATED = 'setting.updated';

    /**
     * Record an action.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function log(
        string $action,
        ?Model $entity = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?User $user = null,
    ): AuditLog {
        $actor = $user ?? Auth::user();

        return AuditLog::create([
            'user_id' => $actor?->id,
            'user_email' => $actor?->email,
            'action' => $action,
            'entity_type' => $entity === null ? null : class_basename($entity),
            'entity_id' => $entity?->getKey(),
            'old_values' => $oldValues === null ? null : $this->redact($oldValues),
            'new_values' => $newValues === null ? null : $this->redact($newValues),
            'ip_address' => $this->clientIp(),
            'user_agent' => $this->userAgent(),
        ]);
    }

    /**
     * Record a model change, storing only the attributes that actually moved.
     *
     * Logging the whole model on every save would bury the one field that
     * changed and bloat the table for no investigative benefit.
     */
    public function logChanges(string $action, Model $model, array $original): AuditLog
    {
        $changed = $model->getChanges();
        unset($changed['updated_at']);

        $before = array_intersect_key($original, $changed);

        return $this->log($action, $model, $before ?: null, $changed ?: null);
    }

    /**
     * Replace sensitive values, walking nested arrays.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function redact(array $values): array
    {
        $redacted = [];

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $redacted[$key] = $this->redact($value);

                continue;
            }

            $redacted[$key] = $this->isSensitive((string) $key)
                ? self::REDACTED_MARKER
                : $value;
        }

        return $redacted;
    }

    private function isSensitive(string $key): bool
    {
        $normalized = mb_strtolower($key);

        foreach (self::REDACTED_KEYS as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The request IP, or null when running from the console.
     *
     * Scheduled scans and queue workers have no request, and asking for one
     * outside HTTP throws rather than returning null.
     */
    private function clientIp(): ?string
    {
        return app()->runningInConsole() ? null : Request::ip();
    }

    private function userAgent(): ?string
    {
        if (app()->runningInConsole()) {
            return 'console';
        }

        $agent = Request::userAgent();

        return $agent === null ? null : mb_substr($agent, 0, 512);
    }
}
