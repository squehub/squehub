<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Core\Exceptions\CustomPrettyPageHandler;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Whoops\Run;

/** Keeps the compatibility debug page from rendering captured request payloads. */
final class LegacyPrettyPagePrivacyTest extends TestCase
{
    public function testWhoopsPageOmitsRequestPayloadTables(): void
    {
        $previousGet = $_GET;
        $previousPost = $_POST;
        $previousCookie = $_COOKIE;
        $secret = 'payload-' . bin2hex(random_bytes(6));
        $_GET = ['lookup' => $secret];
        $_POST = ['nested' => ['password' => $secret]];
        $_COOKIE = ['unknown_cookie_name' => $secret];
        try {
            $run = new Run();
            $handler = new CustomPrettyPageHandler();
            $handler->handleUnconditionally(true);
            $run->pushHandler($handler);
            $run->allowQuit(false);
            $run->writeToOutput(false);
            $html = $run->handleException(new RuntimeException('diagnostic probe'));
            self::assertStringContainsString('diagnostic probe', $html);
            self::assertStringNotContainsString($secret, $html);
        } finally {
            $_GET = $previousGet;
            $_POST = $previousPost;
            $_COOKIE = $previousCookie;
        }
    }
}
