<?php

declare(strict_types=1);

namespace App\Packages;

use App\Activation\ActivationRegistry;
use App\Activation\ActivationStore;
use App\Activation\ActivationLockException;
use App\Activation\ActivationException;
use App\Changes\ChangeAction;
use App\Changes\ChangeApplyException;
use App\Changes\ChangePlan;
use App\Changes\ChangeResult;
use App\Contributions\ContributionOwner;
use App\Foundation\Application;
use App\Plugins\ServiceProvider;
use App\Support\PhysicalPath;
use Symfony\Component\Process\Process;
use Throwable;
use WeakMap;

/**
 * Application-owned Package discovery, activation, and bounded lifecycle.
 *
 * The Application-owned Activation Registry supplies durable state and the
 * static dependency view. A copied Package is discovered but never boots
 * until a reviewed lifecycle action explicitly enables it.
 */
final class PackageManager
{
    private PackageStateStore $state;
    private PackageDiscovery $discovery;

    /** @var array<string, ServiceProvider> */
    private array $providers = [];

    /** @var array<string, string> Safe runtime validation failures. */
    private array $runtimeErrors = [];

    /** @var list<PackageDescriptor>|null Frozen contribution set for this Application. */
    private ?array $activated = null;

    /** @var array<string, string> Source fingerprints taken before trusted verification executes a Package. */
    private array $verificationFingerprints = [];

    /** @var WeakMap<ChangePlan, array{operation:string,name:string,source:?string,files:array<string,string>,state:?string}> */
    private WeakMap $planInputs;

    public function __construct(private Application $app)
    {
        $directory = $this->directory();
        $this->state = new PackageStateStore($directory,
            $app->container()->make(ActivationStore::class));
        $this->discovery = new PackageDiscovery($directory);
        $this->planInputs = new WeakMap();
    }

    /** @return list<PackageDescriptor> */
    public function list(): array
    {
        return array_values($this->graph()->descriptors());
    }

    /** Fresh, static dependency inspection for this Application's Package root. */
    public function graph(): PackageGraph
    {
        return new PackageGraph($this->descriptors());
    }

    /** @return list<PackageDescriptor> Valid enabled Packages in dependency order. */
    public function active(): array
    {
        if ($this->activated !== null) { return $this->activated; }
        try {
            return $this->app->container()->make(ActivationRegistry::class)->bootablePackages();
        } catch (ActivationException $exception) {
            throw new PackageException('Package activation could not be inspected.', 0, $exception);
        }
    }

    /** @return array<string, PackageDescriptor> Exact registered namespaces in this Application. */
    public function viewNamespaces(): array
    {
        $registered = [];
        foreach ($this->active() as $descriptor) {
            $name = $descriptor->name();
            $owner = $this->app->contributions()->ownerOf('view_namespace', $name);
            if ($owner?->type === 'package' && $owner->name === $name) {
                $registered[$name] = $descriptor;
            }
        }
        return $registered;
    }

    public function viewNamespace(string $name): ?PackageDescriptor
    {
        return $this->viewNamespaces()[$name] ?? null;
    }

    public function isEnabled(string $name): bool
    {
        return ($this->descriptors()[$name] ?? null)?->status() === 'enabled';
    }

    /**
     * Read one Package's descriptor and last verified contribution metadata.
     * This path inspects files as data; it never includes Package PHP.
     *
     * @return array{descriptor:PackageDescriptor,required_by:list<string>,required_by_kits:list<string>,provenance:string,contributions:list<array<string,mixed>>}
     */
    public function inspect(string $name): array
    {
        PackageName::require($name);
        $graph = $this->graph();
        $descriptors = $graph->descriptors();
        $descriptor = $descriptors[$name] ?? null;
        if ($descriptor === null) {
            throw new PackageException('Package is not installed.');
        }
        $requiredBy = [];
        $requiredByKits = [];
        foreach ($this->registryDependents($name) as $edge) {
            if ($edge['kind'] === 'package') { $requiredBy[] = $edge['name']; }
            if ($edge['kind'] === 'kit') { $requiredByKits[] = $edge['name']; }
        }
        $snapshot = $descriptor->record()['contribution_snapshot'] ?? null;
        if (!is_array($snapshot)) {
            return ['descriptor' => $descriptor, 'required_by' => $requiredBy,
                'required_by_kits' => $requiredByKits,
                'provenance' => 'unavailable', 'contributions' => []];
        }
        $current = $descriptor->status() !== 'broken'
            && PackageSnapshot::isCurrent($snapshot, $descriptor->path());
        return [
            'descriptor' => $descriptor,
            'required_by' => $requiredBy,
            'required_by_kits' => $requiredByKits,
            'provenance' => $current ? 'current' : 'stale',
            'contributions' => $snapshot['items'],
        ];
    }

    /**
     * Persist observations only after an explicit trusted verification boot.
     * The snapshot changes no Package activation or source ownership state.
     */
    public function captureContributionSnapshot(string $name): void
    {
        PackageName::require($name);
        if (!$this->app->isBooted() || !$this->app->isPackageVerification()) {
            throw new PackageException('Package verification requires a trusted verification boot.');
        }
        $this->withActivationLock(function () use ($name): void {
            $descriptor = $this->descriptors()[$name] ?? null;
            if ($descriptor === null || $descriptor->status() !== 'enabled'
                || !in_array($name, array_map(static fn (PackageDescriptor $item): string => $item->name(),
                    $this->active()), true)) {
                throw new PackageException('Only an active Package can be verified.');
            }
            $records = $this->state->read();
            if (($records[$name]['enabled'] ?? false) !== true) {
                throw new PackageException('Package activation changed during verification.');
            }
            $snapshot = PackageSnapshot::capture($name, $descriptor->path(),
                $this->app->contributions()->byOwner(new ContributionOwner('package', $name)));
            if (!isset($this->verificationFingerprints[$name])
                || !hash_equals($this->verificationFingerprints[$name], $snapshot['source_fingerprint'])) {
                throw new PackageException('Package source changed during verification.');
            }
            $records[$name]['contribution_snapshot'] = $snapshot;
            $this->state->write($records);
        });
    }

    /**
     * Load only explicitly enabled entry classes, then register in dependency
     * order. Invalid classes remain visible as broken to management commands.
     */
    public function registerEnabled(): void
    {
        $graph = $this->graph();
        // Inspection commands report broken entries without executing them.
        // A normal Application must not silently boot without an enabled Package.
        foreach ($graph->descriptors() as $descriptor) {
            if (($descriptor->record()['enabled'] ?? false) === true
                && $descriptor->status() === 'broken') {
                throw new PackageException('An enabled Package is broken; inspect it with package:list.');
            }
        }
        $candidates = $graph->activationOrder();
        // Claim exact Package identities on the Application-owned provenance
        // registry before executing Package PHP. A collision fails this boot;
        // no directory or stale snapshot can activate a namespace instead.
        foreach ($candidates as $descriptor) {
            $name = $descriptor->name();
            try {
                $this->app->contributions()->record('view_namespace', $name,
                    'Project/Packages/' . $name . '/' . $name . '.php', [],
                    new ContributionOwner('package', $name));
            } catch (Throwable $exception) {
                throw new PackageException('Package View namespace registration failed.', 0, $exception);
            }
        }
        $activated = [];
        foreach ($candidates as $descriptor) {
            $name = $descriptor->name();
            $class = $descriptor->entryClass();
            if ($this->app->isPackageVerification()) {
                $this->verificationFingerprints[$name] = PackageSnapshot::sourceFingerprint($descriptor->path());
            }
            foreach ($graph->dependenciesOf($name) as $dependency) {
                if (!isset($this->providers[$dependency])) {
                    $this->runtimeErrors[$name] = 'Required Package could not be activated.';
                    throw new PackageException('Required Package could not be activated.');
                }
            }
            try {
                if ($class === null) {
                    $this->runtimeErrors[$name] = 'Package entry class is unavailable.';
                    throw new PackageException('Package entry class is unavailable.');
                }
                $entry = $descriptor->path() . '/' . $name . '.php';
                if (class_exists($class, false)) {
                    $loaded = (new \ReflectionClass($class))->getFileName();
                    if (!is_string($loaded) || !$this->samePath($loaded, $entry)) {
                        $this->runtimeErrors[$name] = 'Package entry class is already loaded from another location.';
                        throw new PackageException('Package entry class is already loaded from another location.');
                    }
                } else {
                    // A temporary or embedded Application has its own Package
                    // root; Composer's project autoload map cannot select it.
                    $this->withPackageConfig($name, static function () use ($entry): void {
                        require_once $entry;
                    });
                }
                if (!class_exists($class, false)
                    || !is_subclass_of($class, ServiceProvider::class)) {
                    throw new PackageException('Enabled Package entry class is incompatible.');
                }
                $provider = $this->withPackageConfig($name,
                    fn (): object => $this->app->container()->make($class));
                if (!$provider instanceof ServiceProvider) {
                    throw new PackageException('Enabled Package entry did not resolve as a ServiceProvider.');
                }
            } catch (Throwable $exception) {
                // Include/constructor code can run before it throws. The current
                // Application must fail rather than keep partial contributions.
                $this->runtimeErrors[$name] = 'Package entry could not be loaded.';
                throw new PackageException('Enabled Package entry could not be loaded.', 0, $exception);
            }
            try {
                $this->withPackageConfig($name, static function () use ($provider): void {
                    $provider->register();
                });
                $this->providers[$name] = $provider;
                $activated[] = $descriptor;
            } catch (Throwable $exception) {
                // A hook may have already changed mutable framework services.
                // Abort this Application rather than run with partial activation.
                $this->runtimeErrors[$name] = 'Package register hook failed.';
                throw new PackageException('Enabled Package registration failed.', 0, $exception);
            }
        }
        // Runtime resources use one activation set for the full Application.
        // Lifecycle state edits take effect in a new Application/process.
        $this->activated = $activated;
    }

    public function bootEnabled(): void
    {
        foreach ($this->active() as $descriptor) {
            $name = $descriptor->name();
            if (!isset($this->providers[$name])) { continue; }
            try {
                $this->withPackageConfig($name, function () use ($name): void {
                    $this->providers[$name]->boot();
                });
            } catch (Throwable $exception) {
                $this->runtimeErrors[$name] = 'Package boot hook failed.';
                throw new PackageException('Enabled Package boot failed.', 0, $exception);
            }
        }
    }

    /**
     * Legacy utility files define global functions and cannot be unloaded.
     * Load only the frozen enabled set before boot hooks and route files run.
     */
    public function loadEnabledUtilities(): void
    {
        foreach ($this->active() as $descriptor) {
            $root = $descriptor->path();
            foreach (['Utils', 'utils'] as $directoryName) {
                $directory = $root . '/' . $directoryName;
                if (!is_dir($directory)) { continue; }
                try {
                    PackageFiles::assertPhysical($directory);
                    $files = PackageFiles::fingerprints($directory);
                } catch (PackageException $exception) {
                    throw new PackageException('Enabled Package utilities are unsafe.', 0, $exception);
                }
                foreach (array_keys($files) as $relative) {
                    if (strtolower(pathinfo($relative, PATHINFO_EXTENSION)) === 'php') {
                        $this->withPackageConfig($descriptor->name(),
                            static function () use ($directory, $relative): void {
                                require_once $directory . '/' . $relative;
                            }, 'Project/Packages/' . $descriptor->name() . '/' . $directoryName . '/' . $relative);
                    }
                }
                break;
            }
        }
    }

    public function planEnable(string $name): ChangePlan
    {
        PackageName::require($name);
        $records = $this->state->read();
        $descriptors = $this->discovery->scan($records);
        if (!isset($descriptors[$name])) {
            throw new PackageException('Package is not installed.');
        }
        $graph = new PackageGraph($descriptors);
        $order = [];
        $conflicts = [];
        $visited = [];
        $visit = function (string $candidate) use (&$visit, &$order, &$conflicts, &$visited,
            $descriptors, $graph): void {
            if (isset($visited[$candidate])) { return; }
            $visited[$candidate] = true;
            $descriptor = $descriptors[$candidate] ?? null;
            if ($descriptor === null) {
                $conflicts[] = 'Required Package ' . $candidate . ' is missing.';
                return;
            }
            if ($graph->isCyclic($candidate)) {
                $conflicts[] = self::cycleError($graph, $candidate);
                return;
            }
            foreach ($descriptor->errors() as $error) {
                $conflicts[] = $candidate . ': ' . $error;
            }
            foreach ($graph->dependenciesOf($candidate) as $dependency) { $visit($dependency); }
            $order[] = $candidate;
        };
        $visit($name);

        // Dependency actions are reviewable individually, but one registry
        // write commits every enabled flag. A partial dependency activation
        // cannot be mistaken for a successful Package enable.
        $transitions = [];
        foreach ($order as $candidate) {
            if (($records[$candidate]['enabled'] ?? false) === true) { continue; }
            $transitions[$candidate] = $descriptors[$candidate]->status();
            $record = $records[$candidate] ?? [
                'enabled' => false, 'source_kind' => 'manual', 'source' => 'manual', 'files' => (object) [],
            ];
            $record['enabled'] = true;
            $records[$candidate] = $record;
        }
        $after = $this->state->fingerprintWith($records);
        $actions = [];
        foreach ($transitions as $candidate => $current) {
            $actions[] = $this->stateAction($candidate, $current, 'enabled', $after);
        }
        $sourceFingerprints = [];
        foreach ($order as $candidate) {
            if ($descriptors[$candidate]->errors() !== []) { continue; }
            try {
                $sourceFingerprints[$candidate] = PackageSnapshot::sourceFingerprint(
                    $descriptors[$candidate]->path());
            } catch (PackageException) {
                $conflicts[] = 'Package ' . $candidate . ' source could not be reviewed.';
            }
        }
        return $this->reviewPlan('enable', $name, $actions, [], $conflicts,
            sourceFingerprints: $sourceFingerprints);
    }

    public function planDisable(string $name): ChangePlan
    {
        PackageName::require($name);
        $graph = $this->graph();
        $descriptors = $graph->descriptors();
        $descriptor = $descriptors[$name] ?? null;
        if ($descriptor === null) { throw new PackageException('Package is not installed.'); }
        $conflicts = $this->dependentConflicts($name, $graph);
        $current = $this->recordEnabled($name) ? 'enabled' : $descriptor->status();
        $records = $this->state->read();
        if (isset($records[$name])) { $records[$name]['enabled'] = false; }
        $actions = $current === 'enabled' ? [$this->stateAction($name, $current, 'disabled',
            $this->state->fingerprintWith($records))] : [];
        return $this->reviewPlan('disable', $name, $actions, [], $conflicts);
    }

    public function planInstall(string $source): ChangePlan
    {
        [$root, $name, $kind, $cleanup, $sourceLabel] = $this->prepareSource($source);
        try {
            $descriptor = $this->discovery->inspect($root, $name, null, true);
            $conflicts = $descriptor->errors();
            foreach ($this->descriptors() as $installed) {
                if (strcasecmp($installed->name(), $name) === 0) {
                    $conflicts[] = 'Package name already exists or conflicts by casing.';
                }
            }
            $conflicts = [...$conflicts, ...$this->newDependencyConflicts($descriptor)];
            $files = $descriptor->errors() === [] ? PackageFiles::fingerprints($root, true) : [];
            $owner = new ContributionOwner('package', $name);
            $manifest = json_encode($files, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $actions = [new ChangeAction('create', self::directorySubject($name), $owner,
                null, hash('sha256', $manifest), 'low', 'Create the Package directory.')];
            foreach ($files as $path => $hash) {
                $actions[] = new ChangeAction('create', self::fileSubject($name, $path), $owner,
                    null, $hash, 'low', 'Install reviewed Package source.');
            }
            $records = $this->state->read();
            $records[$name] = [
                'enabled' => false, 'source_kind' => $kind,
                'source' => $sourceLabel, 'files' => (object) $files,
            ];
            $actions[] = $this->stateAction($name, 'not installed', 'disabled',
                $this->state->fingerprintWith($records));
            return $this->reviewPlan('install', $name, $actions,
                [...$this->sourceWarnings($files), ...$this->metadataWarnings($descriptor)],
                $conflicts, $source, $files);
        } finally {
            $cleanup();
        }
    }

    public function planRemove(string $name): ChangePlan
    {
        PackageName::require($name);
        $graph = $this->graph();
        $descriptors = $graph->descriptors();
        $descriptor = $descriptors[$name] ?? null;
        if ($descriptor === null) { throw new PackageException('Package is not installed.'); }
        $record = $descriptor->record();
        $conflicts = $this->dependentConflicts($name, $graph);
        if ($record === null || ($record['source_kind'] ?? null) === 'manual') {
            $conflicts[] = 'Manually imported Package files are not managed by SqueHub.';
        } elseif (is_dir($descriptor->path()) || is_link($descriptor->path())) {
            $conflicts = [...$conflicts, ...$this->ownershipConflicts($descriptor)];
        }
        $files = is_array($record['files'] ?? null) ? $record['files'] : [];
        ksort($files, SORT_STRING);
        $owner = new ContributionOwner('package', $name);
        $actions = [];
        if (is_dir($descriptor->path())) {
            foreach ($files as $path => $hash) {
                $actions[] = new ChangeAction('delete', self::fileSubject($name, $path), $owner,
                    $hash, null, 'destructive', 'Remove an owned Package file.');
            }
            try {
                $rootFingerprint = PackageSnapshot::sourceFingerprint($descriptor->path());
                $actions[] = new ChangeAction('delete', self::directorySubject($name), $owner,
                    $rootFingerprint, null, 'destructive', 'Remove the owned Package directory.');
            } catch (PackageException) {
                $conflicts[] = 'Package directory is unsafe or unreadable.';
            }
        }
        $records = $this->state->read();
        unset($records[$name]);
        $actions[] = $this->stateAction($name, $descriptor->status(), 'not installed',
            $this->state->fingerprintWith($records));
        return $this->reviewPlan('remove', $name, $actions, [], $conflicts);
    }

    public function planUpgrade(string $name, string $source): ChangePlan
    {
        PackageName::require($name);
        $descriptors = $this->descriptors();
        $current = $descriptors[$name] ?? null;
        if ($current === null) { throw new PackageException('Package is not installed.'); }
        [$root, $candidateName, $kind, $cleanup, $sourceLabel] = $this->prepareSource($source);
        try {
            $candidate = $this->discovery->inspect($root, $candidateName, null, true);
            $conflicts = $candidate->errors();
            if ($candidateName !== $name) {
                $conflicts[] = 'Upgrade source Package identity differs from the installed Package.';
            }
            $record = $current->record();
            if ($record === null || ($record['source_kind'] ?? null) === 'manual') {
                $conflicts[] = 'Manually imported Package files are not managed by SqueHub.';
            } else {
                $conflicts = [...$conflicts, ...$this->ownershipConflicts($current)];
            }
            $conflicts = [...$conflicts, ...$this->newDependencyConflicts($candidate, $name)];
            if (($record['enabled'] ?? false) === true) {
                foreach ($candidate->dependencies() as $dependency) {
                    if (($descriptors[$dependency] ?? null)?->status() !== 'enabled') {
                        $conflicts[] = 'Required Package ' . $dependency . ' must be enabled before upgrade.';
                    }
                }
            }
            $files = $candidate->errors() === [] ? PackageFiles::fingerprints($root, true) : [];
            $old = is_array($record['files'] ?? null) ? $record['files'] : [];
            $paths = array_unique([...array_keys($old), ...array_keys($files)]);
            sort($paths, SORT_STRING);
            $owner = new ContributionOwner('package', $name);
            $actions = [];
            foreach ($paths as $path) {
                $before = $old[$path] ?? null;
                $after = $files[$path] ?? null;
                if ($before === $after) { continue; }
                $actionKind = $before === null ? 'create' : ($after === null ? 'delete' : 'modify');
                $actions[] = new ChangeAction($actionKind, self::fileSubject($name, $path), $owner,
                    $before, $after, $actionKind === 'delete' ? 'destructive' : ($actionKind === 'modify' ? 'review' : 'low'),
                    'Update reviewed Package source.');
            }
            $records = $this->state->read();
            if (isset($records[$name])) {
                $records[$name]['source_kind'] = $kind;
                $records[$name]['source'] = $sourceLabel;
                $records[$name]['files'] = (object) $files;
            }
            $actions[] = $this->stateAction($name, $current->status(), $current->status(),
                $this->state->fingerprintWith($records), 'Refresh Package ownership metadata.');
            return $this->reviewPlan('upgrade', $name, $actions,
                [...$this->sourceWarnings($files), ...$this->metadataWarnings($candidate, $current)],
                $conflicts, $source, $files);
        } finally {
            $cleanup();
        }
    }

    private static function fileSubject(string $name, string $path): string
    {
        return 'Project/Packages/' . $name . '/' . $path;
    }

    private static function directorySubject(string $name): string
    {
        return 'Project/Packages/' . $name;
    }

    private function stateAction(string $name, string $current, string $target,
        string $afterFingerprint, string $reason = 'Change Package activation state.'): ChangeAction
    {
        return new ChangeAction('state', 'Project/Activation.json',
            new ContributionOwner('package', $name), $this->state->currentFingerprint(),
            $afterFingerprint, $target === 'not installed' ? 'destructive' : 'review',
            $name . ': ' . $current . ' to ' . $target . '. ' . $reason);
    }

    /**
     * Source inspection reports executable data files; applying never runs them.
     *
     * @param array<string, string> $files
     * @return list<string>
     */
    private function sourceWarnings(array $files): array
    {
        $warnings = [];
        foreach (array_keys($files) as $path) {
            if (preg_match('~(?:^|/)Migrations/~i', $path) === 1) {
                $warnings['migration'] = 'Package contains migrations; execution is separate.';
            }
            if (preg_match('~(?:^|/)Seeders/~i', $path) === 1) {
                $warnings['seeder'] = 'Package contains Seeders; execution is separate.';
            }
        }
        return array_values($warnings);
    }

    /** Requirement names are validated Package identifiers; arbitrary Composer fields stay private. */
    /** @return list<string> */
    private function metadataWarnings(PackageDescriptor $candidate, ?PackageDescriptor $previous = null): array
    {
        $required = $candidate->dependencies();
        sort($required, SORT_STRING);
        // Each requirement gets its own bounded line, even at the metadata limit.
        $warnings = array_map(static fn (string $name): string => 'Required Package: ' . $name . '.', $required);
        if ($previous === null) { return $warnings; }

        $added = array_values(array_diff($required, $previous->dependencies()));
        $removed = array_values(array_diff($previous->dependencies(), $required));
        sort($added, SORT_STRING);
        sort($removed, SORT_STRING);
        foreach ($added as $dependency) { $warnings[] = 'Added Package requirement: ' . $dependency . '.'; }
        foreach ($removed as $dependency) { $warnings[] = 'Removed Package requirement: ' . $dependency . '.'; }

        $from = $previous->version();
        $to = $candidate->version();
        if ($from !== $to) {
            // A version field is arbitrary Composer metadata. Show numeric
            // releases; refer to the file for anything more expressive.
            $numeric = static fn (?string $version): bool => $version !== null
                && preg_match('/\A[0-9]+(?:\.[0-9]+){0,3}\z/D', $version) === 1;
            $warnings[] = $numeric($from) && $numeric($to)
                ? 'Package version: ' . $from . ' to ' . $to . '.'
                : 'Package version metadata changes; inspect composer.json.';
        }
        return $warnings;
    }

    /**
     * Keep source locations outside the public plan. A plan is applicable only
     * through the manager that inspected it and retains these private inputs.
     *
     * @param list<ChangeAction> $actions
     * @param list<string> $warnings
     * @param list<string> $conflicts
     * @param array<string, string> $files
     * @param array<string, string> $sourceFingerprints
     */
    private function reviewPlan(string $operation, string $name, array $actions, array $warnings,
        array $conflicts, ?string $source = null, array $files = [],
        array $sourceFingerprints = []): ChangePlan
    {
        $stateFingerprint = $this->state->currentFingerprint();
        $preconditions = ['Project/Activation.json' => $stateFingerprint];
        foreach ($actions as $action) {
            if ($action->kind === 'state') {
                if ($action->before !== $stateFingerprint) {
                    throw new PackageException('Package state changed while planning.');
                }
                continue;
            }
            $preconditions[$action->subject] = $action->before;
        }
        foreach ($sourceFingerprints as $candidate => $fingerprint) {
            $preconditions['Project/Packages/' . $candidate . '#source'] = $fingerprint;
        }
        if (in_array($operation, ['remove', 'upgrade'], true)) {
            $record = $this->state->read()[$name] ?? null;
            if (is_array($record)) {
                $owned = is_array($record['files'] ?? null) ? $record['files'] : [];
                ksort($owned, SORT_STRING);
                $encoded = json_encode($owned, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $preconditions['Project/Packages/' . $name . '#ownership'] = hash('sha256', $encoded);
            }
        }
        ksort($preconditions, SORT_STRING);
        $warnings = array_values(array_unique($warnings));
        $conflicts = array_values(array_unique($conflicts));
        sort($warnings, SORT_STRING);
        sort($conflicts, SORT_STRING);
        $plan = new ChangePlan('package:' . $operation, $name, new ContributionOwner('package', $name),
            $actions, $warnings, $conflicts, $preconditions);
        $this->planInputs[$plan] = [
            'operation' => $operation, 'name' => $name, 'source' => $source,
            'files' => $files, 'state' => $stateFingerprint,
        ];
        return $plan;
    }

    /** Apply only a still-current, conflict-free plan created by this manager. */
    public function apply(ChangePlan $plan): ChangeResult
    {
        if (!isset($this->planInputs[$plan])) {
            throw new PackageException('Package plan does not belong to this Application.');
        }
        if ($plan->hasConflicts()) {
            throw new PackageException('Package operation has unresolved conflicts.');
        }
        $inputs = $this->planInputs[$plan];
        // Reject predictable stale plans before creating a lifecycle lock file.
        // The second check under the lock closes the gap for cooperating CLIs.
        $this->assertCurrentPlan($plan, $inputs);
        return $this->withActivationLock(function () use ($plan, $inputs): ChangeResult {
            return $this->applyLocked($plan, $inputs);
        });
    }

    /** @param array{operation:string,name:string,source:?string,files:array<string,string>,state:?string} $inputs */
    private function applyLocked(ChangePlan $plan, array $inputs): ChangeResult
    {
        $this->assertCurrentPlan($plan, $inputs);
        $beforeArtifacts = $this->recoveryArtifacts($inputs['name']);
        try {
            $recoveryPath = match ($inputs['operation']) {
                'enable', 'disable' => $this->applyState($inputs['name'], $inputs['operation'], $plan->actions),
                'install' => $this->applyInstall($inputs['name'], $inputs['source'] ?? '', $inputs['files']),
                'remove' => $this->applyRemove($inputs['name']),
                'upgrade' => $this->applyUpgrade($inputs['name'], $inputs['source'] ?? '', $inputs['files']),
                default => throw new PackageException('Unknown Package operation.'),
            };
        } catch (Throwable $exception) {
            $afterArtifacts = $this->recoveryArtifacts($inputs['name']);
            $newArtifacts = array_values(array_diff($afterArtifacts, $beforeArtifacts));
            $recoveryPath = $newArtifacts[0] ?? null;
            $result = $this->observedResult($plan, $recoveryPath);
            if ($result->applied !== [] || $recoveryPath !== null) {
                throw new ChangeApplyException($result, $exception);
            }
            throw $exception;
        }
        return $this->verifyOrReport($plan, $recoveryPath);
    }

    /** Verification can itself fail after writes; retain the observed outcome. */
    private function verifyOrReport(ChangePlan $plan, ?string $recoveryPath): ChangeResult
    {
        try {
            return $this->verifyApplied($plan, $recoveryPath);
        } catch (Throwable $exception) {
            throw new ChangeApplyException($this->observedResult($plan, $recoveryPath), $exception);
        }
    }

    /** @return list<string> Safe application-relative Package staging/recovery paths. */
    private function recoveryArtifacts(string $name): array
    {
        $directory = $this->directory();
        if (!is_dir($directory)) { return []; }
        $entries = @scandir($directory);
        if ($entries === false) { return []; }
        $artifacts = [];
        $prefix = '.' . $name . '.';
        foreach ($entries as $entry) {
            if (str_starts_with($entry, $prefix)
                && preg_match('/\A\.' . preg_quote($name, '/')
                    . '\.(?:stage|backup|remove)-[a-f0-9]{16}\z/D', $entry) === 1) {
                $artifacts[] = 'Project/Packages/' . $entry;
            }
        }
        sort($artifacts, SORT_STRING);
        return $artifacts;
    }

    /**
     * Compare observed bytes with the reviewed before/after fingerprints.
     * A failed rollback is never reported as successful merely because the
     * original files remain in a recovery directory.
     */
    private function observedResult(ChangePlan $plan, ?string $recoveryPath): ChangeResult
    {
        $applied = [];
        $unapplied = [];
        $failed = null;
        foreach ($plan->actions as $action) {
            try {
                $actual = $action->kind === 'state' ? $this->state->currentFingerprint()
                    : $this->fileFingerprint($action->subject,
                        $action->subject === self::directorySubject($action->owner->name));
            } catch (Throwable) {
                $actual = false;
            }
            if ($actual !== false && $action->before === $action->after && $actual === $action->before) {
                // Equal fingerprints cannot prove that a write occurred.
                $unapplied[] = $action;
            } elseif ($actual !== false && $actual === $action->after) {
                $applied[] = $action;
            } elseif ($actual !== false && $actual === $action->before) {
                $unapplied[] = $action;
            } elseif ($failed === null) {
                $failed = $action;
            } else {
                $unapplied[] = $action;
            }
        }
        return new ChangeResult($plan, $applied, $failed, $unapplied, false, $recoveryPath);
    }

    /** @param array{operation:string,name:string,source:?string,files:array<string,string>,state:?string} $inputs */
    private function assertCurrentPlan(ChangePlan $plan, array $inputs): void
    {
        if ($this->state->currentFingerprint() !== $inputs['state']) {
            throw new PackageException('Package plan is stale; state changed after review.');
        }
        foreach ($plan->preconditions as $subject => $expected) {
            if ($subject === 'Project/Activation.json') { continue; }
            if (preg_match('~\AProject/Packages/([A-Z][A-Za-z0-9_]*)#source\z~D',
                $subject, $match) === 1) {
                try {
                    $actual = PackageSnapshot::sourceFingerprint($this->directory() . '/' . $match[1]);
                } catch (PackageException) {
                    $actual = null;
                }
            } elseif (str_ends_with($subject, '#ownership')) {
                $records = $this->state->read();
                $record = $records[$inputs['name']] ?? null;
                $owned = is_array($record['files'] ?? null) ? $record['files'] : [];
                ksort($owned, SORT_STRING);
                $actual = $record === null ? null : hash('sha256',
                    json_encode($owned, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                $actual = $this->fileFingerprint($subject,
                    $subject === self::directorySubject($inputs['name']));
            }
            if ($actual !== $expected) {
                throw new PackageException('Package plan is stale; a reviewed file or owner changed.');
            }
        }
        $fresh = match ($inputs['operation']) {
            'enable' => $this->planEnable($inputs['name']),
            'disable' => $this->planDisable($inputs['name']),
            'install' => $this->planInstall($inputs['source'] ?? ''),
            'remove' => $this->planRemove($inputs['name']),
            'upgrade' => $this->planUpgrade($inputs['name'], $inputs['source'] ?? ''),
            default => throw new PackageException('Unknown Package operation.'),
        };
        if ($fresh->hasConflicts() || $plan->fingerprint() !== $fresh->fingerprint()
            || $inputs['files'] !== $this->planInputs[$fresh]['files']) {
            throw new PackageException('Package plan is stale; inspect it again before applying.');
        }
    }

    /** A changed symlink, directory, or unreadable file is never accepted as the expected file. */
    private function fileFingerprint(string $subject, bool $directory = false): ?string
    {
        $path = $this->app->basePath($subject);
        if (!file_exists($path) && !is_link($path)) { return null; }
        PackageFiles::assertPhysical($path);
        if ($directory) {
            if (!is_dir($path)) {
                throw new PackageException('Package plan is stale; reviewed directory became a file.');
            }
            return PackageSnapshot::sourceFingerprint($path);
        }
        if (!is_file($path)) {
            throw new PackageException('Package plan is stale; reviewed file became a directory.');
        }
        $fingerprint = @hash_file('sha256', $path);
        if ($fingerprint === false) {
            throw new PackageException('Package file could not be fingerprinted.');
        }
        return $fingerprint;
    }

    /** Verify only bytes and state, never Package hooks, migrations, or Seeders. */
    private function verifyApplied(ChangePlan $plan, ?string $recoveryPath): ChangeResult
    {
        $applied = [];
        foreach ($plan->actions as $index => $action) {
            $actual = $action->kind === 'state' ? $this->state->currentFingerprint()
                : $this->fileFingerprint($action->subject,
                    $action->subject === self::directorySubject($action->owner->name));
            if ($actual !== $action->after) {
                return new ChangeResult($plan, $applied, $action,
                    array_slice($plan->actions, $index + 1), false, $recoveryPath);
            }
            $applied[] = $action;
        }
        return new ChangeResult($plan, $applied, null, [], true, $recoveryPath);
    }

    /** @return array<string, PackageDescriptor> */
    private function descriptors(): array
    {
        try {
            $descriptors = $this->app->container()->make(ActivationRegistry::class)->packages();
        } catch (ActivationException $exception) {
            throw new PackageException('Package activation metadata could not be inspected.', 0, $exception);
        }
        foreach ($this->runtimeErrors as $name => $error) {
            if (isset($descriptors[$name])) {
                $descriptor = $descriptors[$name];
                $descriptors[$name] = $descriptor->withStatus('broken',
                    [...$descriptor->errors(), $error]);
            }
        }
        return $descriptors;
    }

    /** @return list<string> */
    private function dependentConflicts(string $name, PackageGraph $graph): array
    {
        $conflicts = [];
        $descriptors = $graph->descriptors();
        foreach ($this->registryDependents($name) as $edge) {
            if ($edge['kind'] === 'package'
                && (($descriptors[$edge['name']] ?? null)?->record()['enabled'] ?? false) === true) {
                $conflicts[] = 'Enabled Package ' . $edge['name'] . ' requires ' . $name . '.';
            }
            // Installed Kits retain their published Project files when disabled.
            // Their last recorded requirements continue to protect a Package
            // if the Kit definition later becomes unreadable or broken.
            if ($edge['kind'] === 'kit') {
                $conflicts[] = 'Installed Kit ' . $edge['name'] . ' requires ' . $name . '.';
            }
        }
        return $conflicts;
    }

    /** @return list<string> */
    private function newDependencyConflicts(PackageDescriptor $candidate, ?string $replaced = null): array
    {
        $descriptors = $this->descriptors();
        if ($replaced !== null) { unset($descriptors[$replaced]); }
        $descriptors[$candidate->name()] = $candidate;
        $graph = new PackageGraph($descriptors);
        $conflicts = [];
        foreach ($graph->dependenciesOf($candidate->name()) as $dependency) {
            if (!isset($descriptors[$dependency])) {
                $conflicts[] = 'Required Package ' . $dependency . ' is missing.';
            }
        }
        if ($graph->isCyclic($candidate->name())) {
            $conflicts[] = self::cycleError($graph, $candidate->name());
        }
        return $conflicts;
    }

    /** Keep diagnostics safe and concise even for unusually large cycles. */
    private static function cycleError(PackageGraph $graph, string $name): string
    {
        $path = $graph->cyclePathFor($name);
        if ($path === []) { return 'Package dependency cycle detected.'; }
        if (count($path) > 13) {
            $path = [...array_slice($path, 0, 11), '…', $name];
        }
        return 'Package dependency cycle detected: ' . implode(' → ', $path) . '.';
    }

    /** @return list<string> */
    private function ownershipConflicts(PackageDescriptor $descriptor, ?string $rootPath = null): array
    {
        $record = $descriptor->record();
        $owned = is_array($record['files'] ?? null) ? $record['files'] : [];
        $rootPath ??= $descriptor->path();
        try {
            $actual = PackageFiles::fingerprints($rootPath);
        } catch (PackageException) {
            return ['Package files are unsafe or unreadable.'];
        }
        $conflicts = [];
        foreach ($owned as $path => $hash) {
            if (($actual[$path] ?? null) !== $hash) {
                $conflicts[] = 'Modified or missing Package-owned file: ' . $descriptor->name() . '/' . $path;
            }
        }
        foreach ($actual as $path => $hash) {
            if (!array_key_exists($path, $owned)) {
                $conflicts[] = 'Untracked Package file: ' . $descriptor->name() . '/' . $path;
            }
        }
        $ownedDirectories = [];
        foreach (array_keys($owned) as $path) {
            $segments = explode('/', $path);
            array_pop($segments);
            $prefix = '';
            foreach ($segments as $segment) {
                $prefix = ltrim($prefix . '/' . $segment, '/');
                $ownedDirectories[$prefix] = true;
            }
        }
        try {
            foreach (PackageFiles::directories($rootPath) as $directory) {
                if (!isset($ownedDirectories[$directory])) {
                    $conflicts[] = 'Untracked Package directory: ' . $descriptor->name() . '/' . $directory;
                }
            }
        } catch (PackageException) {
            $conflicts[] = 'Package directories are unsafe or unreadable.';
        }
        sort($conflicts, SORT_STRING);
        return $conflicts;
    }

    private function recordEnabled(string $name): bool
    {
        return ($this->state->read()[$name]['enabled'] ?? false) === true;
    }

    /** @param list<ChangeAction> $actions */
    private function applyState(string $name, string $operation, array $actions): null
    {
        if ($actions === []) { return null; }
        $records = $this->state->read();
        foreach ($actions as $action) {
            $candidate = $operation === 'enable' ? $action->owner->name : $name;
            $current = $records[$candidate] ?? [
                'enabled' => false, 'source_kind' => 'manual', 'source' => 'manual', 'files' => (object) [],
            ];
            $current['enabled'] = $operation === 'enable';
            $records[$candidate] = $current;
        }
        $this->state->write($records);
        return null;
    }

    /** @param array<string, string> $files */
    private function applyInstall(string $name, string $source, array $files): null
    {
        [$root, $sourceName, $kind, $cleanup, $sourceLabel] = $this->prepareSource($source);
        if ($sourceName !== $name) {
            $cleanup();
            throw new PackageException('Package source identity changed after review.');
        }
        $directory = $this->directory();
        $stage = $directory . '/.' . $name . '.stage-' . bin2hex(random_bytes(8));
        $target = $directory . '/' . $name;
        try {
            if (PackageFiles::fingerprints($root, true) !== $files
                || file_exists($target) || is_link($target)) {
                throw new PackageException('Package source or target changed after preview.');
            }
            $this->ensureDirectory();
            PackageFiles::copy($root, $stage, $files);
            // The lock coordinates SqueHub writers; a separate process can
            // still create this path. Recheck immediately before publication.
            if (self::pathExistsOrLink($target)) {
                throw new PackageException('Package target changed while source was staged.');
            }
            if (!@rename($stage, $target)) {
                throw new PackageException('Package files could not be installed.');
            }
            try {
                $records = $this->state->read();
                $records[$name] = [
                    'enabled' => false, 'source_kind' => $kind,
                    'source' => $sourceLabel, 'files' => (object) $files,
                ];
                $this->state->write($records);
            } catch (Throwable $exception) {
                try {
                    PackageFiles::remove($target, $this->app->basePath());
                } catch (PackageException $cleanupFailure) {
                    throw new PackageException('Package installation was not recorded; files could not be '
                        . 'fully removed from Project/Packages/' . $name . '.', 0, $cleanupFailure);
                }
                throw $exception;
            }
        } finally {
            if (is_dir($stage)) { PackageFiles::remove($stage, $this->app->basePath()); }
            $cleanup();
        }
        return null;
    }

    private function applyRemove(string $name): ?string
    {
        $target = $this->directory() . '/' . $name;
        if (!is_dir($target) && !is_link($target) && !file_exists($target)) {
            $records = $this->state->read();
            unset($records[$name]);
            $this->state->write($records);
            return null;
        }
        $descriptor = $this->descriptors()[$name] ?? null;
        if ($descriptor === null) {
            throw new PackageException('Package changed after review.');
        }
        $backup = $this->directory() . '/.' . $name . '.remove-' . bin2hex(random_bytes(8));
        PackageFiles::assertPhysical($target);
        if (!@rename($target, $backup)) {
            throw new PackageException('Package files could not be prepared for removal.');
        }
        try {
            // The lifecycle lock protects SqueHub writers, not a developer
            // editing files outside the CLI. Recheck the renamed tree before
            // deleting anything from it.
            if ($this->ownershipConflicts($descriptor, $backup) !== []) {
                throw new PackageException('Package files changed during removal; review the operation again.');
            }
            $records = $this->state->read();
            unset($records[$name]);
            $this->state->write($records);
        } catch (Throwable $exception) {
            if (file_exists($target) || is_link($target) || !@rename($backup, $target)) {
                throw new PackageException('Package removal was not recorded; original files remain at '
                    . 'Project/Packages/' . basename($backup) . ' and need manual recovery.', 0, $exception);
            }
            throw $exception;
        }
        // State and public path now reflect removal. Recursive cleanup can
        // fail partway; its remaining backup is a recovery artifact, not a
        // reason to claim the operation rolled back.
        try {
            PackageFiles::remove($backup, $this->app->basePath());
        } catch (PackageException $exception) {
            return 'Project/Packages/' . basename($backup);
        }
        return null;
    }

    /** @param array<string, string> $files */
    private function applyUpgrade(string $name, string $source, array $files): ?string
    {
        [$root, $sourceName, $kind, $cleanup, $sourceLabel] = $this->prepareSource($source);
        if ($sourceName !== $name) {
            $cleanup();
            throw new PackageException('Package source identity changed after review.');
        }
        $directory = $this->directory();
        $target = $directory . '/' . $name;
        $stage = $directory . '/.' . $name . '.stage-' . bin2hex(random_bytes(8));
        $backup = $directory . '/.' . $name . '.backup-' . bin2hex(random_bytes(8));
        try {
            if (PackageFiles::fingerprints($root, true) !== $files) {
                throw new PackageException('Package source changed after preview.');
            }
            PackageFiles::assertPhysical($target);
            $descriptor = $this->descriptors()[$name] ?? null;
            if ($descriptor === null) {
                throw new PackageException('Package changed after review.');
            }
            PackageFiles::copy($root, $stage, $files);
            if (!@rename($target, $backup)) {
                throw new PackageException('Package files could not be prepared for upgrade.');
            }
            try {
                if ($this->ownershipConflicts($descriptor, $backup) !== []) {
                    throw new PackageException('Package files changed during upgrade; review the operation again.');
                }
                if (file_exists($target) || is_link($target)) {
                    throw new PackageException('Package target changed during upgrade.');
                }
                if (!@rename($stage, $target)) {
                    throw new PackageException('Package files could not be upgraded.');
                }
                $records = $this->state->read();
                $records[$name]['source_kind'] = $kind;
                $records[$name]['source'] = $sourceLabel;
                $records[$name]['files'] = (object) $files;
                $this->state->write($records);
            } catch (Throwable $exception) {
                if (is_dir($target) || is_link($target) || file_exists($target)) {
                    try {
                        PackageFiles::remove($target, $this->app->basePath());
                    } catch (PackageException $cleanupFailure) {
                        throw new PackageException('Package upgrade was not recorded; replacement files could not '
                            . 'be fully removed and original files remain at Project/Packages/'
                            . basename($backup) . '.', 0, $cleanupFailure);
                    }
                }
                if (!@rename($backup, $target)) {
                    throw new PackageException('Package upgrade was not recorded; original files remain at '
                        . 'Project/Packages/' . basename($backup) . ' and need manual recovery.', 0, $exception);
                }
                throw $exception;
            }
            // The new target and ownership state are already in place. A
            // partial backup cleanup must be reported as such to operators.
            try {
                PackageFiles::remove($backup, $this->app->basePath());
            } catch (PackageException $exception) {
                return 'Project/Packages/' . basename($backup);
            }
        } finally {
            if (is_dir($stage)) { PackageFiles::remove($stage, $this->app->basePath()); }
            $cleanup();
        }
        return null;
    }

    /**
     * @return array{string, string, 'local'|'git', \Closure():void, string}
     */
    private function prepareSource(string $source): array
    {
        if ($source === '' || strlen($source) > 4096
            || preg_match('/[\x00-\x1F\x7F]/', $source)) {
            throw new PackageException('Package source is invalid.');
        }
        if (preg_match('~\Ahttps://~i', $source) === 1) {
            $parts = parse_url($source);
            if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
                || !isset($parts['host']) || isset($parts['user'], $parts['pass'])
                || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])) {
                throw new PackageException('Git Package source must be a credential-free HTTPS URL.');
            }
            $basename = basename(rtrim($parts['path'] ?? '', '/'));
            $name = preg_replace('/\.git\z/i', '', $basename) ?? '';
            PackageName::require($name);
            $label = 'git:' . strtolower($parts['host']) . '/' . $name
                . '#' . substr(hash('sha256', $source), 0, 12);
            if (strlen($label) > 256) {
                throw new PackageException('Git Package source label is too long.');
            }
            $parent = sys_get_temp_dir();
            $temporary = $parent . '/SqueHub-Package-' . bin2hex(random_bytes(10));
            if (!@mkdir($temporary, 0700)) {
                throw new PackageException('Git Package staging could not be created.');
            }
            $root = $temporary . '/Checkout';
            try {
                // Git interprets relative hooksPath inside the checkout on
                // Unix. An absolute empty directory outside the checkout keeps
                // even a committed post-checkout hook inert during inspection.
                $hooks = $temporary . '/EmptyHooks';
                if (!@mkdir($hooks, 0700)) {
                    throw new PackageException('Git Package staging could not be prepared.');
                }
                $process = new Process(['git', '-c', 'core.hooksPath=' . str_replace('\\', '/', $hooks),
                    'clone', '--depth', '1',
                    '--single-branch', '--no-recurse-submodules', '--', $source, $root]);
                $process->setTimeout(120);
                $process->run(null, ['GIT_TERMINAL_PROMPT' => '0']);
                if (!$process->isSuccessful() || !is_dir($root)) {
                    throw new PackageException('Git Package source could not be inspected.');
                }
            } catch (Throwable $exception) {
                PackageFiles::removeTemporary($temporary, $parent);
                if ($exception instanceof PackageException) { throw $exception; }
                throw new PackageException('Git Package source could not be inspected.', 0, $exception);
            }
            $cleanup = static function () use ($temporary, $parent): void {
                PackageFiles::removeTemporary($temporary, $parent);
            };
            return [$root, $name, 'git', $cleanup, $label];
        }
        if (str_contains($source, '://')) {
            throw new PackageException('Package source must be a local directory or HTTPS Git URL.');
        }
        if (is_link($source)) {
            throw new PackageException('Local Package source cannot be linked.');
        }
        $root = realpath($source);
        if ($root === false || !is_dir($root)) {
            throw new PackageException('Local Package source is unavailable.');
        }
        PackageFiles::assertPhysical($root);
        $name = basename(str_replace('\\', '/', $root));
        PackageName::require($name);
        // Source labels are content-derived so an equivalent Package copied to
        // another machine produces the same review identity without leaking its path.
        $manifest = PackageFiles::fingerprints($root, true);
        $encoded = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $label = 'local:' . $name . '#' . substr(hash('sha256', $encoded), 0, 12);
        return [$root, $name, 'local', static function (): void {}, $label];
    }

    private function ensureDirectory(): void
    {
        $directory = $this->directory();
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new PackageException('Package directory could not be created.');
        }
        PackageFiles::assertPhysical($directory);
    }

    private function directory(): string
    {
        return $this->app->basePath('Project/Packages');
    }

    /** @return list<array{kind:string,name:string}> */
    private function registryDependents(string $name): array
    {
        try {
            return $this->app->container()->make(ActivationRegistry::class)
                ->dependents('package', $name);
        } catch (ActivationException $exception) {
            throw new PackageException('Package dependents could not be inspected.', 0, $exception);
        }
    }

    /** One shared lock orders Package and Kit state changes across processes. */
    private function withActivationLock(callable $operation): mixed
    {
        try {
            return $this->app->container()->make(ActivationStore::class)->withLock($operation);
        } catch (ActivationLockException $exception) {
            throw new PackageException('Package activation registry lock failed.', 0, $exception);
        } catch (ActivationException $exception) {
            throw new PackageException('Package activation registry is unavailable.', 0, $exception);
        }
    }

    private function samePath(string $left, string $right): bool
    {
        return PhysicalPath::same($left, $right);
    }

    /** Check again after staging, including a newly created broken link. */
    private static function pathExistsOrLink(string $path): bool
    {
        return is_link($path) || file_exists($path);
    }

    /** Package code may add only its own configuration defaults. */
    private function withPackageConfig(string $name, callable $action, ?string $source = null): mixed
    {
        $config = $this->app->config();
        $config->beginPackageContext($name);
        try {
            return $this->app->contributions()->withOwner(new ContributionOwner('package', $name),
                $action, $source ?? 'Project/Packages/' . $name . '/' . $name . '.php');
        } finally {
            $config->endPackageContext();
        }
    }
}
