<?php

declare(strict_types=1);

namespace App\Services\MicrosoftGraph;

use App\Exceptions\GraphException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Obtains app-only access tokens from Microsoft Entra ID (CLAUDE.md 11).
 *
 * Two grants are supported. Certificate authentication is preferred and is
 * tried first: it proves possession of a private key that never leaves the
 * server, where a client secret is a bearer string that anyone who reads a
 * config file can replay. Secret support exists so development does not need
 * a certificate.
 *
 * Nothing in this class may log a token, a secret, an assertion or a
 * certificate password - not even at debug level, and not inside an exception
 * message (CLAUDE.md 30).
 */
class GraphAuthService
{
    /** Signed assertions are short-lived; ten minutes is the Microsoft guidance. */
    private const ASSERTION_LIFETIME_SECONDS = 600;

    /**
     * A cached access token, acquiring one if necessary.
     *
     * @throws GraphException
     */
    public function accessToken(): string
    {
        $cacheKey = (string) config('microsoft_graph.token_cache_key');
        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        [$token, $expiresIn] = $this->requestToken();

        // Expire early so a request that starts just before the boundary
        // cannot reach Graph with a token that died in flight.
        $leeway = (int) config('microsoft_graph.token_expiry_leeway_seconds', 300);
        $ttl = max($expiresIn - $leeway, 60);

        Cache::put($cacheKey, $token, $ttl);

        return $token;
    }

    /**
     * Drop the cached token.
     *
     * Called after a 401 so the next attempt re-authenticates rather than
     * replaying a token that Entra has already rejected.
     */
    public function forgetToken(): void
    {
        Cache::forget((string) config('microsoft_graph.token_cache_key'));
    }

    /** Whether enough configuration exists to attempt authentication at all. */
    public function isConfigured(): bool
    {
        return filled(config('microsoft_graph.tenant_id'))
            && filled(config('microsoft_graph.client_id'))
            && ($this->hasCertificate() || filled(config('microsoft_graph.client_secret')));
    }

    /** Which grant will be used, for display on the settings screen. */
    public function credentialType(): string
    {
        return match (true) {
            $this->hasCertificate() => 'certificate',
            filled(config('microsoft_graph.client_secret')) => 'client_secret',
            default => 'none',
        };
    }

    /* ------------------------------------------------------------------ */
    /* Internals */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{0: string, 1: int} token and its lifetime in seconds
     *
     * @throws GraphException
     */
    private function requestToken(): array
    {
        if (! $this->isConfigured()) {
            throw GraphException::notConfigured();
        }

        $endpoint = $this->tokenEndpoint();

        $payload = [
            'grant_type' => 'client_credentials',
            'client_id' => (string) config('microsoft_graph.client_id'),
            'scope' => (string) config('microsoft_graph.scope'),
        ];

        if ($this->hasCertificate()) {
            $payload['client_assertion_type'] = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';
            $payload['client_assertion'] = $this->buildClientAssertion($endpoint);
        } else {
            $payload['client_secret'] = (string) config('microsoft_graph.client_secret');
        }

        try {
            $response = Http::asForm()
                ->timeout((int) config('microsoft_graph.timeout', 60))
                ->connectTimeout((int) config('microsoft_graph.connect_timeout', 10))
                ->post($endpoint, $payload);
        } catch (ConnectionException $e) {
            throw new GraphException(
                'Microsoft Entra ID could not be reached. Check the server\'s outbound network access.',
                previous: $e,
            );
        }

        if ($response->failed()) {
            // Entra echoes the request back in its error body, which for the
            // secret grant includes the secret. Only the error *code* is
            // logged; the body is never touched.
            $code = (string) ($response->json('error') ?? 'unknown_error');

            Log::error('Microsoft Graph token request failed.', [
                'status' => $response->status(),
                'error' => $code,
            ]);

            throw GraphException::authenticationFailed($code);
        }

        $token = $response->json('access_token');
        $expiresIn = (int) ($response->json('expires_in') ?? 3600);

        if (! is_string($token) || $token === '') {
            throw GraphException::authenticationFailed('missing_access_token');
        }

        Log::info('Microsoft Graph token acquired.', [
            'credential_type' => $this->credentialType(),
            'expires_in' => $expiresIn,
        ]);

        return [$token, $expiresIn];
    }

    private function tokenEndpoint(): string
    {
        $base = rtrim((string) config('microsoft_graph.login_base_url'), '/');
        $tenant = (string) config('microsoft_graph.tenant_id');

        return "{$base}/{$tenant}/oauth2/v2.0/token";
    }

    private function hasCertificate(): bool
    {
        $path = config('microsoft_graph.certificate.path');

        return is_string($path) && $path !== '' && is_readable($path);
    }

    /**
     * Build and sign the JWT client assertion.
     *
     * The x5t header carries the certificate's SHA-1 thumbprint, which is how
     * Entra knows which of the registered certificates to verify against.
     * SHA-1 here is not a security choice - it is the identifier format the
     * protocol specifies; the signature itself is RS256.
     *
     * @throws GraphException
     */
    private function buildClientAssertion(string $audience): string
    {
        [$privateKey, $certificate] = $this->loadCertificate();

        $thumbprint = openssl_x509_fingerprint($certificate, 'sha1', true);

        if ($thumbprint === false) {
            throw new GraphException('The Graph certificate thumbprint could not be computed.');
        }

        $clientId = (string) config('microsoft_graph.client_id');
        $now = time();

        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
            'x5t' => $this->base64UrlEncode($thumbprint),
        ];

        $claims = [
            'aud' => $audience,
            'iss' => $clientId,
            'sub' => $clientId,
            'jti' => (string) Str::uuid(),
            'nbf' => $now,
            'exp' => $now + self::ASSERTION_LIFETIME_SECONDS,
        ];

        $signingInput = $this->base64UrlEncode($this->encodeJson($header))
            .'.'
            .$this->base64UrlEncode($this->encodeJson($claims));

        $signature = '';

        if (! openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new GraphException('The Graph client assertion could not be signed.');
        }

        return $signingInput.'.'.$this->base64UrlEncode($signature);
    }

    /**
     * Load the private key and certificate.
     *
     * Accepts a PKCS#12 bundle (.pfx / .p12), which is what the Windows
     * certificate store exports, and falls back to a PEM file holding both.
     *
     * @return array{0: \OpenSSLAsymmetricKey, 1: \OpenSSLCertificate|string}
     *
     * @throws GraphException
     */
    private function loadCertificate(): array
    {
        $path = (string) config('microsoft_graph.certificate.path');
        $password = (string) (config('microsoft_graph.certificate.password') ?? '');

        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new GraphException('The Graph certificate file could not be read by the application account.');
        }

        $bundle = [];

        if (@openssl_pkcs12_read($contents, $bundle, $password)) {
            $privateKey = openssl_pkey_get_private($bundle['pkey'] ?? '');
            $certificate = $bundle['cert'] ?? '';
        } else {
            // Not a PKCS#12 bundle - try it as PEM.
            $privateKey = openssl_pkey_get_private($contents, $password !== '' ? $password : null);
            $certificate = $contents;
        }

        if ($privateKey === false) {
            // openssl_error_string() can echo file contents, so it is not
            // included: the message says what to check, not what was seen.
            throw new GraphException(
                'The Graph certificate could not be opened. Check the file format and the certificate password.',
            );
        }

        $parsed = @openssl_x509_read($certificate);

        if ($parsed === false) {
            throw new GraphException('The Graph certificate file does not contain a readable certificate.');
        }

        return [$privateKey, $parsed];
    }

    /** @param array<string, mixed> $value */
    private function encodeJson(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            throw new GraphException('The Graph client assertion could not be encoded.', previous: $e);
        }
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
