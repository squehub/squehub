<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Config\Repository;
use App\Core\ViewEscaper;
use App\Core\ViewException;
use App\Http\BrowserNavigation;
use App\Http\Request;
use App\Http\Response;
use App\Session\SessionManager;
use App\Validation\ErrorBag;
use PHPUnit\Framework\TestCase;

final class BrowserFormsTest extends TestCase
{
    public function testEscapedAndRawValuesHaveExplicitContracts(): void
    {
        self::assertSame('&lt;script&gt;&quot;&#039;&amp;', ViewEscaper::escape('<script>"\'&'));
        self::assertSame('<b>trusted</b>', ViewEscaper::raw('<b>trusted</b>'));
        self::assertSame('', ViewEscaper::escape(null));
        self::assertSame('', ViewEscaper::escape(false));
        self::assertSame('1', ViewEscaper::escape(true));
        self::assertSame('0', ViewEscaper::escape(0));
        self::assertSame('café', ViewEscaper::escape('café'));
        self::assertSame("\xEF\xBF\xBD", ViewEscaper::escape("\xFF"));
        self::assertSame('<b title="é">ok</b>', ViewEscaper::raw('<b title="é">ok</b>'));
        self::assertSame('text', ViewEscaper::escape(new class implements \Stringable {
            public function __toString(): string { return 'text'; }
        }));
        foreach ([[], new \stdClass(), fopen('php://memory', 'r')] as $invalid) {
            try {
                ViewEscaper::escape($invalid);
                self::fail('Unsupported template value was rendered.');
            } catch (ViewException) {
                self::assertTrue(true);
            } finally {
                if (is_resource($invalid)) fclose($invalid);
            }
        }
    }

    public function testErrorBagIsNonConsumingAndFieldIndexed(): void
    {
        self::assertFalse((new ErrorBag())->any());
        $bag = new ErrorBag(['email' => ['First', 'Second']]);
        self::assertTrue($bag->any());
        self::assertTrue($bag->has('email'));
        self::assertFalse($bag->has('name'));
        self::assertSame('First', $bag->first('email'));
        self::assertSame('First', $bag->first('email'));
        self::assertNull($bag->first('name'));
        self::assertSame(['First', 'Second'], $bag->get('email'));
        self::assertSame(['email' => ['First', 'Second']], $bag->all());
    }

    public function testNavigationUsesInternalGetThenStrictSameOriginReferer(): void
    {
        $sessions = new SessionManager(new Repository(['session' => ['driver' => 'array']]));
        $navigation = new BrowserNavigation($sessions);
        $origin = ['HTTP_HOST' => 'app.example:8443', 'HTTPS' => 'on'];
        $navigation->remember(new Request('GET', '/form?secret=hidden'), new Response('form'));
        self::assertSame('/form', $navigation->back(new Request('POST', '/submit', [], [], [], [], [], $origin)));
        self::assertArrayNotHasKey('_squehub', $sessions->store()->all());
        $sessions->store()->setPreviousPath('/submit');
        foreach ([
            'https://app.example:8443/form?token=secret' => '/form',
            'https://evil.example/form' => null,
            'http://app.example:8443/form' => null,
            'https://app.example/form' => null,
            '//evil.example/form' => null,
            'javascript:alert(1)' => null,
            'data:text/html,evil' => null,
            "https://app.example:8443/form\r\nX-Injected: yes" => null,
            'https://app.example:8443/submit' => null,
        ] as $referer => $expected) {
            $request = new Request('POST', '/submit', [], [], [], [], ['Referer' => $referer], $origin);
            self::assertSame($expected, $navigation->back($request), $referer);
        }
    }
}
