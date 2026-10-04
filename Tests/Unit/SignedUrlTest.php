<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Config\Repository;
use App\Cryptography\CryptManager;
use App\Database\ModelClock;
use App\Foundation\UrlBasePath;
use App\Http\Request;
use App\Logging\LogContextNormalizer;
use App\Routing\RouteDefinition;
use App\Routing\RouteRegistry;
use App\Security\SignedUrl\SignedUrlManager;
use App\Support\SecretRedactor;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Signed links are checked against raw request bytes and one matched route. */
final class SignedUrlTest extends TestCase
{
    private const NOW = 1800000000;

    private static function key(string $byte): string
    {
        return 'base64:' . base64_encode(str_repeat($byte, 32));
    }

    private static function crypt(string $current = 'first', ?array $keys = null): CryptManager
    {
        return new CryptManager([
            'driver' => 'auto', 'current' => $current,
            'keys' => $keys ?? ['first' => self::key('a')],
        ]);
    }

    /** @return array{SignedUrlManager,RouteDefinition,SignedUrlClock} */
    private function fixture(string $base = '', ?CryptManager $crypt = null,
        string $host = ''): array
    {
        $mount = new UrlBasePath($base);
        $routes = new RouteRegistry(null, $mount);
        $route = $routes->get('/files/{name}', static fn (): string => 'file',
            $host === '' ? null : $host)->named('files.download');
        $clock = new SignedUrlClock(self::NOW);
        return [new SignedUrlManager($routes, $mount, $crypt ?? self::crypt(), $clock), $route, $clock];
    }

    /** @param array<string,mixed>|null $query */
    private static function request(string $uri, RouteDefinition $route, string $method = 'GET',
        ?array $query = null, string $host = 'example.test', string $base = ''): Request
    {
        if ($query === null) {
            $query = [];
            parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        }
        $request = new Request($method, $uri, $query, server: ['HTTP_HOST' => $host]);
        $request->applyUrlBasePath(new UrlBasePath($base));
        $request->setAttribute('route', $route);
        return $request;
    }

    public function testRootAndMountedLinksBindUnicodePathQueryAndRoute(): void
    {
        foreach (['', '/app'] as $mount) {
            [$signed, $route] = $this->fixture($mount);
            $url = $signed->temporary('files.download', ['name' => 'résumé 🦊.pdf'],
                ['locale' => 'français', 'version' => '二'], self::NOW + 300, 'private.download');
            self::assertStringStartsWith($mount . '/files/r%C3%A9sum%C3%A9%20%F0%9F%A6%8A.pdf?', $url);
            self::assertStringContainsString('sqh_expires=', $url);
            self::assertStringContainsString('sqh_signature=', $url);
            self::assertTrue($signed->valid(self::request($url, $route, base: $mount), 'private.download'));
            self::assertFalse($signed->valid(self::request($url, $route, base: $mount), 'other.download'));
        }
    }

    public function testCanonicalQueryAcceptsReorderButRejectsAmbiguityAndSnapshotMismatch(): void
    {
        [$signed, $route] = $this->fixture();
        $url = $signed->temporary('files.download', ['name' => 'report.pdf'],
            ['alpha' => 'A', 'beta' => 'café'], self::NOW + 300, 'download');
        [$path, $query] = explode('?', $url, 2);
        $pairs = explode('&', $query);
        $reordered = $path . '?' . implode('&', array_reverse($pairs));
        self::assertTrue($signed->valid(self::request($reordered, $route), 'download'));

        foreach ([
            $url . '&alpha=A',
            $url . '&sqh_expires=' . (self::NOW + 300),
            $url . '&sqh_signature=duplicate',
            $url . '&alpha%3D=A',
            $url . '&broken=%GG',
            $url . '&broken=%C3%28',
            $url . '&&empty=1',
        ] as $invalid) {
            self::assertFalse($signed->valid(self::request($invalid, $route), 'download'));
        }

        $mismatched = self::request($url, $route, query: [
            'alpha' => 'different', 'beta' => 'café',
            'sqh_expires' => (string) (self::NOW + 300),
            'sqh_signature' => 'different',
        ]);
        self::assertFalse($signed->valid($mismatched, 'download'));
    }

    public function testMissingAndTamperedFieldsFailClosed(): void
    {
        [$signed, $route] = $this->fixture();
        $url = $signed->temporary('files.download', ['name' => 'report.pdf'],
            ['note' => 'hello world'], self::NOW + 300, 'download');
        $invalid = [
            preg_replace('/&sqh_signature=[^&]+/', '', $url),
            str_replace('note=hello%20world', 'note=changed', $url),
            str_replace('note=hello%20world', 'note=hello+world', $url),
            str_replace('sqh_expires=' . (self::NOW + 300),
                'sqh_expires=' . (self::NOW + 301), $url),
            str_replace('sqh_expires=' . (self::NOW + 300),
                'sqh_expires=0' . (self::NOW + 300), $url),
            str_replace('sqh_expires=' . (self::NOW + 300),
                'sqh_expires=999999999999999999999999', $url),
            $url . '&%73qh_signature=alias',
        ];
        foreach ($invalid as $candidate) {
            self::assertIsString($candidate);
            self::assertFalse($signed->valid(self::request($candidate, $route), 'download'));
        }
    }

    public function testExpiryPurposePathAndMethodAreBound(): void
    {
        [$signed, $route, $clock] = $this->fixture();
        $url = $signed->temporary('files.download', ['name' => 'report.pdf'], [],
            self::NOW + 90, 'download', 'GET');
        self::assertTrue($signed->valid(self::request($url, $route), 'download'));
        self::assertFalse($signed->valid(self::request($url, $route), 'other'));
        self::assertFalse($signed->valid(self::request($url, $route, 'POST'), 'download'));
        self::assertFalse($signed->valid(self::request($url, $route), 'download', 'POST'));
        self::assertFalse($signed->valid(self::request(
            str_replace('/report.pdf?', '/other.pdf?', $url), $route), 'download'));
        $clock->set(self::NOW + 90);
        self::assertFalse($signed->valid(self::request($url, $route), 'download'));
    }

    public function testMethodBindingOnMultiMethodRouteAndHeadCompatibility(): void
    {
        $mount = new UrlBasePath();
        $routes = new RouteRegistry(null, $mount);
        $route = $routes->add(['GET', 'POST', 'DELETE'], '/actions/{id}',
            static fn (): string => 'action')->named('actions.run');
        $signed = new SignedUrlManager($routes, $mount, self::crypt(),
            new SignedUrlClock(self::NOW));
        $get = $signed->temporary('actions.run', ['id' => '42'], [],
            self::NOW + 300, 'action.run', 'GET');
        $post = $signed->temporary('actions.run', ['id' => '42'], [],
            self::NOW + 300, 'action.run', 'POST');
        self::assertTrue($signed->valid(self::request($get, $route), 'action.run', 'GET'));
        self::assertTrue($signed->valid(self::request($get, $route, 'HEAD'), 'action.run', 'GET'));
        self::assertTrue($signed->valid(self::request($post, $route, 'POST'), 'action.run', 'POST'));
        self::assertFalse($signed->valid(self::request($get, $route, 'POST'), 'action.run', 'POST'));
        self::assertFalse($signed->valid(self::request($post, $route), 'action.run', 'GET'));
        $delete = $signed->temporary('actions.run', ['id' => '42'], [],
            self::NOW + 300, 'action.run', 'DELETE');
        $query = [];
        parse_str((string) parse_url($delete, PHP_URL_QUERY), $query);
        $overridden = new Request('POST', $delete, $query, ['_method' => 'DELETE'],
            headers: ['Content-Type' => 'application/x-www-form-urlencoded']);
        $overridden->applyUrlBasePath($mount);
        $overridden->setAttribute('route', $route);
        self::assertSame('DELETE', $overridden->method());
        self::assertFalse($signed->valid($overridden, 'action.run', 'DELETE'));
    }

    public function testGenerationRejectsReservedQueryUnsafePurposeAndUnboundedExpiry(): void
    {
        [$signed] = $this->fixture();
        $calls = [
            static fn () => $signed->temporary('files.download', ['name' => 'report.pdf'],
                ['sqh_signature' => 'forged'], self::NOW + 300, 'download'),
            static fn () => $signed->temporary('files.download', ['name' => 'report.pdf'],
                ['sqh_expires' => '1'], self::NOW + 300, 'download'),
            static fn () => $signed->temporary('files.download', ['name' => 'report.pdf'],
                [], self::NOW, 'download'),
            static fn () => $signed->temporary('files.download', ['name' => 'report.pdf'],
                [], self::NOW + 366 * 86400 + 1, 'download'),
            static fn () => $signed->temporary('files.download', ['name' => 'report.pdf'],
                [], self::NOW + 300, 'bad purpose'),
            static fn () => $signed->temporary('files.download', ['name' => 'report.pdf'],
                [], self::NOW + 300, 'download', 'OPTIONS'),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                self::fail('Invalid signed URL input was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testMissingMatchedRouteAndRotatedCryptKeyDoNotBypassVerification(): void
    {
        [$old, $route] = $this->fixture();
        $url = $old->temporary('files.download', ['name' => 'report.pdf'], [],
            self::NOW + 300, 'download');
        $missing = self::request($url, $route);
        $missing->setAttribute('route', null);
        self::assertFalse($old->valid($missing, 'download'));

        [$rotated, $rotatedRoute] = $this->fixture('', self::crypt('second', [
            'first' => self::key('a'), 'second' => self::key('b'),
        ]));
        self::assertTrue($rotated->valid(self::request($url, $rotatedRoute), 'download'));
        $newUrl = $rotated->temporary('files.download', ['name' => 'report.pdf'], [],
            self::NOW + 300, 'download');
        self::assertFalse($old->valid(self::request($newUrl, $route), 'download'));
        [$removed, $removedRoute] = $this->fixture('', self::crypt('second', [
            'second' => self::key('b'),
        ]));
        self::assertFalse($removed->valid(self::request($url, $removedRoute), 'download'));
    }

    public function testStaticHostIsBoundAndPrivateKeyIsAbsentFromDebugOutput(): void
    {
        [$signed, $route] = $this->fixture('', null, 'downloads.example.test');
        $url = $signed->temporary('files.download', ['name' => 'report.pdf'], [],
            self::NOW + 300, 'download');
        self::assertTrue($signed->valid(self::request($url, $route,
            host: 'downloads.example.test'), 'download'));
        self::assertFalse($signed->valid(self::request($url, $route,
            host: 'other.example.test'), 'download'));
        ob_start();
        var_dump($signed);
        $debug = (string) ob_get_clean();
        self::assertStringNotContainsString(self::key('a'), $debug);
        self::assertStringNotContainsString(str_repeat('a', 32), $debug);
    }

    public function testPlantedSignatureIsRedactedFromStructuredAndFreeFormLogs(): void
    {
        $normalizer = new LogContextNormalizer(new SecretRedactor(new Repository()));
        $planted = 'planted-signature-secret';
        self::assertSame('[REDACTED]', $normalizer->normalize([
            'sqh_signature' => $planted,
        ])['sqh_signature']);
        $message = $normalizer->message('Visit /reports?sqh_signature=' . $planted, []);
        self::assertStringNotContainsString($planted, $message);
        self::assertStringContainsString('sqh_signature=[REDACTED]', $message);
    }
}

final class SignedUrlClock implements ModelClock
{
    public function __construct(private int $time) {}

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $this->time);
    }

    public function set(int $time): void
    {
        $this->time = $time;
    }
}
