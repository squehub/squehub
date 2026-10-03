<?php

declare(strict_types=1);

namespace App\Kits;

use App\Activation\ActivationStore;
use App\Activation\ActivationRegistry;
use App\Changes\ChangeAction;
use App\Changes\ChangeApplyException;
use App\Changes\ChangePlan;
use App\Changes\ChangeResult;
use App\Contributions\ContributionOwner;
use App\Foundation\Application;
use App\Packages\PackageException;
use App\Packages\PackageFiles;
use App\Packages\PackageManager;
use App\Packages\PackageSnapshot;
use JsonException;
use ReflectionClass;
use Throwable;
use WeakMap;

/**
 * Application-owned Kit composition lifecycle. Inspection reads only JSON and
 * source bytes; trusted Kit PHP runs solely for declared apply-time hooks.
 */
final class KitManager
{
    private KitStateStore $state;
    private KitDiscovery $discovery;
    private ActivationStore $activation;
    private ActivationRegistry $registry;

    /** @var WeakMap<ChangePlan,array{operation:string,name:string,source:?string,definition:array<string,string>,published:array<string,array{source:string,hash:string,kind:string}>,registry_state:string}> */
    private WeakMap $planInputs;

    public function __construct(private Application $app)
    {
        $directory = $this->directory();
        $this->activation = $app->container()->make(ActivationStore::class);
        $this->registry = $app->container()->make(ActivationRegistry::class);
        $this->state = new KitStateStore($directory, $this->activation);
        $this->discovery = new KitDiscovery($directory);
        $this->planInputs = new WeakMap();
    }

    /** @return list<KitDescriptor> */
    public function list(): array
    {
        return array_values($this->descriptors());
    }

    /**
     * Source ownership is different from runtime ownership: published Project
     * files remain ordinary application code after Kit disable.
     *
     * @return array{descriptor:KitDescriptor,required_packages:list<string>,owned_files:list<array{path:string,kind:string,modified:bool}>}
     */
    public function inspect(string $name): array
    {
        KitName::require($name);
        $descriptor = $this->descriptors()[$name] ?? null;
        if ($descriptor === null) { throw new KitException('Kit is not installed.'); }
        $owned = [];
        foreach ($this->published($descriptor->record()) as $path => $item) {
            try {
                $modified = KitFiles::fingerprint($this->app->basePath(), $name, $path) !== $item['hash'];
            } catch (KitException) {
                $modified = true;
            }
            $owned[] = ['path' => $path, 'kind' => $item['kind'], 'modified' => $modified];
        }
        return ['descriptor' => $descriptor, 'required_packages' => $descriptor->requires(),
            'owned_files' => $owned];
    }

    /**
     * Installed, even disabled, Kits conservatively protect required Packages.
     * Last recorded requirements remain protective if a Kit source later breaks.
     *
     * @return list<string>
     */
    public function requiredByPackage(string $package): array
    {
        $names = [];
        foreach ($this->registry->dependents('package', $package) as $dependent) {
            if ($dependent['kind'] === 'kit') { $names[] = $dependent['name']; }
        }
        return $names;
    }

    /** Static artifact origin, not a claim that Kit PHP boots at runtime. */
    public function ownerForFile(string $relative): ?ContributionOwner
    {
        KitManifest::requireRelative($relative);
        foreach ($this->state->read() as $name => $record) {
            if (isset($record['published'][$relative])) {
                return new ContributionOwner('kit', $name);
            }
        }
        return null;
    }

    /** A local Kit source is inspected as data before it reaches the application. */
    public function planInstall(string $source): ChangePlan
    {
        [$root, $name, $label] = $this->localSource($source);
        $candidate = $this->discovery->inspect($root, $name, null, true);
        $conflicts = $candidate->errors();
        foreach ($this->descriptors() as $installed) {
            if (strcasecmp($installed->name(), $name) === 0) {
                $conflicts[] = 'Kit name already exists or conflicts by casing.';
            }
        }
        $definition = $conflicts === [] ? KitFiles::fingerprints($root, true) : [];
        $owner = new ContributionOwner('kit', $name);
        $actions = [];
        if ($conflicts === []) {
            $actions[] = new ChangeAction('create', self::directorySubject($name), $owner,
                null, self::sourceFingerprint($definition), 'low', 'Install reviewed Kit definition.');
            foreach ($definition as $path => $hash) {
                $actions[] = new ChangeAction('create', self::definitionSubject($name, $path),
                    $owner, null, $hash, 'low', 'Copy reviewed Kit source.');
            }
        }
        $records = $this->state->read();
        $records[$name] = self::record(false, 'local', $label, $definition, [],
            $candidate->requires());
        $actions[] = $this->stateAction($name, 'not installed', 'installed/disabled', $records);
        return $this->reviewPlan('install', $name, $actions,
            [...self::hookWarnings($candidate, 'install'),
                'Installation does not publish application files or execute Migrations/Seeders.'],
            $conflicts, $source, $definition);
    }

    public function planEnable(string $name): ChangePlan
    {
        $descriptor = $this->requireDescriptor($name);
        $conflicts = $descriptor->errors();
        $record = $this->recordFor($descriptor);
        $owner = new ContributionOwner('kit', $name);
        $actions = [];
        $published = [];
        $packages = [];
        $packageSources = [];
        if ($conflicts === []) {
            [$packages, $packageConflicts, $packageSources] =
                $this->packagesToEnable($descriptor->requires());
            $conflicts = [...$conflicts, ...$packageConflicts];
            if (($record['enabled'] ?? false) !== true) {
                foreach ($packages as $package) {
                    $actions[] = new ChangeAction('state', 'Project/Packages/' . $package . '#activation',
                        $owner, null, null, 'review', 'Enable required Package in the activation registry.');
                }
                [$fileActions, $published, $fileConflicts] = $this->publicationPlan(
                    $descriptor, $descriptor->path(), $record['published']);
                $actions = [...$actions, ...$fileActions];
                $conflicts = [...$conflicts, ...$fileConflicts];
                [$removedActions, $removedConflicts] = $this->removedPublicationPlan(
                    $name, $record['published'], $published);
                $actions = [...$actions, ...$removedActions];
                $conflicts = [...$conflicts, ...$removedConflicts];
            }
        }
        if (($record['enabled'] ?? false) !== true) {
            $records = $this->state->read();
            $records[$name] = self::record(true, $record['source_kind'], $record['source'],
                $record['definition'], $published, $descriptor->requires());
            $actions[] = $this->stateAction($name, 'disabled', 'enabled', $records, $packages);
        }
        return $this->reviewPlan('enable', $name, $actions,
            [...self::hookWarnings($descriptor, 'enable'),
                'Migration and Seeder execution: NOT INCLUDED.'],
            $conflicts, null, [], $published, $packageSources);
    }

    public function planDisable(string $name): ChangePlan
    {
        $descriptor = $this->requireDescriptor($name);
        // An enabled Kit with broken source must still be manageable. Its
        // state can be disabled without executing the broken entry class.
        $conflicts = [];
        $actions = [];
        if (($descriptor->record()['enabled'] ?? false) === true) {
            $records = $this->state->read();
            $records[$name]['enabled'] = false;
            $actions[] = $this->stateAction($name, 'enabled', 'disabled', $records);
        }
        return $this->reviewPlan('disable', $name, $actions,
            [...self::hookWarnings($descriptor, 'disable'),
                ...($descriptor->errors() !== []
                    ? ['Kit definition is broken; disable changes activation state only and skips unavailable hooks.']
                    : []),
                'Generated Project files and published assets/config remain on disk.',
                'Required Packages remain enabled and installed; Migrations are not rolled back.'],
            $conflicts);
    }

    /**
     * Upgrades compare both Kit definition and published application bytes.
     * Migration replacements/removals are blocked without querying or mutating
     * the application's migration repository during a preview.
     */
    public function planUpgrade(string $name, string $source): ChangePlan
    {
        $current = $this->requireDescriptor($name);
        [$root, $candidateName, $label] = $this->localSource($source);
        $candidate = $this->discovery->inspect($root, $candidateName, null, true);
        $conflicts = [...$candidate->errors(), ...$current->errors()];
        if ($candidateName !== $name) { $conflicts[] = 'Upgrade source Kit identity differs.'; }
        $record = $current->record();
        if ($record === null || $current->sourceKind() === 'manual') {
            $conflicts[] = 'Manually imported Kit definitions are not owned for upgrade.';
        } else {
            $conflicts = [...$conflicts, ...$this->definitionConflicts($current)];
        }
        $owner = new ContributionOwner('kit', $name);
        $definition = $candidate->errors() === [] ? KitFiles::fingerprints($root, true) : [];
        $actions = [];
        foreach (array_unique([...array_keys($record['definition'] ?? []), ...array_keys($definition)]) as $path) {
            $before = $record['definition'][$path] ?? null;
            $after = $definition[$path] ?? null;
            if ($before === $after) { continue; }
            $kind = $before === null ? 'create' : ($after === null ? 'delete' : 'modify');
            $actions[] = new ChangeAction($kind, self::definitionSubject($name, $path), $owner,
                $before, $after, $kind === 'delete' ? 'destructive' : 'review', 'Update owned Kit definition.');
        }
        $published = $this->published($record);
        $packages = [];
        $packageSources = [];
        if (($record['enabled'] ?? false) === true && $candidate->manifest() !== null) {
            [$outputActions, $published, $outputConflicts] = $this->publicationPlan(
                $candidate, $root, $this->published($record), true);
            $actions = [...$actions, ...$outputActions];
            $conflicts = [...$conflicts, ...$outputConflicts];
            [$removedActions, $removedConflicts] = $this->removedPublicationPlan(
                $name, $this->published($record), $published);
            $actions = [...$actions, ...$removedActions];
            $conflicts = [...$conflicts, ...$removedConflicts];
        }
        if (($record['enabled'] ?? false) === true && $candidate->manifest() !== null) {
            [$packages, $packageConflicts, $packageSources] =
                $this->packagesToEnable($candidate->requires());
            $conflicts = [...$conflicts, ...$packageConflicts];
            foreach ($packages as $package) {
                $actions[] = new ChangeAction('state', 'Project/Packages/' . $package . '#activation',
                    $owner, null, null, 'review', 'Enable newly required Package in the activation registry.');
            }
        }
        $records = $this->state->read();
        if (isset($records[$name])) {
            $records[$name] = self::record((bool) $record['enabled'], 'local', $label,
                $definition, $published, $candidate->requires());
        }
        $actions[] = $this->stateAction($name, $current->status(), $current->status(), $records, $packages);
        $actions[] = new ChangeAction('modify', self::directorySubject($name), $owner,
            $this->definitionFingerprint($current->path()), self::sourceFingerprint($definition),
            'review', 'Replace reviewed Kit definition tree.');
        $added = array_values(array_diff($candidate->requires(), $current->requires()));
        $removed = array_values(array_diff($current->requires(), $candidate->requires()));
        $warnings = [...self::hookWarnings($candidate, 'upgrade'),
            'Migration and Seeder execution: NOT INCLUDED.'];
        if (($record['enabled'] ?? false) !== true) {
            $warnings[] = 'Disabled Kit upgrade changes definition only; published application files remain '
                . 'until a later reviewed enable.';
        }
        foreach ($added as $dependency) { $warnings[] = 'Added Package requirement: ' . $dependency . '.'; }
        foreach ($removed as $dependency) {
            $warnings[] = 'Removed Package requirement: ' . $dependency
                . '; Package activation remains independently managed.';
        }
        return $this->reviewPlan('upgrade', $name, $actions, $warnings, $conflicts,
            $source, $definition, $published, $packageSources);
    }

    public function planRemove(string $name): ChangePlan
    {
        $descriptor = $this->requireDescriptor($name);
        $record = $descriptor->record();
        $conflicts = $descriptor->errors();
        if ($record === null || $descriptor->sourceKind() === 'manual') {
            $conflicts[] = 'Manually imported Kit definition cannot be removed as managed source.';
        } else {
            $conflicts = [...$conflicts, ...$this->definitionConflicts($descriptor)];
        }
        $owner = new ContributionOwner('kit', $name);
        $actions = [];
        foreach ($this->published($record) as $path => $item) {
            if ($item['kind'] === 'migration') {
                $conflicts[] = 'Kit-owned Migration must be preserved: ' . $path . '.';
                continue;
            }
            try {
                $actual = KitFiles::fingerprint($this->app->basePath(), $name, $path);
            } catch (KitException) {
                $actual = null;
            }
            if ($actual !== $item['hash']) {
                $conflicts[] = 'Modified or missing Kit-owned file: ' . $path . '.';
                continue;
            }
            $actions[] = new ChangeAction('delete', $path, $owner, $item['hash'], null,
                'destructive', 'Remove an unchanged Kit-owned application file.');
        }
        foreach ($record['definition'] ?? [] as $path => $hash) {
            $actions[] = new ChangeAction('delete', self::definitionSubject($name, $path),
                $owner, $hash, null, 'destructive', 'Remove owned Kit source.');
        }
        if (is_dir($descriptor->path())) {
            $actions[] = new ChangeAction('delete', self::directorySubject($name), $owner,
                $this->definitionFingerprint($descriptor->path()), null, 'destructive',
                'Remove managed Kit definition directory.');
        }
        $records = $this->state->read();
        unset($records[$name]);
        $actions[] = $this->stateAction($name, $descriptor->status(), 'not installed', $records);
        return $this->reviewPlan('remove', $name, $actions,
            [...self::hookWarnings($descriptor, 'remove'),
                'Required Packages remain installed and enabled; Migrations are not rolled back.'],
            $conflicts);
    }

    /** Apply only this manager's still-current, conflict-free review plan. */
    public function apply(ChangePlan $plan): ChangeResult
    {
        $inputs = $this->planInputs[$plan] ?? null;
        if ($inputs === null || $plan->hasConflicts()) {
            throw new KitException('Kit plan is invalid or has unresolved conflicts.');
        }
        $this->assertCurrent($plan, $inputs);
        // One registry lock covers Package and Kit writers. Kit enablement
        // commits reviewed Package flags and its own state together below.
        return $this->activation->withLock(function () use ($plan, $inputs): ChangeResult {
            $this->assertCurrent($plan, $inputs);
            $this->ensureDirectory();
            return $this->applyLocked($plan, $inputs);
        });
    }

    /**
     * Source and publication bytes are prepared before any framework-managed
     * write. Hooks remain unsandboxed trusted code outside this guarantee.
     *
     * @param array<string,mixed> $inputs
     */
    private function applyLocked(ChangePlan $plan, array $inputs): ChangeResult
    {
        $operation = $inputs['operation'];
        $name = $inputs['name'];
        $descriptor = $this->descriptors()[$name] ?? null;
        $candidateRoot = $inputs['source'] === null ? null : $this->localSource($inputs['source'])[0];
        $sourceRoot = in_array($operation, ['install', 'upgrade'], true)
            ? $candidateRoot : $descriptor?->path();
        $bytes = $this->preparePublicationBytes($plan, $inputs, $sourceRoot);
        $stage = null;
        $backup = null;
        $recoveryPath = null;
        try {
            if (in_array($operation, ['install', 'upgrade'], true)) {
                if ($candidateRoot === null) { throw new KitException('Kit source is unavailable.'); }
                $stage = $this->stageDefinition($name, $candidateRoot, $inputs['definition']);
            }
            $hook = $this->hookDescriptor($operation, $descriptor, $sourceRoot, $name);
            if ($plan->actions !== []) { $this->runHook($hook, 'before' . ucfirst($operation)); }

            if ($operation === 'install') {
                $this->publishDefinition($name, $stage);
                $stage = null;
            } elseif ($operation === 'upgrade') {
                // The candidate has already been copied to a private staging
                // tree. Switch Kit source only after output bytes are ready.
                $backup = $this->swapDefinition($name, $stage);
                $stage = null;
            }
            $this->applyPublishedActions($plan, $bytes);
            if ($operation === 'remove') {
                $backup = $this->moveDefinitionForRemoval($name);
                // afterRemove must execute from the verified private recovery
                // tree after the public Kit definition has been detached.
                $hook = [$descriptor, $backup];
            }
            $this->writePlannedState($operation, $name, $plan, $inputs);
            if ($plan->actions !== []) {
                $this->runHook($hook, 'after' . ucfirst($operation),
                    $operation === 'remove' ? $descriptor?->path() : null);
            }

            if ($backup !== null) {
                try {
                    PackageFiles::removeTemporary($backup, $this->directory());
                } catch (PackageException) {
                    $recoveryPath = 'Project/Kits/' . basename($backup);
                }
                $backup = null;
            }
            return $this->verifyApplied($plan, $recoveryPath);
        } catch (Throwable $exception) {
            if ($backup !== null) {
                $recoveryPath = 'Project/Kits/' . basename($backup);
            }
            $result = $this->observedResult($plan, $recoveryPath);
            if ($result->applied !== [] || $recoveryPath !== null) {
                throw new ChangeApplyException($result, $exception);
            }
            if ($exception instanceof KitException) { throw $exception; }
            throw new KitException('Kit lifecycle apply failed before managed changes began.', 0, $exception);
        } finally {
            if ($stage !== null && is_dir($stage)) {
                try { PackageFiles::removeTemporary($stage, $this->directory()); }
                catch (PackageException) { /* A staging artifact is reported by the next inspection. */ }
            }
        }
    }

    /** @param array<string,mixed> $inputs @return array<string,string> */
    private function preparePublicationBytes(ChangePlan $plan, array $inputs, ?string $sourceRoot): array
    {
        $bytes = [];
        $total = 0;
        foreach ($plan->actions as $action) {
            if (!in_array($action->kind, ['create', 'modify'], true)
                || !isset($inputs['published'][$action->subject])) {
                continue;
            }
            $entry = $inputs['published'][$action->subject];
            if ($sourceRoot === null) { throw new KitException('Kit publication source is unavailable.'); }
            $content = KitFiles::sourceBytes($sourceRoot, $entry['source'], $entry['hash']);
            $total += strlen($content);
            if ($total > 33554432) {
                throw new KitException('Kit publication exceeds the operation size limit.');
            }
            $bytes[$action->subject] = $content;
        }
        return $bytes;
    }

    /** @param array<string,string> $definition */
    private function stageDefinition(string $name, string $source, array $definition): string
    {
        if (KitFiles::fingerprints($source, true) !== $definition) {
            throw new KitException('Kit source changed after review.');
        }
        $stage = $this->directory() . '/.' . $name . '.stage-' . bin2hex(random_bytes(8));
        try {
            PackageFiles::copy($source, $stage, $definition);
        } catch (PackageException $exception) {
            // PackageFiles may have created part of the private staging tree
            // before a source race or disk failure interrupted the copy.
            if (is_dir($stage)) {
                try { PackageFiles::removeTemporary($stage, $this->directory()); }
                catch (PackageException) { /* Leave the inert stage for manual recovery. */ }
            }
            throw new KitException('Kit source could not be staged.', 0, $exception);
        }
        return $stage;
    }

    private function publishDefinition(string $name, ?string $stage): void
    {
        if ($stage === null) { throw new KitException('Kit staging source is unavailable.'); }
        $target = $this->directory() . '/' . $name;
        if (file_exists($target) || is_link($target) || !@rename($stage, $target)) {
            throw new KitException('Kit destination changed during installation.');
        }
    }

    /** Return a private recovery tree until state/output verification succeeds. */
    private function swapDefinition(string $name, ?string $stage): string
    {
        if ($stage === null) { throw new KitException('Kit staging source is unavailable.'); }
        $target = $this->directory() . '/' . $name;
        $backup = $this->directory() . '/.' . $name . '.backup-' . bin2hex(random_bytes(8));
        if (!@rename($target, $backup)) {
            throw new KitException('Kit definition could not be prepared for upgrade.');
        }
        if (!@rename($stage, $target)) {
            if (!@rename($backup, $target)) {
                throw new KitException('Kit definition remains in a recovery directory.');
            }
            throw new KitException('Kit definition could not be upgraded.');
        }
        return $backup;
    }

    private function moveDefinitionForRemoval(string $name): string
    {
        $target = $this->directory() . '/' . $name;
        $backup = $this->directory() . '/.' . $name . '.remove-' . bin2hex(random_bytes(8));
        if (!@rename($target, $backup)) {
            throw new KitException('Kit definition could not be prepared for removal.');
        }
        return $backup;
    }

    private function applyPublishedActions(ChangePlan $plan, array $bytes): void
    {
        foreach ($plan->actions as $action) {
            $path = $action->subject;
            if (str_starts_with($path, 'Project/Kits/') || str_starts_with($path, 'Project/Packages/')
                || $path === 'Project/Activation.json') {
                continue;
            }
            if (in_array($action->kind, ['create', 'modify'], true)) {
                if (!isset($bytes[$path])) { throw new KitException('Kit publication bytes are unavailable.'); }
                KitFiles::publish($this->app->basePath(), $plan->target, $path,
                    $bytes[$path], $action->before);
            } elseif ($action->kind === 'delete' && $action->before !== null) {
                KitFiles::delete($this->app->basePath(), $plan->target, $path, $action->before);
            }
        }
    }

    /** @param array<string,mixed> $inputs */
    private function writePlannedState(string $operation, string $name,
        ChangePlan $plan, array $inputs): void
    {
        $stateAction = null;
        foreach ($plan->actions as $action) {
            if ($action->kind === 'state' && $action->subject === 'Project/Activation.json') {
                $stateAction = $action;
            }
        }
        if ($stateAction === null) { return; }
        $records = $this->state->read();
        if ($operation === 'install') {
            $root = $this->directory() . '/' . $name;
            $manifest = KitManifest::read($root . '/kit.json', $name);
            $label = $this->localSource($inputs['source'])[2];
            $records[$name] = self::record(false, 'local', $label, $inputs['definition'], [],
                $manifest->requires);
        } elseif ($operation === 'enable') {
            $record = $records[$name] ?? self::record(false, 'manual', 'manual', [], [], []);
            $manifest = KitManifest::read($this->directory() . '/' . $name . '/kit.json', $name);
            $records[$name] = self::record(true, $record['source_kind'], $record['source'],
                $record['definition'], $inputs['published'], $manifest->requires);
        } elseif ($operation === 'disable') {
            if (isset($records[$name])) { $records[$name]['enabled'] = false; }
        } elseif ($operation === 'upgrade') {
            $manifest = KitManifest::read($this->directory() . '/' . $name . '/kit.json', $name);
            $label = $this->localSource($inputs['source'])[2];
            $records[$name] = self::record((bool) ($records[$name]['enabled'] ?? false),
                'local', $label, $inputs['definition'], $inputs['published'], $manifest->requires);
        } elseif ($operation === 'remove') {
            unset($records[$name]);
        }
        $packages = $this->packageRecordsWithEnabled(self::packageActivationNames($plan));
        if ($this->activation->fingerprintWithState($packages, $records) !== $stateAction->after) {
            throw new KitException('Activation registry changed after review.');
        }
        // Publication and hooks may have external effects, but framework
        // Package and Kit activation flags become durable in one JSON replace.
        $this->activation->writeCombined($packages, $records);
    }

    /** @param array<string,mixed> $inputs */
    private function assertCurrent(ChangePlan $plan, array $inputs): void
    {
        if ($this->activation->currentFingerprint() !== $inputs['registry_state']) {
            throw new KitException('Kit plan is stale; activation registry changed after review.');
        }
        foreach ($plan->preconditions as $subject => $expected) {
            if ($subject === 'Project/Activation.json') { continue; }
            if ($this->subjectFingerprint($plan->target, $subject) !== $expected) {
                throw new KitException('Kit plan is stale; a reviewed file changed.');
            }
        }
        $fresh = match ($inputs['operation']) {
            'install' => $this->planInstall($inputs['source'] ?? ''),
            'enable' => $this->planEnable($inputs['name']),
            'disable' => $this->planDisable($inputs['name']),
            'upgrade' => $this->planUpgrade($inputs['name'], $inputs['source'] ?? ''),
            'remove' => $this->planRemove($inputs['name']),
            default => throw new KitException('Unknown Kit lifecycle operation.'),
        };
        if ($fresh->hasConflicts() || $fresh->fingerprint() !== $plan->fingerprint()
            || $this->planInputs[$fresh]['definition'] !== $inputs['definition']
            || $this->planInputs[$fresh]['published'] !== $inputs['published']) {
            throw new KitException('Kit plan is stale; review the operation again.');
        }
    }

    /** Read only the current bytes or state named by a portable plan action. */
    private function subjectFingerprint(string $name, string $subject): ?string
    {
        if ($subject === 'Project/Activation.json') { return $this->activation->currentFingerprint(); }
        if (preg_match('~\AProject/Packages/([A-Z][A-Za-z0-9_]*)#source\z~D',
            $subject, $match) === 1) {
            try {
                return PackageSnapshot::sourceFingerprint(
                    $this->app->basePath('Project/Packages/' . $match[1]));
            } catch (PackageException) {
                return null;
            }
        }
        if (str_starts_with($subject, 'Project/Packages/') && str_ends_with($subject, '#activation')) {
            return null;
        }
        $prefix = self::directorySubject($name);
        if ($subject === $prefix) { return $this->definitionFingerprint($this->directory() . '/' . $name); }
        if (str_starts_with($subject, $prefix . '/')) {
            $relative = substr($subject, strlen($prefix) + 1);
            KitManifest::requireRelative($relative);
            $path = $this->directory() . '/' . $name . '/' . $relative;
            if (!file_exists($path) && !is_link($path)) { return null; }
            try {
                PackageFiles::assertPhysical($path);
            } catch (PackageException $exception) {
                throw new KitException('Kit definition file is unsafe.', 0, $exception);
            }
            if (!is_file($path)) { throw new KitException('Kit definition target is not a file.'); }
            $hash = @hash_file('sha256', $path);
            if ($hash === false) { throw new KitException('Kit definition cannot be fingerprinted.'); }
            return $hash;
        }
        return KitFiles::fingerprint($this->app->basePath(), $name, $subject);
    }

    private function verifyApplied(ChangePlan $plan, ?string $recoveryPath): ChangeResult
    {
        $applied = [];
        foreach ($plan->actions as $index => $action) {
            if (self::packageActivationName($action) !== null) {
                $achieved = $this->registry->package(self::packageActivationName($action))?->status()
                    === 'enabled';
            } else {
                $achieved = $this->subjectFingerprint($plan->target, $action->subject) === $action->after;
            }
            if (!$achieved) {
                return new ChangeResult($plan, $applied, $action,
                    array_slice($plan->actions, $index + 1), false, $recoveryPath);
            }
            $applied[] = $action;
        }
        return new ChangeResult($plan, $applied, null, [], true, $recoveryPath);
    }

    private function observedResult(ChangePlan $plan, ?string $recoveryPath): ChangeResult
    {
        $applied = [];
        $unapplied = [];
        $failed = null;
        foreach ($plan->actions as $action) {
            try {
                $package = self::packageActivationName($action);
                if ($package !== null) {
                    $after = $this->registry->package($package)?->status() === 'enabled';
                    $before = !$after;
                } else {
                    $actual = $this->subjectFingerprint($plan->target, $action->subject);
                    $after = $actual === $action->after;
                    $before = $actual === $action->before;
                }
            } catch (Throwable) {
                $after = false;
                $before = false;
            }
            if ($after && (!$before || $action->before !== $action->after)) {
                $applied[] = $action;
            } elseif ($before) {
                $unapplied[] = $action;
            } elseif ($failed === null) {
                $failed = $action;
            } else {
                $unapplied[] = $action;
            }
        }
        return new ChangeResult($plan, $applied, $failed, $unapplied, false, $recoveryPath);
    }

    private static function packageActivationName(ChangeAction $action): ?string
    {
        if ($action->kind !== 'state') { return null; }
        return preg_match('~\AProject/Packages/([A-Z][A-Za-z0-9_]*)#activation\z~D',
            $action->subject, $match) === 1 ? $match[1] : null;
    }

    private function ensureDirectory(): void
    {
        $directory = $this->directory();
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new KitException('Kit directory could not be created.');
        }
        try {
            PackageFiles::assertPhysical($directory);
        } catch (PackageException $exception) {
            throw new KitException('Kit directory is unsafe.', 0, $exception);
        }
    }

    /** @return array{KitDescriptor|null,string|null} */
    private function hookDescriptor(string $operation, ?KitDescriptor $current,
        ?string $sourceRoot, string $name): array
    {
        if (in_array($operation, ['install', 'upgrade'], true)) {
            if ($sourceRoot === null) { return [null, null]; }
            $candidate = $this->discovery->inspect($sourceRoot, $name, null, true);
            return [$candidate, $sourceRoot];
        }
        return [$current, $current?->path()];
    }

    /**
     * Lifecycle hooks are trusted executable PHP, not a sandbox. The manifest
     * declares which fixed methods may run; preview never enters this path.
     *
     * @param array{KitDescriptor|null,string|null} $source
     */
    private function runHook(array $source, string $hook, ?string $movedFrom = null): void
    {
        [$descriptor, $root] = $source;
        if ($descriptor === null || $root === null || !in_array($hook, $descriptor->hooks(), true)) {
            return;
        }
        $name = $descriptor->name();
        $class = 'Project\\Kits\\' . $name . '\\' . $name;
        $entry = $root . '/' . $name . '.php';
        if (!KitEntry::valid($entry, $name)) {
            throw new KitException('Kit lifecycle entry changed after review.');
        }
        try {
            if (class_exists($class, false)) {
                $loaded = (new ReflectionClass($class))->getFileName();
                $entryHash = @hash_file('sha256', $entry);
                if (!is_string($entryHash)) {
                    throw new KitException('Kit lifecycle entry cannot be fingerprinted.');
                }
                $sameFile = is_string($loaded) && @hash_file('sha256', $loaded) === $entryHash;
                // beforeRemove may already have loaded this exact class. Its
                // original path disappears when the source moves to recovery;
                // the reviewed entry hash and original path prove continuity.
                $sameMovedClass = $hook === 'afterRemove' && $movedFrom !== null
                    && is_string($loaded)
                    && str_replace('\\', '/', $loaded) === str_replace('\\', '/', $movedFrom . '/' . $name . '.php')
                    && ($descriptor->record()['definition'][$name . '.php'] ?? null) === $entryHash;
                if (!$sameFile && !$sameMovedClass) {
                    throw new KitException('Kit lifecycle class is already loaded from different source.');
                }
            } else {
                require_once $entry;
            }
            if (!is_subclass_of($class, \App\Plugins\Kit::class)) {
                throw new KitException('Kit lifecycle class is incompatible.');
            }
            $kit = $this->app->container()->make($class);
            if (!$kit instanceof Kit) { throw new KitException('Kit lifecycle class did not resolve.'); }
            // Hook names are framework-declared identifiers, never input-selected methods.
            $operation = lcfirst(substr($hook, str_starts_with($hook, 'before') ? 6 : 5));
            $kit->{$hook}(new KitContext($operation, $name, $descriptor->version() ?? '0.0.0'));
        } catch (Throwable $exception) {
            throw new KitException('Kit lifecycle hook failed.', 0, $exception);
        }
    }

    /** @return array<string,KitDescriptor> */
    private function descriptors(): array
    {
        // The shared registry derives observed composition status. This
        // manager retains Kit publication and lifecycle ownership only.
        return $this->registry->kits();
    }

    private function requireDescriptor(string $name): KitDescriptor
    {
        KitName::require($name);
        $descriptor = $this->descriptors()[$name] ?? null;
        if ($descriptor === null) { throw new KitException('Kit is not installed.'); }
        return $descriptor;
    }

    /** @param array<string,mixed>|null $record @return array<string,array{hash:string,kind:string}> */
    private function published(?array $record): array
    {
        $published = is_array($record['published'] ?? null) ? $record['published'] : [];
        ksort($published, SORT_STRING);
        return $published;
    }

    /** @return array<string,mixed> */
    private function recordFor(KitDescriptor $descriptor): array
    {
        return $descriptor->record() ?? self::record(false, 'manual', 'manual', [], [],
            $descriptor->requires());
    }

    /**
     * @param array<string,string> $definition
     * @param array<string,array<string,string>> $published
     * @param list<string> $requires
     * @return array<string,mixed>
     */
    private static function record(bool $enabled, string $sourceKind, string $source,
        array $definition, array $published, array $requires): array
    {
        $owned = [];
        foreach ($published as $path => $item) {
            $owned[$path] = ['hash' => $item['hash'], 'kind' => $item['kind']];
        }
        ksort($definition, SORT_STRING);
        ksort($owned, SORT_STRING);
        sort($requires, SORT_STRING);
        return ['enabled' => $enabled, 'source_kind' => $sourceKind, 'source' => $source,
            'definition' => $definition, 'published' => $owned,
            'requires' => $requires];
    }

    /**
     * A Kit plan describes the final shared state, including Package flags
     * shown as separate review actions before Kit activation.
     *
     * @param array<string,array<string,mixed>> $records
     * @param list<string> $packagesToEnable
     */
    private function stateAction(string $name, string $from, string $to, array $records,
        array $packagesToEnable = []): ChangeAction
    {
        return new ChangeAction('state', 'Project/Activation.json',
            new ContributionOwner('kit', $name), $this->activation->currentFingerprint(),
            $this->activation->fingerprintWithState(
                $this->packageRecordsWithEnabled($packagesToEnable), $records),
            $to === 'not installed' ? 'destructive' : 'review',
            $name . ': ' . $from . ' to ' . $to . '.');
    }

    /** @param list<string> $names @return array<string,array<string,mixed>> */
    private function packageRecordsWithEnabled(array $names): array
    {
        $records = $this->activation->packages();
        foreach ($names as $name) {
            $record = $records[$name] ?? [
                'enabled' => false, 'source_kind' => 'manual',
                'source' => 'manual', 'files' => (object) [],
            ];
            $record['enabled'] = true;
            $records[$name] = $record;
        }
        return $records;
    }

    /** @return list<string> */
    private static function packageActivationNames(ChangePlan $plan): array
    {
        $names = [];
        foreach ($plan->actions as $action) {
            $name = self::packageActivationName($action);
            if ($name !== null) { $names[] = $name; }
        }
        return $names;
    }

    private static function directorySubject(string $name): string
    {
        return 'Project/Kits/' . $name;
    }

    private static function definitionSubject(string $name, string $path): string
    {
        return self::directorySubject($name) . '/' . $path;
    }

    /** @param array<string,string> $files */
    private static function sourceFingerprint(array $files): string
    {
        ksort($files, SORT_STRING);
        try {
            return hash('sha256', json_encode($files, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } catch (JsonException $exception) {
            throw new KitException('Kit source could not be fingerprinted.', 0, $exception);
        }
    }

    private function definitionFingerprint(string $path): ?string
    {
        if (!is_dir($path) && !is_link($path)) { return null; }
        return self::sourceFingerprint(KitFiles::fingerprints($path));
    }

    /** @return list<string> */
    private function definitionConflicts(KitDescriptor $descriptor): array
    {
        $record = $descriptor->record();
        if ($record === null || $descriptor->sourceKind() === 'manual') { return []; }
        try {
            $actual = KitFiles::fingerprints($descriptor->path());
        } catch (KitException) {
            return ['Kit definition files are unsafe or unreadable.'];
        }
        $owned = is_array($record['definition'] ?? null) ? $record['definition'] : [];
        $conflicts = [];
        foreach ($owned as $path => $hash) {
            if (($actual[$path] ?? null) !== $hash) {
                $conflicts[] = 'Modified or missing Kit definition file: ' . $path . '.';
            }
        }
        foreach ($actual as $path => $hash) {
            if (!array_key_exists($path, $owned)) {
                $conflicts[] = 'Untracked Kit definition file: ' . $path . '.';
            }
        }
        return $conflicts;
    }

    /**
     * @return array{string,string,string} Physical root, exact name, safe source label.
     */
    private function localSource(string $source): array
    {
        if ($source === '' || strlen($source) > 4096
            || preg_match('/[\x00-\x1F\x7F]/', $source) === 1
            || str_contains($source, '://') || is_link($source)) {
            throw new KitException('Kit source must be a safe local directory.');
        }
        $root = realpath($source);
        if ($root === false || !is_dir($root)) {
            throw new KitException('Local Kit source is unavailable.');
        }
        try {
            PackageFiles::assertPhysical($root);
        } catch (PackageException $exception) {
            throw new KitException('Local Kit source is unsafe.', 0, $exception);
        }
        $name = basename(str_replace('\\', '/', $root));
        KitName::require($name);
        $definition = KitFiles::fingerprints($root, true);
        $label = 'local:' . $name . '#' . substr(self::sourceFingerprint($definition), 0, 12);
        return [$root, $name, $label];
    }

    /**
     * Reuse PackageManager's validated dependency plans rather than keeping
     * a second topological sort. Kit review exposes each required transition;
     * apply commits those flags with the Kit in one registry replacement.
     *
     * @param list<string> $requires
     * @return array{list<string>,list<string>,array<string,string>}
     */
    private function packagesToEnable(array $requires): array
    {
        $manager = $this->app->container()->make(PackageManager::class);
        $order = [];
        $conflicts = [];
        $sources = [];
        $registryState = $this->activation->currentFingerprint();
        foreach ($requires as $name) {
            try {
                $plan = $manager->planEnable($name);
            } catch (PackageException) {
                $conflicts[] = 'Required Package ' . $name . ' cannot be activated.';
                continue;
            }
            if (($plan->preconditions['Project/Activation.json'] ?? null) !== $registryState) {
                $conflicts[] = 'Activation registry changed while planning Kit requirements.';
            }
            foreach ($plan->conflicts as $conflict) { $conflicts[] = $conflict; }
            foreach ($plan->preconditions as $subject => $fingerprint) {
                if (!str_ends_with($subject, '#source') || $fingerprint === null) { continue; }
                if (isset($sources[$subject]) && $sources[$subject] !== $fingerprint) {
                    $conflicts[] = 'Required Package source changed while planning.';
                }
                $sources[$subject] = $fingerprint;
            }
            foreach ($plan->actions as $action) {
                if ($action->kind === 'state' && $action->owner->type === 'package'
                    && $action->subject === 'Project/Activation.json') {
                    $order[$action->owner->name] = true;
                }
            }
        }
        if ($this->activation->currentFingerprint() !== $registryState) {
            $conflicts[] = 'Activation registry changed while planning Kit requirements.';
        }
        return [array_keys($order), array_values(array_unique($conflicts)), $sources];
    }

    /**
     * @param array<string,array{hash:string,kind:string}> $old
     * @return array{list<ChangeAction>,array<string,array{source:string,hash:string,kind:string}>,list<string>}
     */
    private function publicationPlan(KitDescriptor $descriptor, string $sourceRoot,
        array $old, bool $upgrade = false): array
    {
        $actions = [];
        $published = [];
        $conflicts = [];
        $owner = new ContributionOwner('kit', $descriptor->name());
        $definition = KitFiles::fingerprints($sourceRoot, true);
        foreach ($descriptor->manifest()?->files ?? [] as $mapping) {
            $path = $mapping['target'];
            $source = $mapping['source'];
            $hash = $definition[$source] ?? null;
            if ($hash === null) {
                $conflicts[] = 'Kit mapping source is missing: ' . $source . '.';
                continue;
            }
            try {
                $actual = KitFiles::fingerprint($this->app->basePath(), $descriptor->name(), $path);
            } catch (KitException $exception) {
                $conflicts[] = $path . ': unsafe or case-conflicting target.';
                continue;
            }
            $owned = $old[$path] ?? null;
            if ($owned !== null && $actual !== $owned['hash']) {
                $conflicts[] = 'Modified or missing Kit-owned file: ' . $path . '.';
                continue;
            }
            if ($owned === null && $actual !== null) {
                $conflicts[] = 'Application file already exists: ' . $path . '.';
                continue;
            }
            if ($owned !== null && $owned['kind'] !== $mapping['kind']) {
                $conflicts[] = 'Kit-owned file type changed: ' . $path . '.';
                continue;
            }
            if ($owned !== null && $owned['hash'] !== $hash && $mapping['kind'] === 'migration') {
                $conflicts[] = 'Kit-owned Migration must be preserved: ' . $path . '.';
                continue;
            }
            $published[$path] = ['source' => $source, 'hash' => $hash, 'kind' => $mapping['kind']];
            if ($actual === $hash) { continue; }
            $kind = $actual === null ? 'create' : 'modify';
            $actions[] = new ChangeAction($kind, $path, $owner, $actual, $hash,
                $kind === 'create' ? 'low' : 'review',
                $mapping['kind'] === 'migration'
                    ? 'Publish Migration file; execution is separate.' : 'Publish Kit application file.');
        }
        ksort($published, SORT_STRING);
        return [$actions, $published, $conflicts];
    }

    /**
     * An old output removed from the manifest is deletable only while its
     * fingerprint still proves ownership. Migration history is immutable here.
     *
     * @param array<string,array{hash:string,kind:string}> $old
     * @param array<string,array<string,string>> $next
     * @return array{list<ChangeAction>,list<string>}
     */
    private function removedPublicationPlan(string $name, array $old, array $next): array
    {
        $actions = [];
        $conflicts = [];
        $owner = new ContributionOwner('kit', $name);
        foreach ($old as $path => $item) {
            if (isset($next[$path])) { continue; }
            if ($item['kind'] === 'migration') {
                $conflicts[] = 'Kit-owned Migration must be preserved: ' . $path . '.';
                continue;
            }
            try {
                $actual = KitFiles::fingerprint($this->app->basePath(), $name, $path);
            } catch (KitException) {
                $actual = null;
            }
            if ($actual !== $item['hash']) {
                $conflicts[] = 'Modified or missing Kit-owned file: ' . $path . '.';
                continue;
            }
            $actions[] = new ChangeAction('delete', $path, $owner, $item['hash'], null,
                'destructive', 'Remove unchanged Kit output no longer declared.');
        }
        return [$actions, $conflicts];
    }

    /** @return list<string> */
    private static function hookWarnings(KitDescriptor $descriptor, string $operation): array
    {
        $warnings = [];
        foreach (['before' . ucfirst($operation), 'after' . ucfirst($operation)] as $hook) {
            if (in_array($hook, $descriptor->hooks(), true)) {
                $warnings[] = 'Lifecycle hook ' . $hook . ' is trusted executable PHP; '
                    . 'NOT INCLUDED IN PREVIEW and runs only during apply.';
            }
        }
        return $warnings;
    }

    /**
     * Keep source paths and prepared file bytes private to this manager.
     *
     * @param list<ChangeAction> $actions
     * @param list<string> $warnings
     * @param list<string> $conflicts
     * @param array<string,string> $definition
     * @param array<string,array{source:string,hash:string,kind:string}> $published
     * @param array<string,string> $packageSources
     */
    private function reviewPlan(string $operation, string $name, array $actions,
        array $warnings, array $conflicts, ?string $source = null,
        array $definition = [], array $published = [], array $packageSources = []): ChangePlan
    {
        $registryState = $this->activation->currentFingerprint();
        $preconditions = ['Project/Activation.json' => $registryState];
        foreach ($actions as $action) {
            if ($action->kind === 'state') { continue; }
            $preconditions[$action->subject] = $action->before;
        }
        foreach ($packageSources as $subject => $fingerprint) {
            $preconditions[$subject] = $fingerprint;
        }
        ksort($preconditions, SORT_STRING);
        $warnings = array_values(array_unique($warnings));
        $conflicts = array_values(array_unique($conflicts));
        sort($warnings, SORT_STRING);
        sort($conflicts, SORT_STRING);
        $plan = new ChangePlan('kit:' . $operation, $name,
            new ContributionOwner('kit', $name), $actions, $warnings, $conflicts, $preconditions);
        $this->planInputs[$plan] = [
            'operation' => $operation, 'name' => $name, 'source' => $source,
            'definition' => $definition, 'published' => $published,
            'registry_state' => $registryState,
        ];
        return $plan;
    }

    private function directory(): string
    {
        return $this->app->basePath('Project/Kits');
    }
}
