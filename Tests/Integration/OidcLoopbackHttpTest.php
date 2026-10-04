<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Http\Request;
use App\HttpClient\HttpClient;
use App\OAuth\OAuthException;
use App\OAuth\OAuthManager;
use App\Session\SessionManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Opt-in OIDC qualification over actual cURL connections to a test-only issuer.
 * Run with SQUEHUB_TEST_OIDC_LOOPBACK=1; no public provider is contacted.
 *
 * @group oidc-loopback
 */
final class OidcLoopbackHttpTest extends TestCase
{
    private const CLIENT_ID = 'loopback:client';
    private const CLIENT_SECRET = 'local+secret';

    private static ?Process $server = null;
    private static string $base = '';

    private HttpClient $http;
    private OAuthManager $manager;
    private SessionManager $sessions;

    public static function setUpBeforeClass(): void
    {
        if (getenv('SQUEHUB_TEST_OIDC_LOOPBACK') !== '1') {
            self::markTestSkipped('Set SQUEHUB_TEST_OIDC_LOOPBACK=1 to run local OIDC HTTP qualification.');
        }
        if (!extension_loaded('curl') || !extension_loaded('openssl')) {
            self::markTestSkipped('cURL and OpenSSL extensions are required.');
        }

        // Match the established HttpClientTransportTest loopback server harness.
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        if ($socket === false) self::markTestSkipped('Loopback socket is unavailable.');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        self::$base = 'http://127.0.0.1:' . $port;
        self::$server = new Process([PHP_BINARY, 'Tests/Fixtures/OidcLoopbackServer.php', (string) $port],
            dirname(__DIR__, 2));
        self::$server->disableOutput();
        self::$server->start();
        $deadline = microtime(true) + 5;
        do {
            $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $errorNumber, $errorMessage, 0.1);
            if ($probe !== false) { fclose($probe); return; }
            if (!self::$server->isRunning()) break;
            usleep(20000);
        } while (microtime(true) < $deadline);
        self::stopServer();
        self::markTestSkipped('Local OIDC fixture server could not start.');
    }

    public static function tearDownAfterClass(): void { self::stopServer(); }

    private static function stopServer(): void
    {
        if (self::$server === null) return;
        $port = (int) parse_url(self::$base, PHP_URL_PORT);
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $number, $message, 1);
        if ($socket !== false) {
            fwrite($socket, "GET /shutdown HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
            fclose($socket);
        }
        $deadline = microtime(true) + 3;
        while (self::$server->isRunning() && microtime(true) < $deadline) usleep(10000);
        if (self::$server->isRunning()) self::$server->stop(0);
        self::$server = null;
    }

    protected function setUp(): void
    {
        $this->http = new HttpClient(['connect_timeout' => 1, 'timeout' => 3]);
        $config = new Repository([
            'session' => ['driver' => 'array'],
            'oauth' => ['providers' => ['local' => [
                'issuer' => self::$base,
                'client_id' => self::CLIENT_ID,
                'client_secret' => self::CLIENT_SECRET,
                'redirect_uri' => self::$base . '/auth/oidc/callback',
                'scopes' => ['openid', 'email'],
                'allow_local_http' => true,
                'clock_skew' => 0,
            ]]],
        ]);
        $this->sessions = new SessionManager($config);
        $this->manager = new OAuthManager($config, $this->http, $this->sessions);
        $this->configure('discovery', 'normal');
    }

    public function testAuthorizationCodePkceTokenPostAndSignedCallback(): void
    {
        $callback = $this->beginCallback();
        $identity = $this->manager->provider('local')->callback($callback);

        self::assertSame('local', $identity->provider());
        self::assertSame(self::$base, $identity->issuer());
        self::assertSame('loopback-user', $identity->subject());
        self::assertSame('loopback@example.test', $identity->email());
        self::assertTrue($identity->emailVerified());
        self::assertNull($this->sessions->store()->authIdentifier('web'));

        $audit = $this->audit();
        self::assertSame(['discovery' => 2, 'authorize' => 1, 'token' => 1,
            'jwks' => 1, 'followed' => 0], $audit['counts']);
        $token = $audit['last_token'];
        self::assertSame('POST', $token['method']);
        self::assertSame('Basic ' . base64_encode(rawurlencode(self::CLIENT_ID) . ':'
            . rawurlencode(self::CLIENT_SECRET)), $token['headers']['authorization']);
        self::assertStringStartsWith('application/x-www-form-urlencoded',
            $token['headers']['content-type']);
        self::assertSame('authorization_code', $token['fields']['grant_type']);
        self::assertSame('one-time-code', $token['fields']['code']);
        self::assertSame(self::$base . '/auth/oidc/callback', $token['fields']['redirect_uri']);
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9._~-]{43,128}\z/D',
            $token['fields']['code_verifier']);
        self::assertArrayNotHasKey('client_id', $token['fields']);
        self::assertArrayNotHasKey('client_secret', $token['fields']);
    }

    public function testDiscoveryRejectsRedirectAndOversizedResponse(): void
    {
        foreach (['redirect', 'oversize'] as $mode) {
            $this->configure('discovery', $mode);
            $this->reject(fn () => $this->manager->provider('local')->redirect(), 'OIDC discovery failed.');
            self::assertSame(['discovery' => 1, 'authorize' => 0, 'token' => 0,
                'jwks' => 0, 'followed' => 0], $this->audit()['counts']);
        }
    }

    public function testTokenEndpointRejectsRedirectAndOversizedResponse(): void
    {
        foreach (['redirect', 'oversize'] as $mode) {
            $this->configure('token', $mode);
            $callback = $this->beginCallback();
            $this->reject(fn () => $this->manager->provider('local')->callback($callback),
                'OIDC token exchange failed.');
            self::assertSame(['discovery' => 2, 'authorize' => 1, 'token' => 1,
                'jwks' => 0, 'followed' => 0], $this->audit()['counts']);
        }
    }

    public function testJwksRejectsRedirectAndOversizedResponse(): void
    {
        foreach (['redirect', 'oversize'] as $mode) {
            $this->configure('jwks', $mode);
            $callback = $this->beginCallback();
            $this->reject(fn () => $this->manager->provider('local')->callback($callback),
                'OIDC signing keys could not be loaded.');
            self::assertSame(['discovery' => 2, 'authorize' => 1, 'token' => 1,
                'jwks' => 1, 'followed' => 0], $this->audit()['counts']);
        }
    }

    private function beginCallback(): Request
    {
        $redirect = $this->manager->provider('local')->redirect();
        self::assertSame(302, $redirect->status());
        $authorizationUrl = $redirect->header('Location');
        self::assertNotNull($authorizationUrl);
        self::assertStringStartsWith(self::$base . '/authorize?', $authorizationUrl);
        $authorization = $this->http->pending()->followRedirects(0)->get($authorizationUrl);
        self::assertSame(302, $authorization->status());
        $callbackUrl = $authorization->header('Location');
        self::assertNotNull($callbackUrl);
        self::assertStringStartsWith(self::$base . '/auth/oidc/callback?', $callbackUrl);
        return new Request('GET', substr($callbackUrl, strlen(self::$base)));
    }

    private function configure(string $endpoint, string $mode): void
    {
        $response = $this->http->get(self::$base . '/configure?' . http_build_query([
            $endpoint => $mode,
        ], '', '&', PHP_QUERY_RFC3986));
        self::assertSame(200, $response->status());
        self::assertSame(['ok' => true], $response->json());
    }

    /** @return array<string,mixed> */
    private function audit(): array
    {
        $response = $this->http->get(self::$base . '/audit');
        self::assertSame(200, $response->status());
        return $response->json();
    }

    private function reject(callable $operation, string $message): void
    {
        try {
            $operation();
            self::fail('Unsafe local OIDC response was accepted.');
        } catch (OAuthException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }
}
