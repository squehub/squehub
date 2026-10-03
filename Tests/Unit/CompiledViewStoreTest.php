<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\View\Compiled\CompiledViewException;
use App\View\Compiled\CompiledViewStore;
use App\View\Compiled\OpcacheBridge;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Verifies artifact identity, ownership, recovery, and failure behavior. */
final class CompiledViewStoreTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = new TemporaryProject();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
        parent::tearDown();
    }

    public function testFingerprintFramesVersionNameSourceModeAndCompiledCode(): void
    {
        $store = new CompiledViewStore($this->project->path(),
            new OpcacheBridge(static fn (): bool => false));
        $base = $store->fingerprint('Pages.Home', 'A', '<?php echo "A";', 'template');
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', $base);
        self::assertSame($base, $store->fingerprint('Pages.Home', 'A',
            '<?php echo "A";', 'template'));
        self::assertNotSame($base, $store->fingerprint('Pages.Other', 'A',
            '<?php echo "A";', 'template'));
        self::assertNotSame($base, $store->fingerprint('Pages.Home', 'B',
            '<?php echo "A";', 'template'));
        self::assertNotSame($base, $store->fingerprint('Pages.Home', 'A',
            '<?php echo "B";', 'template'));
        self::assertNotSame($base, $store->fingerprint('Pages.Home', 'A',
            '<?php echo "A";', 'component'));
        $later = new CompiledViewStore($this->project->path(),
            new OpcacheBridge(static fn (): bool => false), 'future-format');
        self::assertNotSame($base, $later->fingerprint('Pages.Home', 'A',
            '<?php echo "A";', 'template'));
    }

    public function testPreparationIsDeterministicAndDoesNotRewriteHealthyArtifact(): void
    {
        $store = new CompiledViewStore($this->project->path(),
            new OpcacheBridge(static fn (): bool => false));
        $compiled = '<?php echo "hello";';
        $first = $store->prepare('Pages.Home', 'hello source', $compiled, 'template');
        self::assertTrue($first['compiled']);
        self::assertStringStartsWith(str_replace('\\', '/',
            $this->project->path('Storage/Views/squehub-view-')), $first['path']);
        self::assertSame($compiled, file_get_contents($first['path']));
        $mtime = filemtime($first['path']);
        $second = $store->prepare('Pages.Home', 'hello source', $compiled, 'template');
        self::assertFalse($second['compiled']);
        self::assertSame($first['path'], $second['path']);
        self::assertSame($mtime, filemtime($second['path']));
    }

    public function testCorruptArtifactIsReplacedOnlyAfterTargetedOpcacheInvalidation(): void
    {
        $invalidated = [];
        $bridge = new OpcacheBridge(static fn (): bool => true,
            static function (string $path) use (&$invalidated): bool {
                $invalidated[] = $path;
                return true;
            });
        $store = new CompiledViewStore($this->project->path(), $bridge);
        $first = $store->prepare('Pages.Home', 'source', '<?php echo "ok";', 'template');
        self::assertSame(0, count($invalidated));
        file_put_contents($first['path'], '<?php return 42;');
        $recovered = $store->prepare('Pages.Home', 'source', '<?php echo "ok";', 'template');
        self::assertTrue($recovered['compiled']);
        self::assertSame($first['path'], $recovered['path']);
        self::assertSame([$first['path'], $first['path']], $invalidated);
        self::assertSame('<?php echo "ok";', file_get_contents($first['path']));
    }

    public function testFailedOpcacheInvalidationLeavesCorruptArtifactForOperator(): void
    {
        $store = new CompiledViewStore($this->project->path(),
            new OpcacheBridge(static fn (): bool => true,
                static fn (string $path): bool => false));
        $first = $store->prepare('Pages.Home', 'source', '<?php echo "ok";', 'template');
        file_put_contents($first['path'], 'corrupt');
        try {
            $store->prepare('Pages.Home', 'source', '<?php echo "ok";', 'template');
            self::fail('Failed OPcache invalidation must stop replacement.');
        } catch (CompiledViewException $error) {
            self::assertStringContainsString('OPcache invalidation failed', $error->getMessage());
        }
        self::assertSame('corrupt', file_get_contents($first['path']));
    }

    public function testClearOwnsOnlyCompiledArtifactsAndOldTemporaryFiles(): void
    {
        $store = new CompiledViewStore($this->project->path(),
            new OpcacheBridge(static fn (): bool => false));
        $first = $store->prepare('Pages.Home', 'source', '<?php echo "ok";', 'template');
        $root = dirname($first['path']);
        $fingerprint = $store->fingerprint('Pages.Home', 'source',
            '<?php echo "ok";', 'template');
        $oldTemp = $root . '/.squehub-view-' . $fingerprint . '.tmp.' . str_repeat('a', 32);
        $freshTemp = $root . '/.squehub-view-' . $fingerprint . '.tmp.' . str_repeat('b', 32);
        file_put_contents($oldTemp, 'old');
        file_put_contents($freshTemp, 'fresh');
        touch($oldTemp, time() - 7200);
        file_put_contents($root . '/keep.txt', 'unrelated');

        self::assertSame(2, $store->clear());
        self::assertFileDoesNotExist($first['path']);
        self::assertFileDoesNotExist($oldTemp);
        self::assertFileExists($freshTemp);
        self::assertSame('unrelated', file_get_contents($root . '/keep.txt'));
        self::assertSame(0, $store->clear());
    }

    public function testInvalidGeneratedPhpCannotPublishACompiledArtifact(): void
    {
        $store = new CompiledViewStore($this->project->path(),
            new OpcacheBridge(static fn (): bool => false));
        try {
            $store->prepare('Pages.Broken', 'source', '<?php if (', 'template');
            self::fail('Invalid generated PHP must not be published.');
        } catch (CompiledViewException $error) {
            self::assertStringContainsString('invalid PHP syntax', $error->getMessage());
        }
        self::assertSame([], glob($this->project->path('Storage/Views/*.php')) ?: []);
    }
}
