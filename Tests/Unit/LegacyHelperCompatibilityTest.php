<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Config\Repository;
use App\Session\Session;
use App\Session\SessionManager;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** Locks in retained v1 helper behavior without recommending it for new code. */
final class LegacyHelperCompatibilityTest extends TestCase
{
    private array $serverBefore;
    private array $postBefore;

    protected function setUp(): void
    {
        $this->serverBefore = $_SERVER;
        $this->postBefore = $_POST;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBefore;
        $_POST = $this->postBefore;
        Session::setResolver(null);
    }

    public function testStartSessionHelperStartsTheSelectedStoreOnlyOnce(): void
    {
        $manager = new SessionManager(new Repository(['session' => ['driver' => 'array']]));
        Session::setResolver(static fn (): SessionManager => $manager);
        $store = $manager->store();
        self::assertFalse($store->isStarted());

        self::assertNull(\startSessionIfNotStarted());
        self::assertTrue($store->isStarted());
        $id = $store->id();
        \startSessionIfNotStarted();
        self::assertSame($id, $store->id());
    }

    public function testIsPostRetainsExactMethodCheckAndIsSafeWithoutServerMethod(): void
    {
        unset($_SERVER['REQUEST_METHOD']);
        self::assertFalse(\is_post());
        $_SERVER['REQUEST_METHOD'] = 'GET';
        self::assertFalse(\is_post());
        $_SERVER['REQUEST_METHOD'] = 'POST';
        self::assertTrue(\is_post());
        $_SERVER['REQUEST_METHOD'] = 'post';
        self::assertFalse(\is_post());
    }

    public function testPhoneMaskSlugAndPriceFormattingKeepTheirLegacyOutput(): void
    {
        self::assertSame('', \maskPhoneNumber(''));
        self::assertSame('*', \maskPhoneNumber('7'));
        self::assertSame('**', \maskPhoneNumber('42'));
        self::assertSame('********67', \maskPhoneNumber('0801234567'));

        self::assertSame('hello-squehub-v2', \slugify('  Hello, SqueHub v2!  '));
        self::assertSame('n-a', \slugify('...'));

        self::assertSame('1,234.50', \priceFormatter(1234.5));
        self::assertSame('$1,234.50', \priceFormatter(1234.5, '$'));
        self::assertSame('1,234.5 USD', \priceFormatter(1234.5, 'USD', false, 1));
    }

    public function testLegacyUrlStillUsesRawServerSchemeHostAndTrimmedSegments(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'example.test:8443';
        self::assertSame('https://example.test:8443/users/42',
            \url('/users/', '/42/'));

        $_SERVER['HTTPS'] = 'off';
        self::assertSame('http://example.test:8443', \url());
        unset($_SERVER['HTTP_HOST']);
        self::assertSame('http://localhost/docs', \url('docs'));
    }

    public function testLegacyCsrfValidationSkipsNonPostRequests(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = ['_token' => 'invalid'];
        self::assertTrue(\CsrfTokenValidator());
        \validateCsrfToken();
    }
}
