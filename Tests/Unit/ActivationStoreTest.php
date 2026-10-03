<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Activation\ActivationException;
use App\Activation\ActivationLockException;
use App\Activation\ActivationStore;
use App\Kits\KitStateStore;
use App\Packages\PackageStateStore;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Canonical activation bytes and legacy import stay inert until a lifecycle write. */
final class ActivationStoreTest extends TestCase
{
    public function testEmptyReadIsWriteFreeAndFingerprintIsDeterministic(): void
    {
        $project = new TemporaryProject();
        try {
            $store = new ActivationStore($project->path());
            self::assertSame(['packages' => [], 'kits' => []], $store->read());
            self::assertSame($store->currentFingerprint(), $store->currentFingerprint());
            self::assertFileDoesNotExist($project->path('Project/Activation.json'));
            self::assertFileDoesNotExist($project->path('Project/Activation.lock'));
        } finally {
            $project->remove();
        }
    }

    public function testLegacyLowercaseProjectCanBeReadButCannotReceiveCanonicalWrites(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('project/routes/web.php', '<?php');
            $project->write('project/Packages/State.json', json_encode([
                'version' => 1, 'packages' => ['Orders' => self::packageRecord(true)],
            ], JSON_THROW_ON_ERROR));
            $store = new ActivationStore($project->path());
            // The lowercase root remains available to legacy routes and views,
            // but it must not activate Packages only on case-insensitive hosts.
            self::assertSame(['packages' => [], 'kits' => []], $store->read());
            self::assertSame(['exists' => false, 'writable' => false], $store->storageHealth());
            self::assertFileDoesNotExist($project->path('Project/Activation.lock'));

            try {
                $store->writePackages(['Orders' => self::packageRecord(true)]);
                self::fail('A registry write accepted a conflicting Project directory casing.');
            } catch (ActivationException $exception) {
                self::assertSame('Project directory conflicts by casing.', $exception->getMessage());
                self::assertNotInstanceOf(ActivationLockException::class, $exception);
            }
            self::assertFileDoesNotExist($project->path('Project/Activation.json'));
            self::assertFileDoesNotExist($project->path('project/Activation.json'));
            self::assertFileDoesNotExist($project->path('project/Activation.lock'));
        } finally {
            $project->remove();
        }
    }

    public function testCanonicalProjectOwnsRegistryAndLock(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Routes/Web.php', '<?php');
            $store = new ActivationStore($project->path());
            self::assertSame(['packages' => [], 'kits' => []], $store->read());
            self::assertTrue($store->storageHealth()['writable']);

            $store->writePackages(['Orders' => self::packageRecord(true)]);

            self::assertArrayHasKey('Orders', $store->packages());
            self::assertFileExists($project->path('Project/Activation.json'));
            self::assertFileExists($project->path('Project/Activation.lock'));
            self::assertContains('Project', scandir($project->path()));
        } finally {
            $project->remove();
        }
    }

    public function testBlockedLockPathHasDistinctFailureType(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Routes/Web.php', '<?php');
            self::assertTrue(mkdir($project->path('Project/Activation.lock')));
            $store = new ActivationStore($project->path());
            try {
                $store->withLock(static function (): void {
                    self::fail('A directory was accepted as the activation lock.');
                });
                self::fail('A blocked activation lock path was accepted.');
            } catch (ActivationLockException $exception) {
                self::assertSame('Activation registry lock path is unsafe.', $exception->getMessage());
                self::assertInstanceOf(ActivationException::class, $exception->getPrevious());
            }
            self::assertFileDoesNotExist($project->path('Project/Activation.json'));
        } finally {
            $project->remove();
        }
    }

    public function testSingleLowercaseProjectRejectsActivationMetadata(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('project/Routes/Web.php', '<?php');
            $store = new ActivationStore($project->path());
            foreach (['Activation.json', 'Activation.lock', 'activation.json', 'activation.lock'] as $filename) {
                $project->write('project/' . $filename, '{}');
                try {
                    $store->read();
                    self::fail('Activation metadata under a lowercase project root was accepted.');
                } catch (ActivationException $exception) {
                    self::assertSame('Activation registry requires canonical Project directory.',
                        $exception->getMessage());
                }
                self::assertFalse($store->storageHealth()['writable']);
                unlink($project->path('project/' . $filename));
            }
        } finally {
            $project->remove();
        }
    }

    public function testDistinctProjectRootsConflictBeforeRegistryCreation(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Routes/Web.php', '<?php');
            $project->write('project/routes/web.php', '<?php');
            $entries = scandir($project->path());
            if ($entries === false || !in_array('Project', $entries, true)
                || !in_array('project', $entries, true)) {
                self::markTestSkipped('This filesystem cannot represent distinct Project/ and project/ roots.');
            }

            $store = new ActivationStore($project->path());
            foreach ([static fn (): array => $store->read(),
                static function () use ($store): void { $store->writeCombined([], []); }] as $operation) {
                try {
                    $operation();
                    self::fail('Distinct case-colliding project roots were accepted.');
                } catch (ActivationException $exception) {
                    self::assertSame('Project directory conflicts by casing.', $exception->getMessage());
                }
            }
            self::assertFileDoesNotExist($project->path('Project/Activation.json'));
            self::assertFileDoesNotExist($project->path('Project/Activation.lock'));
        } finally {
            $project->remove();
        }
    }

    public function testLegacyBothDomainsAreImportedOnlyOnExplicitWrite(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Packages/State.json', json_encode([
                'version' => 1, 'packages' => ['Orders' => self::packageRecord(false)],
            ], JSON_THROW_ON_ERROR));
            $project->write('Project/Kits/State.json', json_encode([
                'version' => 1, 'kits' => ['Shop' => self::kitRecord(false)],
            ], JSON_THROW_ON_ERROR));
            $store = new ActivationStore($project->path());
            self::assertArrayHasKey('Orders', $store->packages());
            self::assertArrayHasKey('Shop', $store->kits());
            self::assertFileDoesNotExist($project->path('Project/Activation.json'));

            $packages = $store->packages();
            $packages['Orders']['enabled'] = true;
            $store->writePackages($packages);
            self::assertFileExists($project->path('Project/Activation.json'));
            $json = json_decode((string) file_get_contents($project->path('Project/Activation.json')),
                true, 64, JSON_THROW_ON_ERROR);
            self::assertSame(1, $json['format']);
            self::assertTrue($json['packages']['Orders']['enabled']);
            self::assertFalse($json['kits']['Shop']['enabled']);
            self::assertFileExists($project->path('Project/Packages/State.json'));
            self::assertFileExists($project->path('Project/Kits/State.json'));
            self::assertCount(1, $store->legacyWarnings());
        } finally {
            $project->remove();
        }
    }

    public function testCanonicalStateIsAuthoritativeWhenOldFilesDiverge(): void
    {
        $project = new TemporaryProject();
        try {
            $store = new ActivationStore($project->path());
            $store->writeCombined(['Orders' => self::packageRecord(true)],
                ['Shop' => self::kitRecord(true)]);
            $project->write('Project/Packages/State.json', json_encode([
                'version' => 1, 'packages' => ['Orders' => self::packageRecord(false)],
            ], JSON_THROW_ON_ERROR));
            self::assertTrue($store->packages()['Orders']['enabled']);
            self::assertTrue($store->kits()['Shop']['enabled']);
            self::assertSame(['Package legacy state differs from the activation registry.'],
                $store->legacyWarnings());
        } finally {
            $project->remove();
        }
    }

    public function testPackageAndKitAdaptersShareOneCanonicalFingerprint(): void
    {
        $project = new TemporaryProject();
        try {
            $store = new ActivationStore($project->path());
            $packages = new PackageStateStore($project->path('Project/Packages'), $store);
            $kits = new KitStateStore($project->path('Project/Kits'), $store);
            $before = $packages->currentFingerprint();
            self::assertSame($before, $kits->currentFingerprint());
            $kits->write(['Shop' => self::kitRecord(false)]);
            self::assertNotSame($before, $packages->currentFingerprint());
            self::assertSame($packages->currentFingerprint(), $kits->currentFingerprint());
            self::assertSame($store->fingerprintWithPackages(
                ['Orders' => self::packageRecord(true)]),
                $packages->fingerprintWith(['Orders' => self::packageRecord(true)]));
        } finally {
            $project->remove();
        }
    }

    public function testSerializationIsSortedAndRejectsCorruptUnsupportedOrUnknownData(): void
    {
        $project = new TemporaryProject();
        try {
            $store = new ActivationStore($project->path());
            $store->writeCombined([
                'Zulu' => self::packageRecord(false), 'Alpha' => self::packageRecord(true),
            ], ['Zulu' => self::kitRecord(false), 'Alpha' => self::kitRecord(true)]);
            $bytes = (string) file_get_contents($project->path('Project/Activation.json'));
            self::assertLessThan(strpos($bytes, '"Zulu"'), strpos($bytes, '"Alpha"'));
            self::assertSame($store->currentFingerprint(), $store->fingerprintWithState(
                ['Alpha' => self::packageRecord(true), 'Zulu' => self::packageRecord(false)],
                ['Alpha' => self::kitRecord(true), 'Zulu' => self::kitRecord(false)]));

            foreach (['{bad', '{"format":2,"packages":{},"kits":{}}',
                '{"format":1,"packages":{},"kits":{},"unknown":true}',
                '{"format":1,"packages":[],"kits":{}}',
                '{"format":1,"packages":{"Alpha":{"enabled":true,"source_kind":"manual",'
                    . '"source":"manual","files":[]}},"kits":{}}',
                '{"format":1,"packages":{"Alpha":{"enabled":true}},"kits":{}}'] as $invalid) {
                $project->write('Project/Activation.json', $invalid);
                try {
                    $store->read();
                    self::fail('Invalid registry data was accepted.');
                } catch (ActivationException) {
                    self::assertTrue(true);
                }
            }
        } finally {
            $project->remove();
        }
    }

    public function testFailedValidationPreservesPriorBytesAndNestedLockIsSafe(): void
    {
        $project = new TemporaryProject();
        try {
            $store = new ActivationStore($project->path());
            $store->writePackages(['Orders' => self::packageRecord(false)]);
            $before = (string) file_get_contents($project->path('Project/Activation.json'));
            try {
                $store->withLock(static fn () => $store->withLock(
                    static fn () => $store->writeKits(['Shop' => self::kitRecord(false)])));
            } catch (\Throwable $exception) {
                self::fail('Reentrant registry lock failed: ' . $exception->getMessage());
            }
            $current = (string) file_get_contents($project->path('Project/Activation.json'));
            try {
                $store->writePackages(['Bad' => ['enabled' => 'yes']]);
                self::fail('Invalid record was accepted.');
            } catch (ActivationException) {
                self::assertSame($current,
                    file_get_contents($project->path('Project/Activation.json')));
            }
            self::assertNotSame($before, $current);

            $project->write('Project/Activation.json', '{corrupt');
            try {
                $store->writeCombined([], []);
                self::fail('A mutation replaced corrupt canonical state.');
            } catch (ActivationException) {
                self::assertSame('{corrupt',
                    file_get_contents($project->path('Project/Activation.json')));
            }
        } finally {
            $project->remove();
        }
    }

    public function testLegacyCredentialBearingSourceBecomesOpaqueBeforePersistence(): void
    {
        $project = new TemporaryProject();
        try {
            $secret = 'SQUEHUB_ACTIVATION_SECRET_DO_NOT_LEAK';
            $record = self::packageRecord(false);
            $record['source_kind'] = 'git';
            $record['source'] = 'https://user:' . $secret . '@example.test/Orders.git';
            $project->write('Project/Packages/State.json', json_encode([
                'version' => 1, 'packages' => ['Orders' => $record],
            ], JSON_THROW_ON_ERROR));
            $store = new ActivationStore($project->path());
            self::assertStringNotContainsString($secret,
                json_encode($store->read(), JSON_THROW_ON_ERROR));
            $store->writePackages($store->packages());
            self::assertStringNotContainsString($secret,
                (string) file_get_contents($project->path('Project/Activation.json')));
            self::assertStringNotContainsString($secret,
                implode(' ', $store->legacyWarnings()));
        } finally {
            $project->remove();
        }
    }

    public function testTwoProcessesSerializePackageAndKitWritesThroughOneLock(): void
    {
        $project = new TemporaryProject();
        $held = $project->path('Held.marker');
        $started = $project->path('Started.marker');
        $acquired = $project->path('Acquired.marker');
        $release = $project->path('Release.marker');
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $holderCode = 'require ' . var_export($autoload, true) . '; '
            . '$store = new \\App\\Activation\\ActivationStore(' . var_export($project->path(), true) . '); '
            . '$store->withLock(function () use ($store): void { '
            . '$store->writeKits(["Shop" => ' . var_export(self::kitRecord(true), true) . ']); '
            . 'file_put_contents(' . var_export($held, true) . ', "held"); '
            . '$deadline = microtime(true) + 10; '
            . 'while (!file_exists(' . var_export($release, true) . ')) { '
            . 'if (microtime(true) > $deadline) { throw new RuntimeException("release timed out"); } '
            . 'usleep(10000); } });';
        $waiterCode = 'require ' . var_export($autoload, true) . '; '
            . 'file_put_contents(' . var_export($started, true) . ', "started"); '
            . '$store = new \\App\\Activation\\ActivationStore(' . var_export($project->path(), true) . '); '
            . '$store->withLock(function () use ($store): void { '
            . 'file_put_contents(' . var_export($acquired, true) . ', "acquired"); '
            . '$store->writePackages(["Orders" => '
            . var_export(self::packageRecord(true), true) . ']); });';
        $holder = new Process([PHP_BINARY, '-r', $holderCode]);
        $waiter = new Process([PHP_BINARY, '-r', $waiterCode]);
        try {
            $holder->start();
            self::awaitFile($held);
            $waiter->start();
            self::awaitFile($started);
            self::assertFileDoesNotExist($acquired,
                'A second process must not enter the registry mutation lock early.');
            file_put_contents($release, 'release');
            $holder->wait();
            $waiter->wait();
            self::assertSame(0, $holder->getExitCode(), $holder->getErrorOutput());
            self::assertSame(0, $waiter->getExitCode(), $waiter->getErrorOutput());
            self::assertFileExists($acquired);
            $state = (new ActivationStore($project->path()))->read();
            self::assertTrue($state['packages']['Orders']['enabled']);
            self::assertTrue($state['kits']['Shop']['enabled']);
        } finally {
            file_put_contents($release, 'release');
            if ($holder->isRunning()) { $holder->stop(1); }
            if ($waiter->isRunning()) { $waiter->stop(1); }
            $project->remove();
        }
    }

    private static function awaitFile(string $path): void
    {
        $deadline = microtime(true) + 5;
        while (!is_file($path)) {
            if (microtime(true) > $deadline) {
                self::fail('Process did not reach the synchronization barrier.');
            }
            usleep(10000);
        }
    }

    /** @return array<string,mixed> */
    private static function packageRecord(bool $enabled): array
    {
        return ['enabled' => $enabled, 'source_kind' => 'manual', 'source' => 'manual',
            'files' => []];
    }

    /** @return array<string,mixed> */
    private static function kitRecord(bool $enabled): array
    {
        return ['enabled' => $enabled, 'source_kind' => 'manual', 'source' => 'manual',
            'definition' => [], 'published' => [], 'requires' => []];
    }
}
