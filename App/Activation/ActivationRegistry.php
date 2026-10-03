<?php

declare(strict_types=1);

namespace App\Activation;

use App\Foundation\Application;
use App\Kits\KitDescriptor;
use App\Kits\KitDiscovery;
use App\Kits\KitException;
use App\Kits\KitFiles;
use App\Packages\PackageDescriptor;
use App\Packages\PackageDiscovery;
use App\Packages\PackageException;
use App\Packages\PackageGraph;

/**
 * Application-scoped, read-only activation and dependency view.
 *
 * Metadata comes from physical Package/Kit definitions; durable enablement
 * and ownership come from ActivationStore. Inspection never loads an entry
 * class. Only enabled valid Packages are eligible for runtime boot; Kits are
 * composition metadata and never appear in the Package boot order.
 */
final class ActivationRegistry
{
    public function __construct(private Application $app, private ActivationStore $store)
    {
    }

    /** @return array<string,PackageDescriptor> */
    public function packages(): array
    {
        try {
            $descriptors = (new PackageDiscovery($this->app->basePath('Project/Packages')))
                ->scan($this->store->packages());
            $graph = new PackageGraph($descriptors);
            foreach ($graph->descriptors() as $name => $descriptor) {
                $errors = $descriptor->errors();
                foreach ($graph->dependenciesOf($name) as $dependency) {
                    if (!isset($descriptors[$dependency])) {
                        $errors[] = 'Required Package ' . $dependency . ' is missing.';
                    }
                }
                if ($graph->isCyclic($name)) {
                    $path = $graph->cyclePathFor($name);
                    if (count($path) > 13) {
                        $path = [...array_slice($path, 0, 11), '…', $name];
                    }
                    $errors[] = $path === [] ? 'Package dependency cycle detected.'
                        : 'Package dependency cycle detected: ' . implode(' → ', $path) . '.';
                }
                if ($errors !== []) {
                    $descriptors[$name] = $descriptor->withStatus('broken', $errors);
                }
            }
            $resolved = [];
            $resolve = static function (string $name) use (&$resolve, &$descriptors, &$resolved, $graph): void {
                if (isset($resolved[$name])) { return; }
                $resolved[$name] = true;
                $descriptor = $descriptors[$name];
                if ($descriptor->status() !== 'enabled') { return; }
                foreach ($graph->dependenciesOf($name) as $dependency) {
                    if (!isset($descriptors[$dependency])) { continue; }
                    $resolve($dependency);
                    if ($descriptors[$dependency]->status() !== 'enabled') {
                        $descriptors[$name] = $descriptor->withStatus('broken',
                            [...$descriptor->errors(), 'Required Package ' . $dependency . ' is not enabled.']);
                        return;
                    }
                }
            };
            foreach (array_keys($descriptors) as $name) { $resolve($name); }
            return $descriptors;
        } catch (PackageException $exception) {
            throw new ActivationException('Package activation metadata is invalid.', 0, $exception);
        }
    }

    /** @return array<string,KitDescriptor> */
    public function kits(): array
    {
        try {
            $descriptors = (new KitDiscovery($this->app->basePath('Project/Kits')))
                ->scan($this->store->kits());
            $packages = null;
            foreach ($descriptors as $name => $descriptor) {
                $errors = $descriptor->errors();
                $record = $descriptor->record();
                if ($descriptor->sourceKind() === 'local' && $errors === [] && $record !== null) {
                    try {
                        $actual = KitFiles::fingerprints($descriptor->path());
                        $owned = $record['definition'];
                        foreach ($owned as $path => $hash) {
                            if (($actual[$path] ?? null) !== $hash) {
                                $errors[] = 'Modified or missing Kit definition file: ' . $path . '.';
                            }
                        }
                        foreach (array_keys($actual) as $path) {
                            if (!array_key_exists($path, $owned)) {
                                $errors[] = 'Untracked Kit definition file: ' . $path . '.';
                            }
                        }
                    } catch (KitException) {
                        $errors[] = 'Kit definition files are unsafe or unreadable.';
                    }
                }
                if (($record['enabled'] ?? false) === true && $errors === []) {
                    // Published files are ordinary Project code after Kit
                    // enablement, but their hashes remain lifecycle ownership.
                    foreach ($record['published'] as $path => $item) {
                        try {
                            $actual = KitFiles::fingerprint($this->app->basePath(), $name, $path);
                        } catch (KitException) {
                            $errors[] = 'Kit-owned application file is unsafe: ' . $path . '.';
                            continue;
                        }
                        if ($actual !== $item['hash']) {
                            $errors[] = 'Kit-owned application file is modified or missing: ' . $path . '.';
                        }
                    }
                    if ($descriptor->requires() !== []) {
                        $packages ??= $this->packages();
                        foreach ($descriptor->requires() as $package) {
                            if (($packages[$package] ?? null)?->status() !== 'enabled') {
                                $errors[] = 'Required Package ' . $package . ' is not enabled.';
                            }
                        }
                    }
                }
                if ($errors !== []) {
                    $descriptors[$name] = $descriptor->withStatus('broken', $errors);
                }
            }
            return $descriptors;
        } catch (KitException $exception) {
            throw new ActivationException('Kit activation metadata is invalid.', 0, $exception);
        }
    }

    public function package(string $name): ?PackageDescriptor
    {
        return $this->packages()[$name] ?? null;
    }

    public function kit(string $name): ?KitDescriptor
    {
        return $this->kits()[$name] ?? null;
    }

    public function status(string $kind, string $name): ?string
    {
        return match ($kind) {
            'package' => $this->package($name)?->status(),
            'kit' => $this->kit($name)?->status(),
            default => throw new ActivationException('Activation entity type is invalid.'),
        };
    }

    /** @return list<array{kind:string,name:string}> */
    public function dependencies(string $kind, string $name): array
    {
        if ($kind === 'package') {
            $descriptor = $this->package($name);
            if ($descriptor === null) { return []; }
            return array_map(static fn (string $dependency): array =>
                ['kind' => 'package', 'name' => $dependency], $descriptor->dependencies());
        }
        if ($kind === 'kit') {
            $descriptor = $this->kit($name);
            if ($descriptor === null) { return []; }
            // Recorded requirements remain protective when a Kit definition
            // later breaks or disappears. Manifest declarations are current.
            $names = array_unique([...$descriptor->requires(), ...($descriptor->record()['requires'] ?? [])]);
            sort($names, SORT_STRING);
            return array_map(static fn (string $dependency): array =>
                ['kind' => 'package', 'name' => $dependency], $names);
        }
        throw new ActivationException('Activation entity type is invalid.');
    }

    /** @return list<array{kind:string,name:string}> */
    public function dependents(string $kind, string $name): array
    {
        if ($kind === 'kit') { return []; }
        if ($kind !== 'package') {
            throw new ActivationException('Activation entity type is invalid.');
        }
        $dependents = [];
        $graph = new PackageGraph($this->packages());
        foreach ($graph->dependentsOf($name) as $dependent) {
            $dependents[] = ['kind' => 'package', 'name' => $dependent];
        }
        foreach ($this->kits() as $kit => $descriptor) {
            $requirements = array_unique([
                ...$descriptor->requires(), ...($descriptor->record()['requires'] ?? []),
            ]);
            if (in_array($name, $requirements, true)) {
                $dependents[] = ['kind' => 'kit', 'name' => $kit];
            }
        }
        usort($dependents, static fn (array $a, array $b): int =>
            [$a['kind'], $a['name']] <=> [$b['kind'], $b['name']]);
        return $dependents;
    }

    /** @return list<PackageDescriptor> Deterministic valid enabled Package order. */
    public function bootablePackages(): array
    {
        return (new PackageGraph($this->packages()))->activationOrder();
    }

    public function fingerprint(): string
    {
        return $this->store->currentFingerprint();
    }

    /** @return list<string> */
    public function legacyWarnings(): array
    {
        return $this->store->legacyWarnings();
    }

    /** @return array{exists:bool,writable:bool} */
    public function storageHealth(): array
    {
        return $this->store->storageHealth();
    }

    /**
     * Portable read-only summary for Doctor and future tools. Every call is a
     * fresh snapshot; no mutable registry array or source label is exposed.
     *
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        $packages = [];
        foreach ($this->packages() as $descriptor) {
            $packages[] = ['name' => $descriptor->name(), 'status' => $descriptor->status(),
                'installed' => is_dir($descriptor->path()),
                'registered' => $descriptor->record() !== null,
                'source_kind' => $descriptor->record()['source_kind'] ?? 'unmanaged',
                'enabled' => ($descriptor->record()['enabled'] ?? false) === true,
                'dependencies' => array_map(static fn (string $name): array =>
                    ['kind' => 'package', 'name' => $name], $descriptor->dependencies()),
                'errors' => $descriptor->errors()];
        }
        $kits = [];
        foreach ($this->kits() as $descriptor) {
            $requirements = array_unique([
                ...$descriptor->requires(), ...($descriptor->record()['requires'] ?? []),
            ]);
            sort($requirements, SORT_STRING);
            $kits[] = ['name' => $descriptor->name(), 'status' => $descriptor->status(),
                'installed' => is_dir($descriptor->path()),
                'registered' => $descriptor->record() !== null,
                'source_kind' => $descriptor->record()['source_kind'] ?? 'unmanaged',
                'enabled' => ($descriptor->record()['enabled'] ?? false) === true,
                'dependencies' => array_map(static fn (string $name): array =>
                    ['kind' => 'package', 'name' => $name], $requirements),
                'errors' => $descriptor->errors()];
        }
        return ['fingerprint' => $this->fingerprint(), 'packages' => $packages,
            'kits' => $kits, 'legacy_warnings' => $this->legacyWarnings()];
    }
}
