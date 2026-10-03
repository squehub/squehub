<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Activation\ActivationRegistry;
use App\Activation\ActivationStore;
use App\Foundation\Application;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Typed dependencies expose one static view without treating Kits as runtime providers. */
final class ActivationRegistryTest extends TestCase
{
    public function testTypedDependenciesAndBootOrderPreservePackageKitBoundary(): void
    {
        $project = new TemporaryProject();
        try {
            $this->package($project, 'Payments');
            $this->package($project, 'Commerce', ['Payments']);
            $this->kit($project, 'Commerce', ['Payments']);
            $store = new ActivationStore($project->path());
            $store->writeCombined([
                'Payments' => self::packageRecord(true),
                'Commerce' => self::packageRecord(true),
            ], ['Commerce' => self::kitRecord(true, ['Payments'])]);
            $registry = new ActivationRegistry(new Application($project->path()), $store);

            self::assertSame('enabled', $registry->status('package', 'Commerce'));
            self::assertSame('enabled', $registry->status('kit', 'Commerce'));
            self::assertSame(['Payments', 'Commerce'], array_map(
                static fn ($descriptor): string => $descriptor->name(), $registry->bootablePackages()));
            self::assertSame([['kind' => 'package', 'name' => 'Payments']],
                $registry->dependencies('package', 'Commerce'));
            self::assertSame([['kind' => 'package', 'name' => 'Payments']],
                $registry->dependencies('kit', 'Commerce'));
            self::assertSame([
                ['kind' => 'kit', 'name' => 'Commerce'],
                ['kind' => 'package', 'name' => 'Commerce'],
            ], $registry->dependents('package', 'Payments'));
            self::assertSame([], $registry->dependents('kit', 'Commerce'));
            self::assertCount(2, $registry->snapshot()['packages']);
            self::assertCount(1, $registry->snapshot()['kits']);
        } finally {
            $project->remove();
        }
    }

    public function testDisabledKitStillProtectsDependencyWhileEnabledKitReportsBrokenComposition(): void
    {
        $project = new TemporaryProject();
        try {
            $this->package($project, 'Payments');
            $this->kit($project, 'Shop', ['Payments']);
            $store = new ActivationStore($project->path());
            $store->writeCombined(['Payments' => self::packageRecord(false)],
                ['Shop' => self::kitRecord(false, ['Payments'])]);
            $registry = new ActivationRegistry(new Application($project->path()), $store);
            self::assertSame('disabled', $registry->status('kit', 'Shop'));
            self::assertSame([['kind' => 'kit', 'name' => 'Shop']],
                $registry->dependents('package', 'Payments'));
            $kits = $store->kits();
            $kits['Shop']['enabled'] = true;
            $store->writeKits($kits);
            self::assertSame('broken', $registry->status('kit', 'Shop'));
            self::assertSame([], $registry->bootablePackages());
        } finally {
            $project->remove();
        }
    }

    /** @param list<string> $requires */
    private function package(TemporaryProject $project, string $name, array $requires = []): void
    {
        $project->write('Project/Packages/' . $name . '/' . $name . '.php',
            '<?php namespace Packages\\' . $name . '; final class ' . $name
                . ' extends \\App\\Plugins\\ServiceProvider {}');
        $project->write('Project/Packages/' . $name . '/composer.json', json_encode([
            'name' => 'example/' . strtolower($name),
            'extra' => ['squehub' => ['requires' => $requires]],
        ], JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $requires */
    private function kit(TemporaryProject $project, string $name, array $requires): void
    {
        $project->write('Project/Kits/' . $name . '/kit.json', json_encode([
            'format' => 1, 'name' => $name, 'version' => '1.0.0',
            'requires' => $requires,
        ], JSON_THROW_ON_ERROR));
        $project->write('Project/Kits/' . $name . '/' . $name . '.php',
            '<?php namespace Project\\Kits\\' . $name . '; final class ' . $name
                . ' extends \\App\\Plugins\\Kit {}');
    }

    /** @return array<string,mixed> */
    private static function packageRecord(bool $enabled): array
    {
        return ['enabled' => $enabled, 'source_kind' => 'manual', 'source' => 'manual',
            'files' => []];
    }

    /** @param list<string> $requires @return array<string,mixed> */
    private static function kitRecord(bool $enabled, array $requires): array
    {
        return ['enabled' => $enabled, 'source_kind' => 'manual', 'source' => 'manual',
            'definition' => [], 'published' => [], 'requires' => $requires];
    }
}
