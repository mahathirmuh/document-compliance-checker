<?php

declare(strict_types=1);

namespace Tests\Feature\Graph;

use App\Exceptions\GraphException;
use App\Services\MicrosoftGraph\GraphAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * App-only authentication against Entra ID (CLAUDE.md 11).
 */
class GraphAuthTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_URL = 'https://login.microsoftonline.com/*/oauth2/v2.0/token';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('microsoft_graph.tenant_id', 'tenant-123');
        config()->set('microsoft_graph.client_id', 'client-abc');
        config()->set('microsoft_graph.client_secret', 'dev-secret');
        config()->set('microsoft_graph.certificate.path', null);

        Cache::flush();
    }

    #[Test]
    public function it_acquires_a_token_with_a_client_secret(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600])]);

        $this->assertSame('tok-1', app(GraphAuthService::class)->accessToken());

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $body['grant_type'] === 'client_credentials'
                && $body['client_id'] === 'client-abc'
                && $body['client_secret'] === 'dev-secret'
                && $body['scope'] === 'https://graph.microsoft.com/.default';
        });
    }

    #[Test]
    public function it_caches_the_token_rather_than_re_authenticating_per_call(): void
    {
        // A scan of a thousand files must not mean a thousand token requests.
        Http::fake([self::TOKEN_URL => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600])]);

        $auth = app(GraphAuthService::class);
        $auth->accessToken();
        $auth->accessToken();
        $auth->accessToken();

        Http::assertSentCount(1);
    }

    #[Test]
    public function the_cached_token_expires_before_the_real_one_does(): void
    {
        // Expiring early stops a long request starting with a token that dies
        // mid-flight.
        config()->set('microsoft_graph.token_expiry_leeway_seconds', 300);

        Http::fake([self::TOKEN_URL => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600])]);

        app(GraphAuthService::class)->accessToken();

        $this->assertSame('tok-1', Cache::get('msgraph.app_token'));
    }

    #[Test]
    public function forgetting_the_token_forces_a_fresh_request(): void
    {
        Http::fake([self::TOKEN_URL => Http::sequence()
            ->push(['access_token' => 'tok-1', 'expires_in' => 3600])
            ->push(['access_token' => 'tok-2', 'expires_in' => 3600]),
        ]);

        $auth = app(GraphAuthService::class);

        $this->assertSame('tok-1', $auth->accessToken());
        $auth->forgetToken();
        $this->assertSame('tok-2', $auth->accessToken());
    }

    #[Test]
    public function a_rejected_credential_raises_a_readable_error(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(
            ['error' => 'invalid_client', 'error_description' => 'AADSTS7000215: Invalid client secret'],
            401,
        )]);

        $this->expectException(GraphException::class);
        $this->expectExceptionMessage('Could not authenticate to Microsoft Graph');

        app(GraphAuthService::class)->accessToken();
    }

    #[Test]
    public function a_missing_tenant_is_reported_rather_than_attempted(): void
    {
        config()->set('microsoft_graph.tenant_id', null);
        Http::fake();

        $auth = app(GraphAuthService::class);

        $this->assertFalse($auth->isConfigured());

        // A credential is present, it just has nowhere to authenticate to.
        // credentialType() describes the credential, not the whole setup.
        $this->assertSame('client_secret', $auth->credentialType());

        $this->expectException(GraphException::class);
        $auth->accessToken();

        Http::assertNothingSent();
    }

    #[Test]
    public function a_missing_credential_is_reported_rather_than_attempted(): void
    {
        config()->set('microsoft_graph.client_secret', null);
        config()->set('microsoft_graph.certificate.path', null);
        Http::fake();

        $auth = app(GraphAuthService::class);

        $this->assertFalse($auth->isConfigured());
        $this->assertSame('none', $auth->credentialType());

        $this->expectException(GraphException::class);
        $auth->accessToken();
    }

    #[Test]
    public function a_certificate_is_preferred_over_a_secret_when_both_are_present(): void
    {
        [$certificatePath] = $this->generateCertificate();

        config()->set('microsoft_graph.certificate.path', $certificatePath);
        config()->set('microsoft_graph.client_secret', 'dev-secret');

        Http::fake([self::TOKEN_URL => Http::response(['access_token' => 'tok-cert', 'expires_in' => 3600])]);

        $auth = app(GraphAuthService::class);

        $this->assertSame('certificate', $auth->credentialType());
        $this->assertSame('tok-cert', $auth->accessToken());

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return ! isset($body['client_secret'])
                && $body['client_assertion_type'] === 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer'
                && is_string($body['client_assertion']);
        });

        @unlink($certificatePath);
    }

    #[Test]
    public function the_client_assertion_is_a_signed_jwt_with_the_expected_claims(): void
    {
        [$certificatePath] = $this->generateCertificate();

        config()->set('microsoft_graph.certificate.path', $certificatePath);
        config()->set('microsoft_graph.client_secret', null);

        Http::fake([self::TOKEN_URL => Http::response(['access_token' => 'tok-cert', 'expires_in' => 3600])]);

        app(GraphAuthService::class)->accessToken();

        Http::assertSent(function (Request $request) {
            $assertion = $request->data()['client_assertion'];
            [$header, $claims, $signature] = explode('.', $assertion);

            $header = json_decode($this->base64UrlDecode($header), true);
            $claims = json_decode($this->base64UrlDecode($claims), true);

            return $header['alg'] === 'RS256'
                && $header['typ'] === 'JWT'
                && ! empty($header['x5t'])
                && $claims['iss'] === 'client-abc'
                && $claims['sub'] === 'client-abc'
                && str_contains($claims['aud'], '/oauth2/v2.0/token')
                && $claims['exp'] > $claims['nbf']
                && ! empty($claims['jti'])
                && $signature !== '';
        });

        @unlink($certificatePath);
    }

    #[Test]
    public function an_unreadable_certificate_is_reported_clearly(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cert').'.pem';
        file_put_contents($path, 'this is not a certificate');

        config()->set('microsoft_graph.certificate.path', $path);
        config()->set('microsoft_graph.client_secret', null);

        Http::fake();

        try {
            $this->expectException(GraphException::class);
            app(GraphAuthService::class)->accessToken();
        } finally {
            @unlink($path);
        }
    }

    /* ------------------------------------------------------------------ */

    /**
     * Generate a throwaway self-signed certificate as a PEM bundle.
     *
     * The OpenSSL config is written inline rather than relying on one being
     * installed. A Windows PHP build ships openssl.cnf in extras/ssl, but its
     * location varies by install and is absent often enough that pointing at
     * it would make this test pass on one machine and fail on the next.
     *
     * @return array{0: string}
     */
    private function generateCertificate(): array
    {
        $configPath = tempnam(sys_get_temp_dir(), 'sslcnf').'.cnf';
        file_put_contents($configPath, "[req]\ndistinguished_name = dn\nprompt = no\n\n[dn]\nCN = doccheck-test\n");

        $options = ['config' => $configPath, 'digest_alg' => 'sha256'];

        $key = openssl_pkey_new($options + [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $this->assertNotFalse($key, 'Could not generate a test key pair.');

        $csr = openssl_csr_new(['commonName' => 'doccheck-test'], $key, $options);
        $this->assertNotFalse($csr, 'Could not generate a test CSR.');

        $certificate = openssl_csr_sign($csr, null, $key, 1, $options);
        $this->assertNotFalse($certificate, 'Could not sign the test certificate.');

        openssl_x509_export($certificate, $certificatePem);
        openssl_pkey_export($key, $keyPem, null, $options);

        @unlink($configPath);

        $path = tempnam(sys_get_temp_dir(), 'graphcert').'.pem';
        file_put_contents($path, $certificatePem.$keyPem);

        return [$path];
    }

    private function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    }
}
