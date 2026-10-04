<?php

declare(strict_types=1);

namespace App\Testing;

use App\Core\ViewEscaper;
use App\Foundation\Application;
use App\Http\Request;
use App\Security\Csrf\CsrfTokenManager;
use App\Session\SessionManager;
use App\View\FragmentRenderResult;
use App\View\ViewRenderResult;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;

/**
 * Fluent assertions over HTML and finalized stacks from the real View renderer.
 * It captures output once; assertions never execute the template again.
 */
final class ViewTestResult
{
    public function __construct(
        private readonly string $view,
        private readonly ?string $fragment,
        private readonly ViewRenderResult|FragmentRenderResult $result,
        private readonly ?Application $application = null,
    ) {
    }

    public function html(): string
    {
        return $this->result->html();
    }

    public function hasStack(string $name): bool
    {
        return $this->result->hasStack($name);
    }

    /** Undeclared valid stacks have empty output, as in the renderer. */
    public function stack(string $name): string
    {
        return $this->result->stack($name);
    }

    /** @return array<string, string> */
    public function stacks(): array
    {
        return $this->result->stacks();
    }

    /** Exact, case-sensitive substring of the final rendered HTML. */
    public function assertSee(string $needle): static
    {
        self::assertNeedle($needle);
        Assert::assertTrue(str_contains($this->html(), $needle),
            $this->failure('assertSee', $needle, $this->html()));
        return $this;
    }

    /** Exact, case-sensitive absence from the final rendered HTML. */
    public function assertDontSee(string $needle): static
    {
        self::assertNeedle($needle);
        Assert::assertFalse(str_contains($this->html(), $needle),
            $this->failure('assertDontSee', $needle, $this->html()));
        return $this;
    }

    /** Escape through the same boundary used by {{ }} interpolation. */
    public function assertSeeEscaped(mixed $value): static
    {
        $escaped = ViewEscaper::escape($value);
        self::assertNeedle($escaped);
        Assert::assertTrue(str_contains($this->html(), $escaped),
            $this->failure('assertSeeEscaped', $escaped, $this->html()));
        return $this;
    }

    /** Each occurrence must follow the preceding one, including repeats. */
    public function assertSeeInOrder(array $needles): static
    {
        if ($needles === []) {
            throw new InvalidArgumentException('An ordered View assertion needs at least one text value.');
        }
        $offset = 0;
        foreach (array_values($needles) as $index => $needle) {
            if (!is_string($needle)) {
                throw new InvalidArgumentException('Ordered View assertion values must be text.');
            }
            self::assertNeedle($needle);
            $position = strpos($this->html(), $needle, $offset);
            Assert::assertTrue($position !== false,
                $this->failure('assertSeeInOrder item ' . ($index + 1), $needle, $this->html()));
            $offset = $position + strlen($needle);
        }
        return $this;
    }

    /** Inspect a finalized named stack, even when no @stack position was printed. */
    public function assertStackContains(string $name, string $needle): static
    {
        self::assertNeedle($needle);
        $html = $this->stack($name);
        Assert::assertTrue(str_contains($html, $needle),
            $this->failure('assertStackContains ' . self::excerpt($name), $needle, $html));
        return $this;
    }

    public function assertStackMissing(string $name, string $needle): static
    {
        self::assertNeedle($needle);
        $html = $this->stack($name);
        Assert::assertFalse(str_contains($html, $needle),
            $this->failure('assertStackMissing ' . self::excerpt($name), $needle, $html));
        return $this;
    }

    /**
     * Check the exact configured hidden control against the current real
     * session token. A missing token fails without generating or printing one.
     */
    public function assertHasCsrfField(): static
    {
        $app = $this->application;
        if ($app === null || !$app->container()->has(CsrfTokenManager::class)
            || !$app->container()->has(SessionManager::class)) {
            Assert::fail($this->origin() . ': assertHasCsrfField failed; CSRF test services are unavailable.');
        }
        $csrf = $app->container()->make(CsrfTokenManager::class);
        $sessions = $app->container()->make(SessionManager::class);
        if (!$csrf instanceof CsrfTokenManager || !$sessions instanceof SessionManager) {
            Assert::fail($this->origin() . ': assertHasCsrfField failed; CSRF test services are invalid.');
        }
        $token = $sessions->store()->csrfToken();
        Assert::assertTrue(is_string($token) && preg_match('/\A[0-9a-f]{64}\z/D', $token) === 1,
            $this->origin() . ': assertHasCsrfField failed; no current session CSRF token exists.');
        $expected = '<input type="hidden" name="' . ViewEscaper::escape($csrf->field())
            . '" value="' . ViewEscaper::escape($token) . '">';
        Assert::assertTrue(str_contains($this->html(), $expected),
            $this->origin() . ': assertHasCsrfField failed; expected the current configured CSRF hidden field.');
        return $this;
    }

    /** Match the browser-form method helper's exact allowlisted control. */
    public function assertHasMethodField(string $method): static
    {
        $normalized = Request::normalizeFormMethod($method);
        if ($normalized === null) {
            throw new InvalidArgumentException('A method-field assertion needs PUT, PATCH, or DELETE.');
        }
        Assert::assertTrue(str_contains($this->html(),
            '<input type="hidden" name="_method" value="' . $normalized . '">'),
            $this->origin() . ': assertHasMethodField failed; expected a '
                . $normalized . ' method hidden field.');
        return $this;
    }

    private function failure(string $assertion, string $needle, string $actual): string
    {
        return $this->origin() . ': ' . $assertion . ' failed for ' . self::excerpt($needle)
            . '; rendered excerpt: ' . self::excerpt($actual) . '.';
    }

    private function origin(): string
    {
        return 'View ' . self::excerpt($this->view)
            . ($this->fragment === null ? '' : ', Fragment ' . self::excerpt($this->fragment));
    }

    private static function assertNeedle(string $needle): void
    {
        if ($needle === '') {
            throw new InvalidArgumentException('A View assertion text value cannot be empty.');
        }
    }

    /** Bound local PHPUnit failure detail without splitting valid UTF-8 text. */
    private static function excerpt(string $value): string
    {
        if (preg_match('/\A.{0,160}/us', $value, $match) !== 1) {
            $prefix = substr($value, 0, 160);
        } else {
            $prefix = $match[0];
        }
        $suffix = strlen($prefix) < strlen($value) ? '…' : '';
        return (string) json_encode($prefix . $suffix,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
