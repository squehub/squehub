<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\RateLimit\RateLimitException;
use App\RateLimit\RateLimiter;
use App\RateLimit\Stores\FileRateLimitStore;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Real local files, corruption, privacy, and coordinated subprocesses. */
final class RateLimitFileTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void { $this->project = new TemporaryProject(); }
    protected function tearDown(): void { $this->project->remove(); }

    public function testPersistencePrivacyPolicyChangeAndClear(): void
    {
        $root = $this->project->path('Storage/RateLimits');
        $clock = static fn (): DateTimeImmutable => new DateTimeImmutable('@1000');
        $a = new RateLimiter(new FileRateLimitStore($root, 'app-one'), 'app-one', clock: $clock);
        self::assertDirectoryDoesNotExist($root);
        self::assertTrue($a->consume('login', 'sensitive-user@example.test', 1, 60)->allowed());
        $b = new RateLimiter(new FileRateLimitStore($root, 'app-one'), 'app-one', clock: $clock);
        self::assertTrue($b->consume('login', 'sensitive-user@example.test', 1, 60)->denied());
        for ($i = 0; $i < 3; ++$i) {
            self::assertTrue($b->consume('login', 'sensitive-user@example.test', 1, 60)->denied());
        }
        $appOneRecords = glob($root . '/' . hash('sha256', 'app-one') . '/*.json');
        self::assertCount(1, $appOneRecords);
        self::assertSame(2, json_decode((string) file_get_contents($appOneRecords[0]), true)['count']);
        $other = new RateLimiter(new FileRateLimitStore($root, 'app-two'), 'app-two', clock: $clock);
        self::assertTrue($other->consume('login', 'sensitive-user@example.test', 1, 60)->allowed());
        self::assertTrue($b->consume('login', 'sensitive-user@example.test', 2, 120)->allowed());
        self::assertTrue($b->clear('login', 'sensitive-user@example.test'));
        self::assertFalse($b->clear('login', 'sensitive-user@example.test'));
        self::assertTrue($a->consume('login', 'sensitive-user@example.test', 1, 60)->allowed());

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        $contents = '';
        foreach ($files as $file) {
            if (!$file->isFile()) continue;
            $contents .= $file->getPathname() . file_get_contents($file->getPathname());
            if (str_ends_with($file->getFilename(), '.json')) {
                $record = json_decode((string) file_get_contents($file->getPathname()), true);
                self::assertSame(['version', 'count', 'limit', 'window_seconds', 'resets_at'], array_keys($record));
            }
        }
        self::assertStringNotContainsString('sensitive-user@example.test', $contents);
        self::assertStringNotContainsString('login', $contents);
    }

    public function testCorruptionFailsClosedAndBadRootFailsClearly(): void
    {
        $root = $this->project->path('RateLimits');
        $store = new FileRateLimitStore($root, 'test');
        $fingerprint = hash('sha256', 'entry');
        $store->consume($fingerprint, 1, 60, new DateTimeImmutable('@1000'));
        $record = $root . '/' . hash('sha256', 'test') . '/' . $fingerprint . '.json';
        file_put_contents($record, '{broken');
        try { $store->consume($fingerprint, 1, 60, new DateTimeImmutable('@1060')); self::fail('Corruption should fail closed.'); }
        catch (RateLimitException $failure) { self::assertStringContainsString('corrupt', $failure->getMessage()); }
        // The failed read released its per-key lock. A later PHP process must
        // be able to acquire it after an operator repairs the record.
        file_put_contents($record, '{"version":1,"count":1,"limit":1,"window_seconds":60,"resets_at":1060}');
        $barrier = $this->project->path('go');
        $ready = $this->project->path('ready');
        file_put_contents($barrier, 'go');
        $worker = new Process([PHP_BINARY, dirname(__DIR__) . '/Fixtures/RateLimitWorker.php',
            $root, 'test', $fingerprint, $barrier, $ready, '1']);
        $worker->run();
        self::assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
        self::assertSame('denied', trim($worker->getOutput()));
        $blocked = $this->project->path('blocked-root');
        file_put_contents($blocked, 'file');
        $this->expectException(RateLimitException::class);
        (new FileRateLimitStore($blocked, 'test'))->consume($fingerprint, 1, 60, new DateTimeImmutable('@1000'));
    }

    public function testExpiredWindowAndInvalidRecordLeaveNoFreshPermit(): void
    {
        $root = $this->project->path('RateLimits');
        $store = new FileRateLimitStore($root, 'test');
        $fingerprint = hash('sha256', 'expiry');
        self::assertTrue($store->consume($fingerprint, 1, 60, new DateTimeImmutable('@1000'))->allowed());
        self::assertTrue($store->consume($fingerprint, 1, 60, new DateTimeImmutable('@1059'))->denied());
        self::assertTrue($store->consume($fingerprint, 1, 60, new DateTimeImmutable('@1060'))->allowed());
        $record = $root . '/' . hash('sha256', 'test') . '/' . $fingerprint . '.json';
        file_put_contents($record, '{"version":1,"count":-1,"limit":1,"window_seconds":60,"resets_at":1060}');
        $this->expectException(RateLimitException::class);
        $store->consume($fingerprint, 1, 60, new DateTimeImmutable('@2000'));
    }

    public function testConcurrentProcessesPermitExactlyOne(): void
    {
        $root = $this->project->path('RateLimits');
        $barrier = $this->project->path('go');
        $worker = dirname(__DIR__) . '/Fixtures/RateLimitWorker.php';
        $fingerprint = hash('sha256', 'coordinated-entry');
        $workers = [];
        for ($i = 0; $i < 2; ++$i) {
            $ready = $this->project->path('ready-' . $i);
            $process = new Process([PHP_BINARY, $worker, $root, 'test', $fingerprint, $barrier, $ready, '1']);
            $process->start();
            $workers[] = [$process, $ready];
        }
        $deadline = microtime(true) + 10;
        foreach ($workers as [, $ready]) {
            while (!file_exists($ready) && microtime(true) < $deadline) usleep(1000);
            self::assertFileExists($ready);
        }
        file_put_contents($barrier, 'go');
        $outcomes = [];
        foreach ($workers as [$process]) {
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $outcomes[] = trim($process->getOutput());
        }
        sort($outcomes);
        self::assertSame(['allowed', 'denied'], $outcomes);
    }

    public function testFivePermitsAcrossEightCoordinatedProcesses(): void
    {
        $root = $this->project->path('RateLimits');
        $barrier = $this->project->path('go');
        $worker = dirname(__DIR__) . '/Fixtures/RateLimitWorker.php';
        $fingerprint = hash('sha256', 'multi-entry');
        $workers = [];
        for ($i = 0; $i < 8; ++$i) {
            $ready = $this->project->path('ready-' . $i);
            $process = new Process([PHP_BINARY, $worker, $root, 'test', $fingerprint, $barrier, $ready, '5']);
            $process->start();
            $workers[] = [$process, $ready];
        }
        $deadline = microtime(true) + 10;
        foreach ($workers as [, $ready]) {
            while (!file_exists($ready) && microtime(true) < $deadline) usleep(1000);
            self::assertFileExists($ready);
        }
        file_put_contents($barrier, 'go');
        $outcomes = [];
        foreach ($workers as [$process]) {
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $outcomes[] = trim($process->getOutput());
        }
        self::assertSame(5, count(array_filter($outcomes, static fn (string $value): bool => $value === 'allowed')));
        self::assertSame(3, count(array_filter($outcomes, static fn (string $value): bool => $value === 'denied')));
    }
}
