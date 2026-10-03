<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Core\View;
use App\Foundation\Application;
use App\Plugins\ViewContext;
use App\Support\RuntimeContext;
use App\View\Compiler\CompilerException;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Phase 14C behavior through the real resolver, renderer, and compiled cache. */
final class ConditionalCompilerRenderTest extends TestCase
{
    /** @dataProvider lineEndings */
    public function testMultilineIfElseifElseAndNestedConditionsRenderAtRuntime(string $lineEnding): void
    {
        $project = new TemporaryProject();
        try {
            $source = <<<'TEMPLATE'
@if (
    $value !== null
    && in_array($state, ['ready', 'hello, world', ')'], true)
)
<p>{{ $value }}</p>
@elseif ($fallback)
<p>Fallback</p>
@else
<p>Other</p>
@endif
@if ($outer)@if ($inner)<b>Inner</b>@else <b>Outer</b>@endif@endif
TEMPLATE;
            $project->write('Project/Views/Conditions.squehub.php',
                str_replace("\n", $lineEnding, $source));
            RuntimeContext::select(new Application($project->path()));

            self::assertSame('<p>&lt;Ada&gt;</p><b>Inner</b>',
                $this->withoutIntertagWhitespace($this->render('Conditions', [
                    'value' => '<Ada>', 'state' => 'ready', 'fallback' => false,
                    'outer' => true, 'inner' => true,
                ])));
            self::assertSame('<p>Fallback</p><b>Outer</b>',
                $this->withoutIntertagWhitespace($this->render('Conditions', [
                    'value' => null, 'state' => 'off', 'fallback' => true,
                    'outer' => true, 'inner' => false,
                ])));
            self::assertSame('<p>Other</p>',
                $this->withoutIntertagWhitespace($this->render('Conditions', [
                    'value' => null, 'state' => 'off', 'fallback' => false,
                    'outer' => false, 'inner' => true,
                ])));
        } finally {
            $project->remove();
        }
    }

    /** @return iterable<string, array{string}> */
    public static function lineEndings(): iterable
    {
        yield 'LF' => ["\n"];
        yield 'CRLF' => ["\r\n"];
    }

    public function testUnlessAndUnselectedBranchesKeepNativePhpLaziness(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Unless.squehub.php',
                '@unless($disabled)<b>Enabled</b>@else <b>Disabled</b>@endunless'
                . '|@if($selected)Selected@else{{ $onOtherBranch() }}@endif');
            RuntimeContext::select(new Application($project->path()));
            $calls = 0;
            $onOtherBranch = static function () use (&$calls): string {
                ++$calls;
                return 'Other';
            };

            self::assertSame('<b>Enabled</b>|Selected',
                $this->render('Unless', [
                    'disabled' => false, 'selected' => true,
                    'onOtherBranch' => $onOtherBranch,
                ]));
            self::assertSame(0, $calls);
            self::assertSame('<b>Disabled</b>|Other',
                $this->render('Unless', [
                    'disabled' => true, 'selected' => false,
                    'onOtherBranch' => $onOtherBranch,
                ]));
            self::assertSame(1, $calls);
        } finally {
            $project->remove();
        }
    }

    /** @dataProvider lineEndings */
    public function testSwitchWhitespaceFallthroughNestedSwitchAndDefaultRender(string $lineEnding): void
    {
        $project = new TemporaryProject();
        try {
            $source = <<<'TEMPLATE'
@switch($outer)
    @case('active')
        @switch($inner)
            @case('x')
                X
                @break
            @default
                Y
        @endswitch
        @break
    @case('pending')
    @case('queued')
        WAITING
        @break
    @default
        UNKNOWN
@endswitch
TEMPLATE;
            $project->write('Project/Views/Switches.squehub.php',
                str_replace("\n", $lineEnding, $source));
            RuntimeContext::select(new Application($project->path()));

            self::assertSame('X', $this->withoutWhitespace($this->render('Switches', [
                'outer' => 'active', 'inner' => 'x',
            ])));
            self::assertSame('Y', $this->withoutWhitespace($this->render('Switches', [
                'outer' => 'active', 'inner' => 'other',
            ])));
            self::assertSame('WAITING', $this->withoutWhitespace($this->render('Switches', [
                'outer' => 'pending', 'inner' => 'x',
            ])));
            self::assertSame('WAITING', $this->withoutWhitespace($this->render('Switches', [
                'outer' => 'queued', 'inner' => 'x',
            ])));
            self::assertSame('UNKNOWN', $this->withoutWhitespace($this->render('Switches', [
                'outer' => 'missing', 'inner' => 'x',
            ])));
        } finally {
            $project->remove();
        }
    }

    public function testBreakInsideIfCanExitEnclosingSwitchAndRawPhpStaysExecutable(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Mixed.squehub.php', <<<'TEMPLATE'
<?php $literal = '@case("not a case")'; ?>
@php $alsoLiteral = '@endswitch'; @endphp
@switch($state)@case('active')@if($allowed)YES@break@endif NO@break@default OTHER@endswitch
TEMPLATE);
            RuntimeContext::select(new Application($project->path()));

            self::assertSame('YES', $this->render('Mixed', ['state' => 'active', 'allowed' => true]));
            self::assertSame('NO', $this->render('Mixed', ['state' => 'active', 'allowed' => false]));
            self::assertSame('OTHER', $this->render('Mixed', ['state' => 'unknown', 'allowed' => true]));
        } finally {
            $project->remove();
        }
    }

    public function testNullsafeAndMatchExpressionsAreEvaluatedByPhp(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/ModernCondition.squehub.php', <<<'TEMPLATE'
@if (
    $user?->isActive() === true
    && match ($state) {
        'ready' => true,
        default => false,
    }
)
ACTIVE
@else
INACTIVE
@endif
TEMPLATE);
            RuntimeContext::select(new Application($project->path()));
            $user = new class {
                public function isActive(): bool { return true; }
            };

            self::assertSame('ACTIVE', $this->render('ModernCondition', [
                'user' => $user, 'state' => 'ready',
            ]));
            self::assertSame('INACTIVE', $this->render('ModernCondition', [
                'user' => $user, 'state' => 'waiting',
            ]));
            self::assertSame('INACTIVE', $this->render('ModernCondition', [
                'user' => null, 'state' => 'ready',
            ]));
        } finally {
            $project->remove();
        }
    }

    public function testConditionalTitleProviderComposerAndIncludeOverrideKeepContextPrecedence(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Pages/Orders.squehub.php',
                "@section('content')@if(\$showHeading)<h1>{{ \$title }}</h1>@endif"
                . "@include('Parts.Card', ['mode' => 'compact'])|@include('Parts.Card')"
                . "@endsection@extends('Layouts.Main')");
            $project->write('Project/Views/Layouts/Main.squehub.php',
                '<head>@if(isset($title) && $title !== \'\')<title>{{ $title }}</title>'
                . '@else<title>My App</title>@endif</head>@yield(\'content\')');
            $project->write('Project/Views/Parts/Card.squehub.php',
                '@if($mode === \'compact\')C@else{{ \'F\' }}@endif@if($showBadge)+@endif');

            $app = new Application($project->path());
            $app->views()->share('mode', 'full');
            $app->views()->provide(static fn (ViewContext $context): array => [
                'showHeading' => true,
            ]);
            $app->views()->compose('Parts.Card', static fn (ViewContext $context): array => [
                'showBadge' => true,
            ]);
            RuntimeContext::select($app);

            self::assertSame(
                '<head><title>&lt;Orders&gt;</title></head><h1>&lt;Orders&gt;</h1>C+|F+',
                $this->render('Pages.Orders', ['title' => '<Orders>'])
            );
            self::assertSame(
                '<head><title>My App</title></head><h1></h1>C+|F+',
                $this->render('Pages.Orders', ['title' => ''])
            );
        } finally {
            $project->remove();
        }
    }

    public function testCachedConditionalEvaluatesFreshDataWithoutPublishingRuntimeSecrets(): void
    {
        $project = new TemporaryProject();
        try {
            $probe = 'conditional-cache-' . bin2hex(random_bytes(6));
            $project->write('Project/Views/Cached.squehub.php',
                '<?php /* ' . $probe . ' */ ?>@if($enabled){{ $value }}@else Hidden@endif');
            RuntimeContext::select(new Application($project->path()));
            $secret = 'RUNTIME_SECRET_' . bin2hex(random_bytes(6));

            self::assertSame($secret, $this->render('Cached', [
                'enabled' => true, 'value' => $secret,
            ]));
            $firstCache = $this->compiledFilesContaining($probe);
            self::assertCount(1, $firstCache);
            self::assertSame('Hidden', $this->render('Cached', [
                'enabled' => false, 'value' => 'another runtime value',
            ]));
            self::assertSame($firstCache, $this->compiledFilesContaining($probe));

            $compiled = file_get_contents($firstCache[0]);
            self::assertIsString($compiled);
            self::assertStringContainsString('$enabled', $compiled);
            self::assertStringNotContainsString($secret, $compiled);
        } finally {
            $project->remove();
        }
    }

    public function testMalformedConditionalPublishesNoCompiledCacheArtifact(): void
    {
        $project = new TemporaryProject();
        try {
            $probe = 'invalid-conditional-' . bin2hex(random_bytes(6));
            $project->write('Project/Views/Broken.squehub.php',
                '<?php /* ' . $probe . ' */ ?>@if($enabled)Missing endif');
            RuntimeContext::select(new Application($project->path()));

            try {
                $this->render('Broken', ['enabled' => true]);
                self::fail('Expected an unterminated conditional error.');
            } catch (CompilerException $exception) {
                self::assertSame('Broken', $exception->view());
            }
            self::assertSame([], $this->compiledFilesContaining($probe));
        } finally {
            $project->remove();
        }
    }

    /** @return list<string> */
    private function compiledFilesContaining(string $probe): array
    {
        $found = [];
        foreach (glob(View::application()->basePath('Storage/Views/*.php')) ?: [] as $file) {
            $compiled = file_get_contents($file);
            if (is_string($compiled) && str_contains($compiled, $probe)) {
                $found[] = $file;
            }
        }
        return $found;
    }

    private function render(string $view, array $data = []): string
    {
        ob_start();
        try {
            View::render($view, $data);
            return trim((string) ob_get_contents());
        } finally {
            ob_end_clean();
        }
    }

    private function withoutIntertagWhitespace(string $output): string
    {
        return preg_replace('/>\s+</u', '><', trim($output)) ?? $output;
    }

    private function withoutWhitespace(string $output): string
    {
        return preg_replace('/\s+/u', '', $output) ?? $output;
    }
}
