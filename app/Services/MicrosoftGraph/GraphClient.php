<?php

declare(strict_types=1);

namespace App\Services\MicrosoftGraph;

use App\Exceptions\GraphException;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Low-level Microsoft Graph transport.
 *
 * Deliberately built on Laravel's HTTP client rather than the Microsoft Graph
 * SDK. The SDK is a large dependency whose model classes would leak into the
 * source adapters, and the handful of endpoints this application needs is
 * small enough that owning the calls keeps Laravel loosely coupled to Graph -
 * which is the rule for the analyzer too (CLAUDE.md 35.8).
 *
 * Everything that talks to Graph goes through here, so throttling, token
 * renewal and error translation are handled once.
 */
class GraphClient
{
    public function __construct(private readonly GraphAuthService $auth) {}

    /**
     * GET a Graph resource and return the decoded body.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws GraphException
     */
    public function get(string $path, array $query = []): array
    {
        $response = $this->send('GET', $this->absoluteUrl($path), $query);
        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    /**
     * Walk a paged collection, yielding each entry.
     *
     * A generator rather than an array: a document library can hold tens of
     * thousands of items, and the scanner consumes them one at a time.
     *
     * @param  array<string, mixed>  $query
     * @return Generator<int, array<string, mixed>>
     *
     * @throws GraphException
     */
    public function paginate(string $path, array $query = []): Generator
    {
        $url = $this->absoluteUrl($path);
        $pageQuery = $query;
        $pagesFetched = 0;

        while ($url !== null) {
            $response = $this->send('GET', $url, $pageQuery);
            $body = $response->json();

            if (! is_array($body)) {
                return;
            }

            foreach (($body['value'] ?? []) as $item) {
                if (is_array($item)) {
                    yield $item;
                }
            }

            $next = $body['@odata.nextLink'] ?? null;
            $url = is_string($next) && $next !== '' ? $next : null;

            // nextLink already carries every parameter, including the skip
            // token. Re-appending the original query would corrupt it.
            $pageQuery = [];
            $pagesFetched++;

            if ($pagesFetched > 10_000) {
                Log::warning('Graph pagination stopped at the safety limit.', ['path' => $path]);

                return;
            }
        }
    }

    /**
     * Stream a file's content to a local path.
     *
     * Streamed to disk rather than buffered: a scanned SOP can be hundreds of
     * megabytes and holding one in a queue worker's memory would take the
     * worker down (CLAUDE.md 35.12).
     *
     * @throws GraphException
     */
    public function download(string $path, string $destination): void
    {
        $url = $this->absoluteUrl($path);

        $attempt = 0;
        $maxRetries = (int) config('microsoft_graph.max_retries', 4);

        while (true) {
            $attempt++;

            try {
                $response = $this->request()
                    ->withOptions(['sink' => $destination])
                    ->get($url);
            } catch (ConnectionException $e) {
                throw new GraphException('Microsoft Graph could not be reached.', previous: $e);
            }

            if ($response->successful()) {
                return;
            }

            if ($this->shouldRetry($response, $attempt, $maxRetries)) {
                continue;
            }

            throw $this->translate($response, 'file content');
        }
    }

    /* ------------------------------------------------------------------ */
    /* Internals */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws GraphException
     */
    private function send(string $method, string $url, array $query = []): Response
    {
        $attempt = 0;
        $maxRetries = (int) config('microsoft_graph.max_retries', 4);
        $reauthenticated = false;

        while (true) {
            $attempt++;

            try {
                $response = $this->request()->send($method, $url, ['query' => $query]);
            } catch (ConnectionException $e) {
                throw new GraphException('Microsoft Graph could not be reached.', previous: $e);
            }

            if ($response->successful()) {
                return $response;
            }

            // A token can be revoked mid-scan. One forced re-authentication is
            // worth trying; a second 401 is a real configuration problem.
            if ($response->status() === 401 && ! $reauthenticated) {
                $reauthenticated = true;
                $this->auth->forgetToken();

                continue;
            }

            if ($this->shouldRetry($response, $attempt, $maxRetries)) {
                continue;
            }

            throw $this->translate($response, 'Graph resource');
        }
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->auth->accessToken())
            ->acceptJson()
            ->timeout((int) config('microsoft_graph.timeout', 60))
            ->connectTimeout((int) config('microsoft_graph.connect_timeout', 10));
    }

    /**
     * Decide whether to retry, sleeping first if so.
     *
     * Graph answers 429 with a Retry-After telling you exactly how long to
     * wait. Ignoring it escalates to a tenant-wide throttle that affects every
     * other Graph consumer in the organisation, so the header wins over our
     * own backoff whenever it is present.
     */
    private function shouldRetry(Response $response, int $attempt, int $maxRetries): bool
    {
        $status = $response->status();
        $retryable = $status === 429 || $status >= 500;

        if (! $retryable || $attempt > $maxRetries) {
            return false;
        }

        $retryAfter = (int) $response->header('Retry-After');
        $cap = (int) config('microsoft_graph.max_retry_after_seconds', 120);

        if ($retryAfter > 0) {
            $delayMs = min($retryAfter, $cap) * 1000;
        } else {
            // Exponential backoff when Graph does not say: 1s, 2s, 4s, 8s.
            $base = (int) config('microsoft_graph.retry_base_delay_ms', 1000);
            $delayMs = min($base * (2 ** ($attempt - 1)), $cap * 1000);
        }

        Log::info('Retrying a Microsoft Graph request.', [
            'status' => $status,
            'attempt' => $attempt,
            'delay_ms' => $delayMs,
        ]);

        usleep($delayMs * 1000);

        return true;
    }

    /** Turn a failed response into an operator-readable exception. */
    private function translate(Response $response, string $what): GraphException
    {
        $status = $response->status();
        $code = $response->json('error.code');
        $code = is_string($code) ? $code : null;

        // The Graph message can contain the requested path and site names, so
        // it is logged rather than returned to the caller.
        Log::error('Microsoft Graph request failed.', [
            'status' => $status,
            'graph_code' => $code,
        ]);

        return match (true) {
            $status === 401 => GraphException::authenticationFailed($code),
            $status === 403 => GraphException::accessDenied(),
            $status === 404 => GraphException::notFound($what),
            $status === 429 => GraphException::throttled(),
            default => new GraphException(
                "Microsoft Graph returned HTTP {$status} while reading the {$what}.",
                status: $status,
                graphCode: $code,
            ),
        };
    }

    /** Absolute URLs (nextLink) pass through; relative paths get the base. */
    private function absoluteUrl(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $base = rtrim((string) config('microsoft_graph.graph_base_url'), '/');
        $version = trim((string) config('microsoft_graph.graph_version'), '/');

        return "{$base}/{$version}/".ltrim($path, '/');
    }
}
