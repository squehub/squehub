<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Activation\ActivationRegistry;
use App\Activation\ActivationStore;
use App\Foundation\Application;
use App\Health\CoreHealthChecks;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Qualifies Application isolation, static inspection, and Package-only boot. */
final class ActivationRegistryIntegrationTest extends TestCase
{
    public function testSmallApplicationBootsWithoutCreatingActivationMetadata(): void
    {
        $project = new TemporaryProject();
        try {
            $app = new Application($project->path());
            $registry = $app->container()->make(ActivationRegistry::class);
            self::assertSame([], $registry->packages());
            self::assertSame([], $registry->kits());
            self::assertSame([], $registry->bootablePackages());
            $app->bootstrap();
            $checks = new CoreHealthChecks($app);
            self::assertSame('pass', $checks->packages()->status());
            self::assertSame('pass', $checks->kits()->status());
            self::assertFileDoesNotExist($project->path('Project/Activation.json'));
        } finally {
            $project->remove();
        }
    }

    public function testTypedSameNameEntitiesAndApplicationsRemainIsolated(): void
    {
        $first = new TemporaryProject();
        $second = new TemporaryProject();
        $packageMarker = $first->path('package-entry-executed.txt');
        $kitMarker = $first->path('kit-entry-executed.txt');
        try {
            $first->write('Project/Packages/ActivationCommerce13L/ActivationCommerce13L.php',
                '<?php namespace Packages\\ActivationCommerce13L; '
                . 'file_put_contents(' . var_export($packageMarker, true) . ', "loaded"); '
                . 'final class ActivationCommerce13L extends \\App\\Plugins\\ServiceProvider {}');
            $first->write('Project/Kits/ActivationCommerce13L/ActivationCommerce13L.php',
                '<?php namespace Project\\Kits\\ActivationCommerce13L; '
                . 'file_put_contents(' . var_export($kitMarker, true) . ', "loaded"); '
                . 'final class ActivationCommerce13L extends \\App\\Plugins\\Kit {}');
            $first->write('Project/Kits/ActivationCommerce13L/kit.json', json_encode([
                'format' => 1, 'name' => 'ActivationCommerce13L', 'version' => '1.0.0',
                'requires' => ['ActivationCommerce13L'],
            ], JSON_THROW_ON_ERROR));
            (new ActivationStore($first->path()))->writeCombined(
                ['ActivationCommerce13L' => self::packageRecord(true)],
                ['ActivationCommerce13L' => self::kitRecord(true, ['ActivationCommerce13L'])],
            );
            $appA = new Application($first->path());
            $registryA = $appA->container()->make(ActivationRegistry::class);
            self::assertSame('enabled', $registryA->status('package', 'ActivationCommerce13L'));
            self::assertSame('enabled', $registryA->status('kit', 'ActivationCommerce13L'));
            self::assertSame([['kind' => 'package', 'name' => 'ActivationCommerce13L']],
                $registryA->dependencies('kit', 'ActivationCommerce13L'));
            self::assertSame([['kind' => 'kit', 'name' => 'ActivationCommerce13L']],
                $registryA->dependents('package', 'ActivationCommerce13L'));
            self::assertSame(['ActivationCommerce13L'], array_map(static fn ($descriptor): string =>
                $descriptor->name(), $registryA->bootablePackages()));
            self::assertFileDoesNotExist($packageMarker);
            self::assertFileDoesNotExist($kitMarker);

            $second->write('Project/Packages/Blog/Blog.php',
                '<?php namespace Packages\\Blog; final class Blog extends \\App\\Plugins\\ServiceProvider {}');
            $appB = new Application($second->path());
            $registryB = $appB->container()->make(ActivationRegistry::class);
            self::assertSame('disabled', $registryB->status('package', 'Blog'));
            (new ActivationStore($second->path()))->writePackages(['Blog' => self::packageRecord(true)]);
            self::assertSame('enabled', $registryB->status('package', 'Blog'));
            self::assertNull($registryB->package('ActivationCommerce13L'));
            self::assertSame('enabled', $registryA->status('package', 'ActivationCommerce13L'));
            self::assertNull($registryA->package('Blog'));
            self::assertFileDoesNotExist($kitMarker);

            $appA->bootstrap();
            self::assertFileExists($packageMarker);
            self::assertFileDoesNotExist($kitMarker,
                'A Kit sharing a Package name must still stay outside runtime boot.');
        } finally {
            $first->remove();
            $second->remove();
        }
    }

    public function testDoctorReportsAConcreteBrokenKitRequirementWithoutExecutingKitPhp(): void
    {
        $project = new TemporaryProject();
        $marker = $project->path('kit-entry-executed.txt');
        try {
            $project->write('Project/Kits/Commerce13L/Commerce13L.php',
                '<?php namespace Project\\Kits\\Commerce13L; '
                . 'file_put_contents(' . var_export($marker, true) . ', "loaded"); '
                . 'final class Commerce13L extends \\App\\Plugins\\Kit {}');
            $project->write('Project/Kits/Commerce13L/kit.json', json_encode([
                'format' => 1, 'name' => 'Commerce13L', 'version' => '1.0.0',
                'requires' => ['Payments13L'],
            ], JSON_THROW_ON_ERROR));
            (new ActivationStore($project->path()))->writeKits([
                'Commerce13L' => self::kitRecord(true, ['Payments13L']),
            ]);
            $result = (new CoreHealthChecks(new Application($project->path())))->kits();
            self::assertSame('fail', $result->status());
            self::assertStringContainsString('Kit Commerce13L requires enabled Package Payments13L.',
                $result->summary());
            self::assertFileDoesNotExist($marker);
        } finally {
            $project->remove();
        }
    }

    public function testThirtyPackagesAndTwelveKitsHaveDeterministicDependenciesAndPackageOnlyBoot(): void
    {
        $project = new TemporaryProject();
        $trace = $project->path('package-register-trace.txt');
        $kitMarker = $project->path('kit-entry-executed.txt');
        try {
            $packages = [];
            for ($number = 1; $number <= 30; ++$number) {
                $name = sprintf('ScalePkg%02d', $number);
                $requires = $number > 1 && $number <= 28
                    ? [sprintf('ScalePkg%02d', $number - 1)] : [];
                $project->write('Project/Packages/' . $name . '/' . $name . '.php',
                    '<?php namespace Packages\\' . $name . '; final class ' . $name
                    . ' extends \\App\\Plugins\\ServiceProvider {'
                    . ' public function register(): void { file_put_contents('
                    . var_export($trace, true) . ', ' . var_export($name . "\n", true)
                    . ', FILE_APPEND); } }');
                $project->write('Project/Packages/' . $name . '/composer.json', json_encode([
                    'name' => 'test/' . strtolower($name), 'version' => '1.0.0',
                    'extra' => ['squehub' => ['requires' => $requires]],
                ], JSON_THROW_ON_ERROR));
                $packages[$name] = self::packageRecord($number <= 28);
            }
            $kits = [];
            for ($number = 1; $number <= 12; ++$number) {
                $name = sprintf('ScaleKit%02d', $number);
                $requires = ['ScalePkg01'];
                if ($number <= 5) { $requires[] = 'ScalePkg02'; }
                if ($number <= 3) { $requires[] = 'ScalePkg03'; }
                $project->write('Project/Kits/' . $name . '/' . $name . '.php',
                    '<?php namespace Project\\Kits\\' . $name . '; '
                    . 'file_put_contents(' . var_export($kitMarker, true) . ', "loaded"); '
                    . 'final class ' . $name . ' extends \\App\\Plugins\\Kit {}');
                $project->write('Project/Kits/' . $name . '/kit.json', json_encode([
                    'format' => 1, 'name' => $name, 'version' => '1.0.0',
                    'requires' => $requires,
                ], JSON_THROW_ON_ERROR));
                $kits[$name] = self::kitRecord($number <= 10, $requires);
            }
            (new ActivationStore($project->path()))->writeCombined($packages, $kits);
            $app = new Application($project->path());
            $registry = $app->container()->make(ActivationRegistry::class);
            $bytes = file_get_contents($project->path('Project/Activation.json'));
            self::assertCount(30, $registry->packages());
            self::assertCount(12, $registry->kits());
            self::assertSame(12, count($registry->dependents('package', 'ScalePkg01')) - 1,
                'The first Package has one Package dependent and twelve enabled or disabled Kit dependents.');
            self::assertCount(5, array_filter($registry->dependents('package', 'ScalePkg02'),
                static fn (array $dependent): bool => $dependent['kind'] === 'kit'));
            self::assertCount(3, array_filter($registry->dependents('package', 'ScalePkg03'),
                static fn (array $dependent): bool => $dependent['kind'] === 'kit'));
            $bootable = array_map(static fn ($descriptor): string =>
                $descriptor->name(), $registry->bootablePackages());
            self::assertSame(array_keys(array_slice($packages, 0, 28, true)), $bootable);
            self::assertSame($bytes, file_get_contents($project->path('Project/Activation.json')),
                'Static inspection must not rewrite state.');
            self::assertFileDoesNotExist($trace);
            self::assertFileDoesNotExist($kitMarker);
            $app->bootstrap();
            self::assertSame($bootable, file($trace, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
            self::assertFileDoesNotExist($kitMarker);
            self::assertSame($bytes, file_get_contents($project->path('Project/Activation.json')),
                'Normal boot must not rewrite activation state.');
        } finally {
            $project->remove();
        }
    }

    /** @return array{enabled:bool,source_kind:string,source:string,files:array<mixed>} */
    private static function packageRecord(bool $enabled): array
    {
        return ['enabled' => $enabled, 'source_kind' => 'manual',
            'source' => 'manual', 'files' => []];
    }

    /** @param list<string> $requires
     *  @return array{enabled:bool,source_kind:string,source:string,definition:array<mixed>,published:array<mixed>,requires:list<string>}
     */
    private static function kitRecord(bool $enabled, array $requires): array
    {
        return ['enabled' => $enabled, 'source_kind' => 'manual', 'source' => 'manual',
            'definition' => [], 'published' => [], 'requires' => $requires];
    }
}
