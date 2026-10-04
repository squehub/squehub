<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Config\Repository;
use App\Session\Session;
use App\Session\SessionManager;
use App\View\Compiler\CompilerException;
use App\View\Compiler\TemplateCompiler;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** Checks structural security directives without inventing View-level Auth state. */
final class ViewSecurityCompilerTest extends TestCase
{
    protected function tearDown(): void
    {
        Session::setResolver(null);
        parent::tearDown();
    }

    public function testSecurityChecksStayInRuntimeCodeAndComposeWithElse(): void
    {
        $source = <<<'TEMPLATE'
@auth Authenticated @else Guest @endauth
@auth(
    'admin'
) Admin @endauth
@guest Guest @else Member @endguest
@guest('admin') Admin guest @endguest
@can('reports.view') Reports @else Hidden @endcan
@can('update', resolveOrder($orders, ['a,b'])) Edit @endcan
@cannot('update', $order) Read only @else Edit @endcannot
@session('status'){{ $value }}@else Missing @endsession
TEMPLATE;

        $compiled = $this->compile($source);

        self::assertSame($compiled, $this->compile($source));
        self::assertStringContainsString('\auth()->hasDefaultGuard() && \auth()->check()', $compiled);
        self::assertStringContainsString("\auth()->guard('admin')->check()", $compiled);
        self::assertStringContainsString("\auth()->guard('admin')->guest()", $compiled);
        self::assertStringContainsString("\authorize()->allows('reports.view')", $compiled);
        self::assertStringContainsString("\authorize()->allows('update', resolveOrder(\$orders, ['a,b']))", $compiled);
        self::assertStringContainsString("\authorize()->denies('update', \$order)", $compiled);
        self::assertSame(1, substr_count($compiled, 'resolveOrder('));
        self::assertStringContainsString("->has('status')", $compiled);
        self::assertStringContainsString("->get('status')", $compiled);
        self::assertSame(5, substr_count($compiled, '<?php else: ?>'));
        self::assertStringNotContainsString('@auth', $compiled);
        self::assertStringNotContainsString('@session', $compiled);
    }

    public function testSessionPresenceUsesHasAndNestedValuesRestoreCallerBinding(): void
    {
        $sessions = new SessionManager(new Repository(['session' => ['driver' => 'array']]));
        Session::setResolver(static fn (): SessionManager => $sessions);
        $sessions->store()->put('outer', false);
        $sessions->store()->put('inner', null);

        [$output, $hadValue, $value] = $this->render(
            "@session('outer'){{ \$value === false ? 'false' : 'wrong' }}:"
            . "@session('inner'){{ \$value === null ? 'null' : 'wrong' }}@endsession:"
            . "{{ \$value === false ? 'false' : 'wrong' }}@endsession",
            ['value' => 'before']
        );

        self::assertSame('false:null:false', $output);
        self::assertTrue($hadValue);
        self::assertSame('before', $value);
    }

    public function testSessionElseAndUnavailableStoreDoNotLeakValue(): void
    {
        [$output, $hadValue] = $this->render(
            "@session('missing')shown@else absent @endsession"
            . "{{ isset(\$value) ? 'leaked' : 'clean' }}"
        );

        self::assertSame(' absent clean', $output);
        self::assertFalse($hadValue);
    }

    public function testSessionRestoresValueAfterBodyException(): void
    {
        $sessions = new SessionManager(new Repository(['session' => ['driver' => 'array']]));
        Session::setResolver(static fn (): SessionManager => $sessions);
        $sessions->store()->put('status', 'ready');
        $compiled = $this->compile("@session('status')<?php throw new \\RuntimeException('stop'); ?>@endsession");

        $value = 'before';
        try {
            eval('?>' . $compiled);
            self::fail('Expected the template body to throw.');
        } catch (\RuntimeException $exception) {
            self::assertSame('stop', $exception->getMessage());
            self::assertSame('before', $value);
        }
    }

    /** @dataProvider malformedDirectives */
    public function testMalformedDirectivesReportLogicalViewAndLine(
        string $source,
        string $directive
    ): void {
        foreach (["\n", "\r\n"] as $ending) {
            $body = str_replace("\n", $ending, "intro\nsecond\n" . $source);
            try {
                $this->compile($body, 'Pages.Security');
                self::fail('Expected a compiler error for ' . $directive);
            } catch (CompilerException $exception) {
                self::assertSame('Pages.Security', $exception->view());
                self::assertSame(3, $exception->sourceLine());
                self::assertStringContainsString($directive, $exception->getMessage());
                self::assertStringNotContainsString('intro', $exception->getMessage());
            }
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformedDirectives(): iterable
    {
        yield 'orphan endauth' => ['@endauth', '@endauth'];
        yield 'orphan endguest' => ['@endguest', '@endguest'];
        yield 'orphan endcan' => ['@endcan', '@endcan'];
        yield 'orphan endcannot' => ['@endcannot', '@endcannot'];
        yield 'orphan endsession' => ['@endsession', '@endsession'];
        yield 'unclosed auth' => ['@auth body', '@auth'];
        yield 'unclosed session' => ["@session('status')body", '@session'];
        yield 'mismatched auth close' => ['@auth body @endguest', '@endguest'];
        yield 'mismatched can close' => ["@can('read')body@endcannot", '@endcannot'];
        yield 'mismatched session close' => ["@session('status')body@endauth", '@endauth'];
        yield 'duplicate else' => ['@auth a @else b @else c @endauth', '@else'];
        yield 'empty auth argument' => ['@auth()body@endauth', '@auth'];
        yield 'too many guard arguments' => ["@auth('web', 'admin')body@endauth", '@auth'];
        yield 'dynamic guard' => ['@auth($guard)body@endauth', '@auth'];
        yield 'bare can' => ['@can body', '@can'];
        yield 'empty can' => ['@can()body@endcan', '@can'];
        yield 'empty resource' => ["@can('edit', , 'extra')body@endcan", '@can'];
        yield 'dynamic ability' => ['@can($ability)body@endcan', '@can'];
        yield 'bare session' => ['@session body', '@session'];
        yield 'empty session key' => ["@session('')body@endsession", '@session'];
        yield 'dynamic session key' => ['@session($key)body@endsession', '@session'];
        yield 'too many session keys' => ["@session('one', 'two')body@endsession", '@session'];
    }

    public function testDirectiveLookingNamesAndProtectedContextsRemainInert(): void
    {
        $source = <<<'TEMPLATE'
@author @authenticated @guestUser @cannotEdit @sessionValue
<!-- @auth hidden @endauth -->
<script>const value = "@auth('admin')";</script>
<style>/* @can('edit') */</style>
<?php $literal = '@session("status")'; ?>
TEMPLATE;
        $compiled = $this->compile($source);

        self::assertStringContainsString('@author @authenticated @guestUser @cannotEdit @sessionValue', $compiled);
        self::assertStringNotContainsString('hidden', $compiled);
        self::assertStringContainsString('const value = "@auth(\'admin\')";', $compiled);
        self::assertStringContainsString('/* @can(\'edit\') */', $compiled);
        self::assertStringContainsString('$literal = \'@session("status")\';', $compiled);
    }

    private function compile(string $source, string $view = 'Pages.Security'): string
    {
        return (new TemplateCompiler())->compile($source, $view);
    }

    /** @param array<string, mixed> $variables
     *  @return array{string, bool, mixed}
     */
    private function render(string $source, array $variables = []): array
    {
        $compiled = $this->compile($source);
        return (static function () use ($compiled, $variables): array {
            extract($variables, EXTR_SKIP);
            $level = ob_get_level();
            ob_start();
            try {
                eval('?>' . $compiled);
                return [(string) ob_get_clean(),
                    array_key_exists('value', get_defined_vars()), $value ?? null];
            } finally {
                while (ob_get_level() > $level) {
                    ob_end_clean();
                }
            }
        })();
    }
}
