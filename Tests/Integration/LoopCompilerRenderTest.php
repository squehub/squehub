<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Core\View;
use App\Foundation\Application;
use App\Http\Request;
use App\Plugins\ViewContext;
use App\Support\RuntimeContext;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Exercises Phase 14D loops through the resolver, renderer, includes, and compiled cache. */
final class LoopCompilerRenderTest extends TestCase
{
    /** @dataProvider lineEndings */
    public function testForeachRendersOriginalSparseKeysEscapingAndEveryMetadataProperty(string $lineEnding): void
    {
        $project = new TemporaryProject();
        try {
            $source = <<<'TEMPLATE'
@foreach (
    $items
    as $key => $value
)
<row>{{ $key }}|{{ $value }}|{{ $loop->index }}|{{ $loop->iteration }}|{{ $loop->count }}|{{ $loop->remaining }}|{{ (int) $loop->first }}|{{ (int) $loop->last }}|{{ (int) $loop->even }}|{{ (int) $loop->odd }}|{{ $loop->depth }}|{{ $loop->parent === null ? 'root' : 'nested' }}</row>
@endforeach
TEMPLATE;
            $project->write('Project/Views/Loops/Metadata.squehub.php',
                str_replace("\n", $lineEnding, $source));
            RuntimeContext::select(new Application($project->path()));

            self::assertSame(
                '<row>10|&lt;A&gt;|0|1|3|2|1|0|0|1|1|root</row>'
                . '<row>40|B|1|2|3|1|0|0|1|0|1|root</row>'
                . '<row>70|C|2|3|3|0|0|1|0|1|1|root</row>',
                $this->withoutIntertagWhitespace($this->render('Loops.Metadata', [
                    'items' => [10 => '<A>', 40 => 'B', 70 => 'C'],
                ]))
            );
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

    public function testNestedForeachForelseAndTripleParentRestoreOuterAndDeveloperLoop(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Loops/Nested.squehub.php', <<<'TEMPLATE'
<before>{{ $loop }}</before>
@foreach($groups as $group)
<outer>{{ $loop->iteration }}:{{ $loop->depth }}:{{ $loop->parent === null ? 'root' : 'wrong' }}</outer>
@forelse($group['children'] as $child)
<inner>{{ $loop->iteration }}:{{ $loop->depth }}:{{ $loop->parent->iteration }}</inner>
@foreach($child['leaves'] as $leaf)
<deep>{{ $loop->depth }}:{{ $loop->parent->depth }}:{{ $loop->parent->parent->iteration }}:{{ $leaf }}</deep>
@endforeach
<inner-after>{{ $loop->iteration }}</inner-after>
@empty
<empty>{{ $loop->iteration }}:{{ $loop->depth }}</empty>
@endforelse
<outer-after>{{ $loop->iteration }}</outer-after>
@endforeach
<after>{{ $loop }}</after>
TEMPLATE);
            $project->write('Project/Views/Loops/UndefinedAfter.squehub.php',
                '@foreach($items as $item)<item>{{ $loop->iteration }}</item>@endforeach'
                . '<after>{{ isset($loop) ? "stale" : "undefined" }}</after>');
            RuntimeContext::select(new Application($project->path()));

            $groups = [
                ['children' => [['leaves' => ['a', 'b']]]],
                ['children' => []],
            ];
            self::assertSame(
                '<before>developer</before>'
                . '<outer>1:1:root</outer><inner>1:2:1</inner>'
                . '<deep>3:2:1:a</deep><deep>3:2:1:b</deep>'
                . '<inner-after>1</inner-after><outer-after>1</outer-after>'
                . '<outer>2:1:root</outer><empty>2:1</empty><outer-after>2</outer-after>'
                . '<after>developer</after>',
                $this->withoutIntertagWhitespace($this->render('Loops.Nested', [
                    'groups' => $groups,
                    'loop' => 'developer',
                ]))
            );
            self::assertSame('<item>1</item><after>undefined</after>',
                $this->render('Loops.UndefinedAfter', ['items' => ['one']]));
        } finally {
            $project->remove();
        }
    }

    public function testGeneratorExpressionsRunOncePreserveKeysAndDriveForelseEmptyBranch(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Loops/Generators.squehub.php', <<<'TEMPLATE'
@foreach($loadItems() as $key => $item)
<item>{{ $key }}:{{ $item }}:{{ $loop->index }}:{{ $loop->remaining }}:{{ (int) $loop->last }}</item>
@endforeach
@forelse($loadReports() as $key => $report)
<report>{{ $key }}:{{ $report }}:{{ $loop->count }}</report>
@empty<no-reports/>@endforelse
@forelse($loadEmpty() as $item)<unexpected/>@empty<empty/>@endforelse
TEMPLATE);
            RuntimeContext::select(new Application($project->path()));
            $calls = ['items' => 0, 'reports' => 0, 'empty' => 0];
            $loadItems = static function () use (&$calls): \Generator {
                ++$calls['items'];
                yield 'admin' => '<Ada>';
                yield 40 => 'Bob';
            };
            $loadReports = static function () use (&$calls): \Generator {
                ++$calls['reports'];
                yield 'daily' => 'ready';
            };
            $loadEmpty = static function () use (&$calls): \Generator {
                ++$calls['empty'];
                if (false) {
                    yield 'never';
                }
                return;
            };

            self::assertSame(
                '<item>admin:&lt;Ada&gt;:0:1:0</item><item>40:Bob:1:0:1</item>'
                . '<report>daily:ready:1</report><empty/>',
                $this->withoutIntertagWhitespace($this->render('Loops.Generators', [
                    'loadItems' => $loadItems,
                    'loadReports' => $loadReports,
                    'loadEmpty' => $loadEmpty,
                ]))
            );
            self::assertSame(['items' => 1, 'reports' => 1, 'empty' => 1], $calls);
        } finally {
            $project->remove();
        }
    }

    public function testForelseBreakAndContinueDoNotRenderEmptyAndKeepMetadataCurrent(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Loops/Flow.squehub.php', <<<'TEMPLATE'
@forelse($items as $item)
@if($item === 2)@continue@endif
<kept>{{ $loop->iteration }}:{{ $loop->remaining }}:{{ $item }}</kept>
@if($item === 3)@break@endif
@empty<empty/>@endforelse
TEMPLATE);
            RuntimeContext::select(new Application($project->path()));
            self::assertSame('<kept>1:2:1</kept><kept>3:0:3</kept>',
                $this->withoutIntertagWhitespace($this->render('Loops.Flow', [
                    'items' => [1, 2, 3],
                ])));
            self::assertSame('<empty/>', $this->render('Loops.Flow', ['items' => []]));
        } finally {
            $project->remove();
        }
    }

    public function testForWhileConditionsAndNearestBreakableControlRenderNatively(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Loops/Native.squehub.php', <<<'TEMPLATE'
@for(
    $i = 0;
    $i < 4;
    $i++
)
@if($i === 1)@continue@endif
<for>{{ $i }}</for>
@endfor
@while($remaining-- > 0)
<while>{{ $remaining }}</while>
@if($remaining === 1)@break@endif
@endwhile
@foreach($items as $item)
@switch($item)
@case('keep')<switch>keep</switch>@break
@default<switch>other</switch>
@endswitch
<after-switch>{{ $loop->iteration }}</after-switch>
@endforeach
TEMPLATE);
            RuntimeContext::select(new Application($project->path()));
            self::assertSame(
                '<for>0</for><for>2</for><for>3</for>'
                . '<while>2</while><while>1</while>'
                . '<switch>keep</switch><after-switch>1</after-switch>'
                . '<switch>other</switch><after-switch>2</after-switch>',
                $this->withoutIntertagWhitespace($this->render('Loops.Native', [
                    'remaining' => 3,
                    'items' => ['keep', 'other'],
                ]))
            );
        } finally {
            $project->remove();
        }
    }

    public function testNativeForAndWhileKeepExistingLoopVariableWithoutFabricatingMetadata(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Loops/NativeScope.squehub.php',
                '@for($i = 0; $i < 2; $i++)'
                . '<for>{{ $loop ?? "undefined" }}</for>@endfor'
                . '@while($remaining-- > 0)'
                . '<while>{{ $loop ?? "undefined" }}</while>@endwhile');
            RuntimeContext::select(new Application($project->path()));

            self::assertSame('<for>undefined</for><for>undefined</for><while>undefined</while>',
                $this->render('Loops.NativeScope', ['remaining' => 1]));
            self::assertSame('<for>developer</for><for>developer</for><while>developer</while>',
                $this->render('Loops.NativeScope', ['remaining' => 1, 'loop' => 'developer']));
        } finally {
            $project->remove();
        }
    }

    public function testIncludesInheritLoopAndIterationValuesDespiteExplicitLoopOverlays(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Loops/Includes.squehub.php', <<<'TEMPLATE'
@foreach($rows as $row)
<parent>{{ $loop->iteration }}</parent>
@include('Parts.LoopRow', ['loop' => 'overlay'])
<after>{{ $loop->iteration }}</after>
@endforeach
<restored>{{ $loop }}</restored>
TEMPLATE);
            $project->write('Project/Views/Parts/LoopRow.squehub.php', <<<'TEMPLATE'
<row>{{ $loop->iteration }}:{{ $row['label'] }}</row>
@include('Parts.LoopDeep', ['loop' => 'nested overlay'])
@foreach($row['details'] as $detail)
<child>{{ $loop->parent->iteration }}:{{ $loop->iteration }}:{{ $detail }}</child>
@endforeach
<row-after>{{ $loop->iteration }}</row-after>
TEMPLATE);
            $project->write('Project/Views/Parts/LoopDeep.squehub.php',
                '<deep>{{ $loop->iteration }}:{{ $row[\'label\'] }}</deep>');
            RuntimeContext::select(new Application($project->path()));

            self::assertSame(
                '<parent>1</parent><row>1:&lt;A&gt;</row><deep>1:&lt;A&gt;</deep>'
                . '<child>1:1:x</child><child>1:2:y</child><row-after>1</row-after><after>1</after>'
                . '<parent>2</parent><row>2:B</row><deep>2:B</deep>'
                . '<child>2:1:z</child><row-after>2</row-after><after>2</after>'
                . '<restored>developer</restored>',
                $this->withoutIntertagWhitespace($this->render('Loops.Includes', [
                    'loop' => 'developer',
                    'rows' => [
                        ['label' => '<A>', 'details' => ['x', 'y']],
                        ['label' => 'B', 'details' => ['z']],
                    ],
                ]))
            );
        } finally {
            $project->remove();
        }
    }

    public function testSharedProviderComposerAndControllerTitleWorkThroughLoopAndLayout(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Pages/LoopUsers.squehub.php', <<<'TEMPLATE'
@section('content')
<h1>{{ $title }}</h1>
<nav>@foreach($navigation as $entry)<a>{{ $loop->iteration }}:{{ $entry }}</a>@endforeach</nav>
<main>@forelse($users as $user)<user>{{ $loop->iteration }}/{{ $loop->count }}:{{ $user }}</user>@empty<empty/>@endforelse</main>
@endsection
@extends('Layouts.LoopMain')
TEMPLATE);
            $project->write('Project/Views/Layouts/LoopMain.squehub.php',
                '<title>{{ $title }}</title><brand>{{ $appName }}</brand>@yield(\'content\')');
            $app = new Application($project->path());
            $app->views()->share('appName', 'SqueHub');
            $app->views()->provide(static fn (ViewContext $context): array => [
                'navigation' => ['Home', 'Users'],
            ]);
            $app->views()->compose('Pages.LoopUsers', static fn (ViewContext $context): array => [
                'users' => ['<Ada>', 'Bob'],
            ]);
            RuntimeContext::select($app);

            self::assertSame(
                '<title>&lt;Users&gt;</title><brand>SqueHub</brand><h1>&lt;Users&gt;</h1>'
                . '<nav><a>1:Home</a><a>2:Users</a></nav>'
                . '<main><user>1/2:&lt;Ada&gt;</user><user>2/2:Bob</user></main>',
                $this->withoutIntertagWhitespace($this->render('Pages.LoopUsers', [
                    'title' => '<Users>',
                ]))
            );
            self::assertSame(
                '<title>Empty</title><brand>SqueHub</brand><h1>Empty</h1>'
                . '<nav><a>1:Home</a><a>2:Users</a></nav><main><empty/></main>',
                $this->withoutIntertagWhitespace($this->render('Pages.LoopUsers', [
                    'title' => 'Empty', 'users' => [],
                ]))
            );
        } finally {
            $project->remove();
        }
    }

    public function testCompiledLoopIsReusedWithFreshRuntimeDataAndNoDataInCompiledPhp(): void
    {
        $project = new TemporaryProject();
        try {
            $probe = 'loop-cache-' . bin2hex(random_bytes(6));
            $project->write('Project/Views/Loops/Cached.squehub.php',
                '<?php /* ' . $probe . ' */ ?>'
                . '@foreach($items as $item)<item>{{ $loop->iteration }}/{{ $loop->count }}:{{ $item }}</item>@endforeach');
            RuntimeContext::select(new Application($project->path()));
            $secret = 'RUNTIME_LOOP_SECRET_' . bin2hex(random_bytes(6));

            self::assertSame('<item>1/1:' . $secret . '</item>',
                $this->render('Loops.Cached', ['items' => [$secret]]));
            $firstCache = $this->compiledFilesContaining($probe);
            self::assertCount(1, $firstCache);
            self::assertSame('<item>1/3:A</item><item>2/3:B</item><item>3/3:C</item>',
                $this->render('Loops.Cached', ['items' => ['A', 'B', 'C']]));
            self::assertSame('', $this->render('Loops.Cached', ['items' => []]));
            self::assertSame($firstCache, $this->compiledFilesContaining($probe));

            $compiled = file_get_contents($firstCache[0]);
            self::assertIsString($compiled);
            self::assertStringNotContainsString($secret, $compiled);
        } finally {
            $project->remove();
        }
    }

    public function testLoopMetadataIsReadonlyAndRuntimeFailureDoesNotLeakIntoNextRender(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Loops/Readonly.squehub.php',
                '@foreach($items as $item)'
                . '@php try { $loop->index = 99; } catch (\\Error $exception) { echo "readonly"; } @endphp'
                . ':{{ $loop->index }}@endforeach');
            $project->write('Project/Views/Loops/Throws.squehub.php',
                '@foreach($groups as $group)@foreach($group as $item)'
                . '@php if ($loop->depth !== 2) { throw new \\RuntimeException("not nested"); } '
                . 'throw new \\RuntimeException("body failure"); @endphp'
                . '@endforeach@endforeach');
            $project->write('Project/Views/Loops/Clean.squehub.php',
                '{{ isset($loop) ? "stale" : "clean" }}');
            RuntimeContext::select(new Application($project->path()));

            self::assertSame('readonly:0', $this->render('Loops.Readonly', ['items' => ['x']]));
            try {
                $this->render('Loops.Throws', ['groups' => [['x']]]);
                self::fail('Expected the inner loop body to throw.');
            } catch (\App\View\ViewRenderException $exception) {
                self::assertSame('Loops.Throws', $exception->view());
                self::assertStringNotContainsString('body failure', $exception->getMessage());
                self::assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
                self::assertSame('body failure', $exception->getPrevious()->getMessage());
            }
            self::assertSame('clean', $this->render('Loops.Clean'));
        } finally {
            $project->remove();
        }
    }

    public function testRequestAndApplicationRendersDoNotShareLoopState(): void
    {
        $firstProject = new TemporaryProject();
        $secondProject = new TemporaryProject();
        try {
            $source = '@foreach($items as $item)<item>{{ $loop->iteration }}/{{ $loop->count }}:{{ $item }}</item>@endforeach';
            $firstProject->write('Project/Views/Loops/Isolated.squehub.php', $source);
            $secondProject->write('Project/Views/Loops/Isolated.squehub.php', $source);
            $first = new Application($firstProject->path());
            $second = new Application($secondProject->path());
            $first->views()->provide(static fn (ViewContext $context): array => [
                'items' => $context->request()?->path() === '/first' ? ['A', 'B'] : ['C'],
            ]);
            $second->views()->share('items', ['Z']);

            RuntimeContext::select($first);
            $first->views()->beginRequest(new Request('GET', '/first'));
            try {
                self::assertSame('<item>1/2:A</item><item>2/2:B</item>',
                    $this->render('Loops.Isolated'));
            } finally {
                $first->views()->endRequest();
            }
            $first->views()->beginRequest(new Request('GET', '/second'));
            try {
                self::assertSame('<item>1/1:C</item>', $this->render('Loops.Isolated'));
            } finally {
                $first->views()->endRequest();
            }
            RuntimeContext::select($second);
            self::assertSame('<item>1/1:Z</item>', $this->render('Loops.Isolated'));
        } finally {
            $firstProject->remove();
            $secondProject->remove();
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
}
