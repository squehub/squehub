<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Foundation\Application;
use App\Plugins\View;
use App\Support\RuntimeContext;
use App\View\Compiler\CompilerException;
use App\View\ViewNotFoundException;
use App\View\FragmentNotFoundException;
use App\View\InvalidFragmentNameException;
use App\View\ViewHttpException;
use App\View\ViewRenderException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Throwable;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Exercises logical diagnostics through the actual cached View renderer. */
final class ViewDiagnosticRenderTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = new TemporaryProject();
        RuntimeContext::select(new Application($this->project->path()));
    }

    protected function tearDown(): void
    {
        $this->project->remove();
        parent::tearDown();
    }

    public function testMissingRootAndFragmentRootHaveNoInventedSourceLine(): void
    {
        [$error, $output] = $this->renderFailure('Missing.Page');
        self::assertInstanceOf(ViewNotFoundException::class, $error);
        self::assertSame('Missing.Page', $error->view());
        self::assertSame('View "Missing.Page" was not found.', $error->getMessage());
        self::assertSame('', $output);

        try {
            View::fragment('Missing.Page', 'body');
            self::fail('A missing Fragment root must fail.');
        } catch (ViewNotFoundException $fragmentError) {
            self::assertSame('Missing.Page', $fragmentError->view());
        }

        [$invalid] = $this->renderFailure('../PATH_SECRET');
        self::assertInstanceOf(ViewNotFoundException::class, $invalid);
        self::assertSame('[invalid logical name]', $invalid->view());
        self::assertStringNotContainsString('PATH_SECRET', $invalid->getMessage());
    }

    public function testAbsentSelectedFragmentAndInvalidNameHaveNoSourceLine(): void
    {
        $this->project->write('Project/Views/Pages/Fragments.squehub.php',
            "@fragment('known')known@endfragment");
        try {
            View::fragment('Pages.Fragments', 'missing');
            self::fail('An absent selected Fragment must fail.');
        } catch (FragmentNotFoundException $error) {
            self::assertSame('Pages.Fragments', $error->view());
            self::assertSame('missing', $error->fragment());
            self::assertFalse(method_exists($error, 'sourceLine'));
        }
        try {
            View::fragment('Pages.Fragments', 'FRAGMENT_SECRET/<script>');
            self::fail('An invalid public Fragment name must fail.');
        } catch (InvalidFragmentNameException $error) {
            self::assertSame('Pages.Fragments', $error->view());
            self::assertStringNotContainsString('FRAGMENT_SECRET', $error->getMessage());
            self::assertFalse(method_exists($error, 'sourceLine'));
        }
        try {
            View::fragment('Pages.Fragments', str_repeat('F', 129));
            self::fail('An excessive public Fragment name must fail.');
        } catch (InvalidFragmentNameException $error) {
            self::assertSame('Pages.Fragments', $error->view());
            self::assertStringNotContainsString(str_repeat('F', 129), $error->getMessage());
        }
    }

    public function testMissingStaticDependenciesExposeCallerLineAndDirective(): void
    {
        $cases = [
            ['Pages.LayoutMiss', "\n@extends('Layouts.Absent')", 'Layout "Layouts.Absent"', '@extends'],
            ['Pages.IncludeMiss', "\n@include('Partials.Absent')", 'Included View "Partials.Absent"', '@include'],
            ['Pages.ComponentMiss', "\n@component('Absent')@endcomponent", 'Component "Components.Absent"', '@component'],
        ];
        foreach ($cases as [$view, $source, $reason, $directive]) {
            $this->project->write('Project/Views/' . str_replace('.', '/', $view)
                . '.squehub.php', $source);
            [$error, $output] = $this->renderFailure($view);
            self::assertInstanceOf(CompilerException::class, $error);
            self::assertSame($view, $error->view());
            self::assertSame(2, $error->sourceLine());
            self::assertStringContainsString($reason, $error->reason());
            self::assertSame($directive, $error->directive());
            self::assertStringNotContainsString($this->project->path(), $error->getMessage());
            self::assertSame('', $output);
        }

        $this->project->write('Project/Views/Pages/Optional.squehub.php',
            "@includeOptional('Partials.Absent')after");
        self::assertSame('after', $this->render('Pages.Optional'));
    }

    public function testFragmentNestedDependencyUsesSelectedBodyOriginWithoutRunningSiblings(): void
    {
        $this->project->write('Project/Views/Pages/Fragment.squehub.php', <<<'VIEW'
@php throw new \RuntimeException('SIBLING_SECRET'); @endphp
@fragment('body')
@include('Partials.Absent')
@endfragment
VIEW);
        try {
            View::fragment('Pages.Fragment', 'body');
            self::fail('The selected Fragment should report its missing Include.');
        } catch (CompilerException $error) {
            self::assertSame('Pages.Fragment', $error->view());
            self::assertSame(3, $error->sourceLine());
            self::assertSame('@include', $error->directive());
            self::assertStringContainsString('Partials.Absent', $error->reason());
            self::assertStringNotContainsString('SIBLING_SECRET', $error->getMessage());
        }
    }

    public function testLayoutIncludeAndMixedCyclesExplainTheLogicalActiveChain(): void
    {
        $this->project->write('Project/Views/Pages/LayoutCycle.squehub.php',
            "@extends('Layouts.A')");
        $this->project->write('Project/Views/Layouts/A.squehub.php',
            "@extends('Layouts.B')");
        $this->project->write('Project/Views/Layouts/B.squehub.php',
            "@extends('Layouts.A')");
        $this->project->write('Project/Views/Pages/IncludeCycle.squehub.php',
            "@include('Partials.A')");
        $this->project->write('Project/Views/Partials/A.squehub.php',
            "@include('Partials.B')");
        $this->project->write('Project/Views/Partials/B.squehub.php',
            "@include('Partials.A')");
        $this->project->write('Project/Views/Pages/MixedCycle.squehub.php',
            "@component('Card')@endcomponent");
        $this->project->write('Project/Views/Components/Card.squehub.php',
            "@props([])@include('Partials.CardBody')");
        $this->project->write('Project/Views/Partials/CardBody.squehub.php',
            "@component('Card')@endcomponent");
        $this->project->write('Project/Views/Pages/Healthy.squehub.php', 'healthy');

        foreach ([
            ['Pages.LayoutCycle', ['View Pages.LayoutCycle', 'Layout Layouts.A',
                'Layout Layouts.B', 'Layout Layouts.A'], '@extends'],
            ['Pages.IncludeCycle', ['View Pages.IncludeCycle', 'Include Partials.A',
                'Include Partials.B', 'Include Partials.A'], '@include'],
            ['Pages.MixedCycle', ['View Pages.MixedCycle', 'Component Components.Card',
                'Include Partials.CardBody', 'Component Components.Card'], '@component'],
        ] as [$view, $chain, $directive]) {
            [$error, $output] = $this->renderFailure($view);
            self::assertInstanceOf(CompilerException::class, $error);
            self::assertSame($chain, $error->dependencyChain());
            self::assertSame($directive, $error->directive());
            self::assertStringContainsString(implode(' -> ', $chain), $error->getMessage());
            self::assertStringNotContainsString($this->project->path(), $error->getMessage());
            self::assertSame('', $output);
            self::assertSame('healthy', $this->render('Pages.Healthy'));
        }

        // Sequential reuse is not recursion: the chain is active, not global.
        $this->project->write('Project/Views/Components/Badge.squehub.php',
            '@props([])<b>badge</b>');
        $this->project->write('Project/Views/Pages/Repeated.squehub.php',
            "@component('Badge')@endcomponent@component('Badge')@endcomponent");
        self::assertSame('<b>badge</b><b>badge</b>', $this->render('Pages.Repeated'));
    }

    public function testInRootSymlinkAliasStillTriggersPhysicalCycleWithLogicalMessage(): void
    {
        $this->project->write('Project/Views/Partials/Real.squehub.php',
            "@include('Partials.Alias')");
        $this->project->write('Project/Views/Pages/Alias.squehub.php',
            "@include('Partials.Real')");
        $link = $this->project->path('Project/Views/Partials/Alias.squehub.php');
        if (!@symlink($this->project->path('Project/Views/Partials/Real.squehub.php'), $link)) {
            self::markTestSkipped('File symlinks are unavailable to this test process.');
        }
        try {
            [$error, $output] = $this->renderFailure('Pages.Alias');
            self::assertInstanceOf(CompilerException::class, $error);
            self::assertSame(['View Pages.Alias', 'Include Partials.Real',
                'Include Partials.Alias'], $error->dependencyChain());
            self::assertStringNotContainsString($this->project->path(), $error->getMessage());
            self::assertSame('', $output);
        } finally {
            unlink($link);
        }
    }

    public function testRuntimeFailurePreservesCauseWithoutInventedSourceLineOrLeakingOutput(): void
    {
        $this->project->write('Project/Views/Partials/Runtime.squehub.php',
            "PARTIAL<?php throw new \\RuntimeException('VIEW_SECRET'); ?>");
        $this->project->write('Project/Views/Pages/Runtime.squehub.php',
            "BEFORE\n@include('Partials.Runtime')");
        $this->project->write('Project/Views/Pages/Healthy.squehub.php', 'healthy');

        [$error, $output] = $this->renderFailure('Pages.Runtime');
        self::assertInstanceOf(ViewRenderException::class, $error);
        self::assertSame('Partials.Runtime', $error->view());
        self::assertSame('runtime', $error->category());
        self::assertSame('A PHP expression failed while rendering.', $error->reason());
        self::assertInstanceOf(RuntimeException::class, $error->getPrevious());
        self::assertSame('VIEW_SECRET', $error->getPrevious()->getMessage());
        self::assertStringNotContainsString('VIEW_SECRET', $error->getMessage());
        self::assertStringNotContainsString($this->project->path(), $error->getMessage());
        self::assertFalse(method_exists($error, 'sourceLine'));
        self::assertSame('', $output);
        self::assertSame('healthy', $this->render('Pages.Healthy'));
    }

    public function testHttpStatusFromTemplateKeepsStatusAndOriginalCause(): void
    {
        $this->project->write('Project/Views/Pages/HttpFailure.squehub.php',
            "<?php throw new \\App\\Http\\Exception\\HttpException(503, 'PATH_SECRET'); ?>");

        [$error, $output] = $this->renderFailure('Pages.HttpFailure');
        self::assertInstanceOf(ViewHttpException::class, $error);
        self::assertSame('Pages.HttpFailure', $error->view());
        self::assertSame(503, $error->status());
        self::assertInstanceOf(\App\Http\Exception\HttpException::class,
            $error->getPrevious());
        self::assertSame('PATH_SECRET', $error->getPrevious()->getMessage());
        self::assertStringNotContainsString('PATH_SECRET', $error->getMessage());
        self::assertSame('', $output);
    }

    /** @dataProvider lineEndings */
    public function testSourceChangeAndLineEndingsDoNotReuseStaleDiagnostics(string $ending): void
    {
        $path = 'Project/Views/Pages/Changing.squehub.php';
        $this->project->write($path, "first{$ending}second");
        self::assertSame("first{$ending}second", $this->render('Pages.Changing'));

        $this->project->write($path,
            "first{$ending}second{$ending}@include('Partials.Absent')");
        [$error] = $this->renderFailure('Pages.Changing');
        self::assertInstanceOf(CompilerException::class, $error);
        self::assertSame('Pages.Changing', $error->view());
        self::assertSame(3, $error->sourceLine());
        self::assertSame('@include', $error->directive());
    }

    /** @return iterable<string, array{string}> */
    public static function lineEndings(): iterable
    {
        yield 'LF' => ["\n"];
        yield 'CRLF' => ["\r\n"];
    }

    /** @return array{Throwable, string} */
    private function renderFailure(string $view): array
    {
        $level = ob_get_level();
        ob_start();
        $error = null;
        try {
            View::render($view);
        } catch (Throwable $failure) {
            $error = $failure;
        } finally {
            $output = (string) ob_get_contents();
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
        self::assertInstanceOf(Throwable::class, $error,
            'Expected the View render to fail.');
        return [$error, $output];
    }

    private function render(string $view): string
    {
        ob_start();
        try {
            View::render($view);
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
