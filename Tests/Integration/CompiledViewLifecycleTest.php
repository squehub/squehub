<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Core\View;
use App\Foundation\Application;
use App\Support\RuntimeContext;
use App\View\ViewNotFoundException;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;
use Throwable;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Exercises compiled artifacts through the real View renderer and filesystem. */
final class CompiledViewLifecycleTest extends TestCase
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

    public function testSourceContentsInvalidateEvenWithTheSameSizeAndMtime(): void
    {
        $source = 'Project/Views/Pages/Changing.squehub.php';
        $this->project->write($source, 'Alpha');
        self::assertSame('Alpha', View::renderResult('Pages.Changing')->html());
        $original = $this->artifacts();
        self::assertCount(1, $original);
        $mtime = filemtime($this->project->path($source));
        self::assertIsInt($mtime);

        $this->project->write($source, 'Bravo');
        self::assertTrue(touch($this->project->path($source), $mtime));
        clearstatcache(true, $this->project->path($source));
        self::assertSame('Bravo', View::renderResult('Pages.Changing')->html());
        $current = $this->artifacts();
        self::assertCount(2, $current);
        self::assertContains($original[0], $current);
        self::assertSame('Bravo', View::renderResult('Pages.Changing')->html());
        self::assertSame($current, $this->artifacts());
    }

    public function testLogicalNamesAndRuntimeDataStaySeparateFromCompiledInstructions(): void
    {
        $source = '{{ $value }}';
        $this->project->write('Project/Views/Pages/First.squehub.php', $source);
        $this->project->write('Project/Views/Pages/Second.squehub.php', $source);

        self::assertSame('RUNTIME_SECRET_ONE',
            View::renderResult('Pages.First', ['value' => 'RUNTIME_SECRET_ONE'])->html());
        self::assertSame('RUNTIME_SECRET_TWO',
            View::renderResult('Pages.First', ['value' => 'RUNTIME_SECRET_TWO'])->html());
        self::assertSame('RUNTIME_SECRET_THREE',
            View::renderResult('Pages.Second', ['value' => 'RUNTIME_SECRET_THREE'])->html());
        self::assertCount(2, $this->artifacts());
        foreach ($this->artifacts() as $artifact) {
            $compiled = (string) file_get_contents($artifact);
            self::assertStringNotContainsString('RUNTIME_SECRET_', $compiled);
        }
    }

    /** @dataProvider damagedArtifacts */
    public function testDamagedArtifactRebuildsFromSafeSource(string $damage): void
    {
        $this->project->write('Project/Views/Pages/Damaged.squehub.php', 'HEALTHY_VIEW');
        self::assertSame('HEALTHY_VIEW', View::renderResult('Pages.Damaged')->html());
        $files = $this->artifacts();
        self::assertCount(1, $files);
        $expected = file_get_contents($files[0]);
        self::assertIsString($expected);

        if ($damage === 'missing') {
            self::assertTrue(unlink($files[0]));
        } else {
            $bytes = match ($damage) {
                'zero' => '',
                'truncated' => '<?php if (',
                'wrong-return' => '<?php return 42;',
            };
            self::assertSame(strlen($bytes), file_put_contents($files[0], $bytes));
        }
        clearstatcache(true, $files[0]);

        self::assertSame('HEALTHY_VIEW', View::renderResult('Pages.Damaged')->html());
        self::assertSame($expected, file_get_contents($files[0]));
        self::assertSame($files, $this->artifacts());
    }

    /** @return iterable<string, array{string}> */
    public static function damagedArtifacts(): iterable
    {
        yield 'missing' => ['missing'];
        yield 'zero bytes' => ['zero'];
        yield 'truncated PHP' => ['truncated'];
        yield 'wrong return value' => ['wrong-return'];
    }

    public function testRemovedSourceDoesNotExecuteItsOldArtifact(): void
    {
        $source = $this->project->path('Project/Views/Pages/Removed.squehub.php');
        $this->project->write('Project/Views/Pages/Removed.squehub.php', 'OLD_OUTPUT');
        self::assertSame('OLD_OUTPUT', View::renderResult('Pages.Removed')->html());
        self::assertCount(1, $this->artifacts());
        self::assertTrue(unlink($source));
        clearstatcache(true, $source);

        try {
            View::renderResult('Pages.Removed');
            self::fail('A compiled artifact must not replace its missing source.');
        } catch (ViewNotFoundException $error) {
            self::assertSame('Pages.Removed', $error->view());
        }
        self::assertCount(1, $this->artifacts());
    }

    /** @dataProvider nestedSources */
    public function testNestedSourceChangesSelectNewArtifacts(string $page,
        string $dependencyPath, string $beforeSource, string $afterSource,
        string $beforeHtml, string $afterHtml): void
    {
        $this->project->write('Project/Views/Pages/Nested.squehub.php', $page);
        $this->project->write($dependencyPath, $beforeSource);
        self::assertSame($beforeHtml, View::renderResult('Pages.Nested')->html());
        $before = $this->artifacts();
        self::assertCount(2, $before);

        $this->project->write($dependencyPath, $afterSource);
        self::assertSame($afterHtml, View::renderResult('Pages.Nested')->html());
        $after = $this->artifacts();
        self::assertCount(3, $after);
        foreach ($before as $artifact) {
            self::assertContains($artifact, $after);
        }
    }

    /** @return iterable<string, array{string, string, string, string, string, string}> */
    public static function nestedSources(): iterable
    {
        yield 'partial' => ["@include('Partials.Body')",
            'Project/Views/Partials/Body.squehub.php', 'OLD_PARTIAL', 'NEW_PARTIAL',
            'OLD_PARTIAL', 'NEW_PARTIAL'];
        yield 'layout' => ["@section('body')child@endsection@extends('Layouts.Shell')",
            'Project/Views/Layouts/Shell.squehub.php',
            '<main>@yield(\'body\')</main>', '<aside>@yield(\'body\')</aside>',
            '<main>child</main>', '<aside>child</aside>'];
        yield 'component' => ["@component('Badge')@endcomponent",
            'Project/Views/Components/Badge.squehub.php',
            '@props([])<b>OLD</b>', '@props([])<b>NEW</b>', '<b>OLD</b>', '<b>NEW</b>'];
    }

    public function testCorruptNestedPartialAndFragmentOwnerRecoverThroughTheSameStore(): void
    {
        $this->project->write('Project/Views/Pages/WithPartial.squehub.php',
            "@include('Partials.Body')");
        $this->project->write('Project/Views/Partials/Body.squehub.php',
            'PARTIAL_UNIQUE_MARKER');
        self::assertSame('PARTIAL_UNIQUE_MARKER',
            View::renderResult('Pages.WithPartial')->html());
        $partialArtifact = $this->artifactContaining('PARTIAL_UNIQUE_MARKER');
        file_put_contents($partialArtifact, '<?php return 42;');
        self::assertSame('PARTIAL_UNIQUE_MARKER',
            View::renderResult('Pages.WithPartial')->html());
        self::assertStringContainsString('PARTIAL_UNIQUE_MARKER',
            (string) file_get_contents($partialArtifact));

        $this->project->write('Project/Views/Pages/Fragment.squehub.php',
            "@fragment('body')FRAGMENT_UNIQUE_MARKER@endfragment");
        self::assertSame('FRAGMENT_UNIQUE_MARKER',
            View::fragment('Pages.Fragment', 'body')->html());
        $fragmentArtifact = $this->artifactContaining('FRAGMENT_UNIQUE_MARKER');
        file_put_contents($fragmentArtifact, '<?php if (');
        self::assertSame('FRAGMENT_UNIQUE_MARKER',
            View::fragment('Pages.Fragment', 'body')->html());
        self::assertStringContainsString('FRAGMENT_UNIQUE_MARKER',
            (string) file_get_contents($fragmentArtifact));
    }

    public function testWarmCanonicalizesLowercaseComponentDirectoryForRuntimeReuse(): void
    {
        $this->project->write('Project/Views/Pages/Card.squehub.php',
            "@component('Badge', ['label' => 'Warm'])@endcomponent");
        $this->project->write('Project/Views/components/Badge.squehub.php',
            "@props(['label' => 'Default'])<b>{{ \$label }}</b>");

        $warm = View::warm();
        self::assertSame(2, $warm['compiled']);
        self::assertSame(0, $warm['failed']);
        $artifacts = $this->artifacts();
        self::assertCount(2, $artifacts);
        self::assertSame('<b>Warm</b>', View::renderResult('Pages.Card')->html());
        self::assertSame($artifacts, $this->artifacts());
    }

    public function testLinkedCacheRootCannotWriteOutsideTheApplication(): void
    {
        $outside = new TemporaryProject();
        $cache = $this->project->path('Storage/Views');
        try {
            $this->project->write('Project/Views/Pages/Safe.squehub.php', 'SAFE_OUTPUT');
            if (!is_dir(dirname($cache))) {
                mkdir(dirname($cache), 0777, true);
            }
            if (!@symlink($outside->path(), $cache)) {
                self::markTestSkipped('Directory symlinks are unavailable to this test process.');
            }

            $failure = null;
            try {
                View::renderResult('Pages.Safe');
            } catch (Throwable $error) {
                $failure = $error;
            }
            self::assertInstanceOf(Throwable::class, $failure);
            self::assertStringNotContainsString($outside->path(), $failure->getMessage());
            $clearFailure = null;
            try {
                View::clearCompiled();
            } catch (Throwable $error) {
                $clearFailure = $error;
            }
            self::assertInstanceOf(Throwable::class, $clearFailure);
            self::assertSame([], glob($outside->path('*.php')) ?: []);
        } finally {
            if (is_link($cache)) {
                unlink($cache);
            }
            $outside->remove();
        }
    }

    public function testEightProcessesPublishOneCompleteArtifact(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('PHP process creation is unavailable.');
        }
        $this->project->write('Project/Views/Pages/Race.squehub.php',
            'EIGHT_WORKERS_SAME_OUTPUT');
        $barrier = $this->project->path('release-workers');
        $fixture = dirname(__DIR__) . '/Fixtures/CompiledViewWorker.php';
        $workers = [];
        try {
            for ($i = 0; $i < 8; ++$i) {
                $ready = $this->project->path('ready-' . $i);
                $process = new Process([PHP_BINARY, $fixture, $this->project->path(),
                    $barrier, $ready]);
                $process->start();
                $workers[] = [$process, $ready];
            }
            $deadline = microtime(true) + 20;
            foreach ($workers as [, $ready]) {
                while (!is_file($ready) && microtime(true) < $deadline) {
                    clearstatcache(true, $ready);
                    usleep(1000);
                }
                self::assertFileExists($ready);
            }
            self::assertTrue(is_int(file_put_contents($barrier, 'go')));
            foreach ($workers as [$process]) {
                $process->wait();
                self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
                self::assertSame('EIGHT_WORKERS_SAME_OUTPUT', $process->getOutput());
            }

            $files = $this->artifacts();
            self::assertCount(1, $files);
            self::assertGreaterThan(0, filesize($files[0]));
            foreach (glob($this->project->path('Storage/Views/*')) ?: [] as $entry) {
                self::assertStringNotContainsString('.tmp.', basename($entry));
            }
            self::assertSame('EIGHT_WORKERS_SAME_OUTPUT',
                View::renderResult('Pages.Race')->html());
        } finally {
            foreach ($workers as [$process]) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
        }
    }

    /** @return list<string> */
    private function artifacts(): array
    {
        return glob($this->project->path('Storage/Views/*.php')) ?: [];
    }

    private function artifactContaining(string $marker): string
    {
        foreach ($this->artifacts() as $artifact) {
            if (str_contains((string) file_get_contents($artifact), $marker)) {
                return $artifact;
            }
        }
        self::fail('The compiled artifact containing the fixture marker was not found.');
    }
}
