<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Config\Repository;
use App\Core\View;
use App\Security\Csrf\Csrf;
use App\Security\Csrf\CsrfException;
use App\Security\Csrf\CsrfTokenManager;
use App\Session\SessionManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

final class CsrfTokenManagerTest extends TestCase
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
        Csrf::setResolver(null);
    }

    private function manager(): array
    {
        $sessions = new SessionManager(new Repository(['session' => ['driver' => 'array']]));
        $tokens = new CsrfTokenManager($sessions);
        Csrf::setResolver(static fn (): CsrfTokenManager => $tokens);
        return [$sessions, $tokens];
    }

    public function testTokenIsStableHiddenAndBoundToSessionIdentityLifecycle(): void
    {
        [$sessions, $tokens] = $this->manager();
        $store = $sessions->store();
        self::assertNull($store->csrfToken());
        $first = csrf_token();
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', $first);
        self::assertSame($first, csrf_token());
        self::assertTrue($tokens->verify($first));
        self::assertSame([], $store->all());
        self::assertSame([], $store->old());
        $store->flash('notice', 'visible');
        self::assertSame(['notice' => 'visible'], $store->all());
        $store->regenerate();
        self::assertSame($first, csrf_token());
        $store->close();
        self::assertTrue($tokens->verify($first));
        $store->invalidate();
        self::assertFalse($tokens->verify($first));
        $second = csrf_token();
        self::assertNotSame($first, $second);
        self::assertTrue($tokens->verify($second));
    }

    public function testExplicitRotationInvalidatesOldTokenAndRejectsInvalidShapes(): void
    {
        [, $tokens] = $this->manager();
        $first = $tokens->token();
        $second = $tokens->rotate();
        self::assertNotSame($first, $second);
        self::assertFalse($tokens->verify($first));
        self::assertTrue($tokens->verify($second));
        foreach ([null, [], new \stdClass(), str_repeat('a', 100000), strtoupper($second)] as $bad) {
            self::assertFalse($tokens->verify($bad));
        }
    }

    public function testVerificationNeverIssuesAFirstToken(): void
    {
        [$sessions, $tokens] = $this->manager();
        self::assertFalse($tokens->verify(str_repeat('a', 64)));
        self::assertNull($sessions->store()->csrfToken());
    }

    public function testValidLegacyTokenIsAdoptedOnceAndRemovedFromPublicData(): void
    {
        [$sessions, $tokens] = $this->manager();
        $legacy = bin2hex(random_bytes(32));
        $sessions->store()->put('_token', $legacy);
        self::assertTrue($tokens->verify($legacy));
        self::assertSame($legacy, csrf_token());
        self::assertFalse($sessions->store()->has('_token'));
        self::assertSame([], $sessions->store()->all());
    }

    public function testLegacyBooleanAndThrowingHelpersShareTheVerifier(): void
    {
        [, $tokens] = $this->manager();
        $issued = csrf_token();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['_token' => $issued];
        self::assertTrue(CsrfTokenValidator());
        validateCsrfToken();
        $_POST = ['_token' => 'invalid'];
        self::assertFalse(CsrfTokenValidator());
        $this->expectException(CsrfException::class);
        validateCsrfToken();
    }

    public function testCompiledLegacyDirectiveResolvesTokenAtRenderTime(): void
    {
        [$firstSessions] = $this->manager();
        $compiler = new ReflectionMethod(View::class, 'processBladeSyntax');
        $compiled = $compiler->invoke(null, '@csrf');
        $first = csrf_token();
        self::assertStringNotContainsString($first, $compiled);
        ob_start();
        eval('?>' . $compiled);
        $firstHtml = ob_get_clean();
        self::assertStringContainsString('name="_csrf"', $firstHtml);
        self::assertStringContainsString('value="' . $first . '"', $firstHtml);

        // Reuse the same compiled template under a different session. No
        // visitor-specific token may be frozen into the cached PHP source.
        [$secondSessions] = $this->manager();
        $second = csrf_token();
        self::assertNotSame($first, $second);
        ob_start();
        eval('?>' . $compiled);
        $secondHtml = ob_get_clean();
        self::assertStringContainsString('value="' . $second . '"', $secondHtml);
        self::assertStringNotContainsString($first, $secondHtml);
    }
}
