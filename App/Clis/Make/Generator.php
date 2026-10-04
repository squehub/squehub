<?php

declare(strict_types=1);

namespace App\Clis\Make;

use App\Changes\ChangeAction;
use App\Changes\ChangeApplyException;
use App\Changes\ChangePlan;
use App\Changes\ChangeResult;
use App\Contributions\ContributionOwner;
use App\Foundation\Application;
use App\Packages\PackageException;
use App\Packages\PackageFiles;
use App\Packages\PackageManager;
use App\Packages\PackageStateStore;
use DateTimeImmutable;
use DateTimeZone;
use WeakMap;

/** Plans one canonical application file and publishes it without replacing an existing file. */
final class Generator
{
    private const CLASS_DIRECTORIES = [
        'controller' => 'Controllers',
        'middleware' => 'Middleware',
        'model' => 'Models',
    ];

    private const RESERVED = [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch',
        'class', 'clone', 'const', 'continue', 'declare', 'default', 'do', 'echo',
        'else', 'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach', 'endif',
        'endswitch', 'endwhile', 'enum', 'eval', 'exit', 'extends', 'false', 'final',
        'finally', 'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if',
        'implements', 'include', 'instanceof', 'interface', 'isset', 'list',
        'match', 'namespace', 'new', 'null', 'or', 'parent', 'print', 'private',
        'protected', 'public', 'readonly', 'require', 'return', 'self', 'static',
        'switch', 'throw', 'trait', 'true', 'try', 'unset', 'use', 'var', 'while',
        'xor', 'yield', 'bool', 'float', 'int', 'iterable', 'mixed', 'never',
        'object', 'string', 'void',
    ];

    private string $root;
    private DateTimeImmutable $clock;

    /** @var WeakMap<ChangePlan, array{path:string,contents:string,package:?string,package_files:?array<string,string>}> */
    private WeakMap $plannedFiles;

    /** @var WeakMap<ChangePlan, array{files:array<string,string>,package:?string,package_files:?array<string,string>,package_state:?string,migration_class:?string}> */
    private WeakMap $plannedBatches;

    public function __construct(string $basePath, ?DateTimeImmutable $clock = null)
    {
        $root = realpath($basePath);
        if ($root === false || !is_dir($root)) {
            throw new GeneratorException('Application root was not found.');
        }
        $this->root = $root;
        $this->clock = ($clock ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        $this->plannedFiles = new WeakMap();
        $this->plannedBatches = new WeakMap();
    }

    /** Validate a singular Feature class through the existing PHP identifier rules. */
    public function featureClassName(string $name): string
    {
        $parts = $this->classNameParts($name, false);
        if (count($parts) !== 1 || $parts[0] !== $name || strlen($name) > 100) {
            throw new GeneratorException('Feature name must be one capitalized PHP class name.');
        }
        return $name;
    }

    /** Reuse the dated filename and class mapping of make:migration. */
    public function migrationArtifact(string $name): array
    {
        [$class, $path, $table] = $this->migrationName($name);
        return [
            'class' => $class,
            'path' => $path,
            'contents' => GeneratorTemplates::render('migration', 'Database\\Migrations', $class, $table),
        ];
    }

    /**
     * Plan one composite source change. The public plan contains fingerprints,
     * while the prepared source remains private to this Generator instance.
     *
     * @param array<string,string> $files Application-relative path => complete source.
     * @param list<string> $warnings
     * @param list<string> $extraConflicts
     */
    public function planComposite(
        string $target,
        array $files,
        ?string $package = null,
        ?string $migrationClass = null,
        array $warnings = [],
        array $extraConflicts = []
    ): ChangePlan {
        if ($files === []) {
            throw new GeneratorException('Feature Blueprint needs source files.');
        }
        if ($package !== null) {
            $this->requirePackage($package);
        }
        $owner = $package === null
            ? new ContributionOwner('application', 'Project')
            : new ContributionOwner('package', $package);
        $actions = [];
        $preconditions = [];
        $conflicts = $extraConflicts;
        foreach ($files as $path => $contents) {
            if (!is_string($path) || !is_string($contents) || $contents === '') {
                throw new GeneratorException('Feature Blueprint source is invalid.');
            }
            try {
                $this->inspectTarget($path);
            } catch (GeneratorException $exception) {
                if (!in_array($exception->getMessage(), [
                    'Target already exists.', 'Unsafe target path casing.', 'Unsafe target path.',
                ], true)) {
                    throw $exception;
                }
                $conflicts[] = $path . ': ' . $exception->getMessage();
            }
            $actions[] = new ChangeAction('create', $path, $owner, null,
                hash('sha256', $contents), 'low', 'Generate Feature source.');
            $preconditions[$path] = null;
        }
        if ($migrationClass !== null) {
            try {
                $this->rejectDuplicateMigrationClass($migrationClass);
            } catch (GeneratorException $exception) {
                if ($exception->getMessage() !== 'A migration with this class name already exists.') {
                    throw $exception;
                }
                $conflicts[] = $exception->getMessage();
            }
        }
        $packageFiles = $package === null ? null : $this->packageFiles($package);
        $packageState = $package === null ? null : $this->packageStateFingerprint();
        $plan = new ChangePlan('make:feature', $target, $owner, $actions,
            $warnings, array_values(array_unique($conflicts)), $preconditions);
        $this->plannedBatches[$plan] = [
            'files' => $files,
            'package' => $package,
            'package_files' => $packageFiles,
            'package_state' => $packageState,
            'migration_class' => $migrationClass,
        ];
        return $plan;
    }

    /**
     * Recheck all targets before the first write. Files are staged completely
     * and then published with create-if-absent links; any later failure removes
     * only source we can still prove this operation owns.
     */
    public function applyComposite(ChangePlan $plan): ChangeResult
    {
        $planned = $this->plannedBatches[$plan] ?? null;
        if ($planned === null || $plan->operation !== 'make:feature' || $plan->hasConflicts()
            || count($plan->actions) !== count($planned['files'])) {
            throw new GeneratorException('Feature Blueprint plan is invalid.');
        }
        foreach ($plan->actions as $action) {
            $contents = $planned['files'][$action->subject] ?? null;
            if ($action->kind !== 'create' || $action->before !== null || $contents === null
                || $action->after !== hash('sha256', $contents)
                || $action->owner->key() !== $plan->owner->key()) {
                throw new GeneratorException('Feature Blueprint plan is invalid.');
            }
        }
        if ($planned['package'] !== null) {
            $this->requirePackage($planned['package']);
            if ($this->packageFiles($planned['package']) !== $planned['package_files']
                || $this->packageStateFingerprint() !== $planned['package_state']) {
                throw new GeneratorException('Package ownership changed after review.');
            }
        }
        if ($planned['migration_class'] !== null) {
            // A new migration with a different timestamp can still claim this
            // class after planning. Recheck the canonical discovery set before
            // publishing any of the seven files.
            $this->rejectDuplicateMigrationClass($planned['migration_class']);
        }
        foreach (array_keys($planned['files']) as $path) {
            $this->inspectTarget($path);
        }

        $createdDirectories = [];
        $temporary = [];
        $published = [];
        try {
            foreach ($planned['files'] as $path => $contents) {
                $this->createParents($path, $createdDirectories);
                $target = $this->inspectTarget($path);
                $staged = @tempnam(dirname($target), '.squehub-make-');
                if ($staged === false) {
                    throw new GeneratorException('Feature source could not be prepared.');
                }
                $temporary[$path] = $staged;
                if (@file_put_contents($staged, $contents) !== strlen($contents)
                    || !@chmod($staged, 0666 & ~umask())) {
                    throw new GeneratorException('Feature source could not be prepared.');
                }
            }
            foreach ($temporary as $path => $staged) {
                $target = $this->inspectTarget($path);
                if (!@link($staged, $target)) {
                    throw new GeneratorException(file_exists($target) || is_link($target)
                        ? 'Target already exists.' : 'Feature source could not be published.');
                }
                $published[$path] = $target;
                if (@hash_file('sha256', $target) !== hash('sha256', $planned['files'][$path])) {
                    throw new GeneratorException('Feature source could not be verified.');
                }
            }
        } catch (\Throwable $exception) {
            $uncertain = null;
            $remaining = [];
            foreach (array_reverse($published, true) as $path => $target) {
                if (@hash_file('sha256', $target) !== hash('sha256', $planned['files'][$path])
                    || !@unlink($target)) {
                    $uncertain ??= $path;
                    $remaining[$path] = true;
                }
            }
            if ($uncertain !== null) {
                $applied = array_values(array_filter($plan->actions,
                    static fn (ChangeAction $action): bool => isset($remaining[$action->subject])));
                $unapplied = array_values(array_filter($plan->actions,
                    static fn (ChangeAction $action): bool => !isset($remaining[$action->subject])));
                throw new ChangeApplyException(new ChangeResult($plan, $applied, null,
                    $unapplied, false, $uncertain),
                    $exception);
            }
            throw $exception;
        } finally {
            foreach ($temporary as $staged) {
                if (is_file($staged)) { @unlink($staged); }
            }
            foreach (array_reverse(array_unique($createdDirectories)) as $directory) {
                @rmdir($directory);
            }
        }
        return new ChangeResult($plan, $plan->actions, null, [], true);
    }

    private function packageStateFingerprint(): ?string
    {
        try {
            return (new PackageStateStore($this->root . '/Project/Packages'))->currentFingerprint();
        } catch (PackageException $exception) {
            throw new GeneratorException('Package ownership could not be inspected.', 0, $exception);
        }
    }

    /** Validate and render once; the shared plan reveals fingerprints, not source contents. */
    public function plan(string $kind, string $name, ?string $package = null): ChangePlan
    {
        if (!isset(self::CLASS_DIRECTORIES[$kind]) && !in_array($kind, ['migration', 'seeder'], true)) {
            throw new GeneratorException('Unknown generator.');
        }
        if ($package !== null) {
            if (!isset(self::CLASS_DIRECTORIES[$kind])) {
                throw new GeneratorException('Package migrations and Seeders are not supported by the current runtime.');
            }
            $this->requirePackage($package);
        }

        $table = null;
        if ($kind === 'migration') {
            [$className, $relativePath, $table] = $this->migrationName($name);
            $namespace = 'Database\\Migrations';
        } else {
            $parts = $this->classNameParts($name, $kind !== 'seeder');
            $className = array_pop($parts);
            $subpath = $parts === [] ? '' : implode('/', $parts) . '/';
            if ($kind === 'seeder') {
                $relativePath = 'Database/Seeders/' . $className . '.php';
                $namespace = 'Database\\Seeders';
            } else {
                $directory = self::CLASS_DIRECTORIES[$kind];
                $base = $package === null ? 'Project' : 'Project/Packages/' . $package;
                $relativePath = $base . '/' . $directory . '/' . $subpath . $className . '.php';
                $namespace = str_replace('/', '\\', $base . '/' . $directory
                    . ($parts === [] ? '' : '/' . implode('/', $parts)));
            }
        }

        $conflicts = [];
        try {
            $this->inspectTarget($relativePath);
        } catch (GeneratorException $exception) {
            if (!in_array($exception->getMessage(), [
                'Target already exists.', 'Unsafe target path casing.', 'Unsafe target path.',
            ], true)) {
                throw $exception;
            }
            $conflicts[] = $exception->getMessage();
        }
        if ($kind === 'migration') {
            try {
                $this->rejectDuplicateMigrationClass($className);
            } catch (GeneratorException $exception) {
                if ($exception->getMessage() !== 'A migration with this class name already exists.') {
                    throw $exception;
                }
                $conflicts[] = 'A migration with this class name already exists.';
            }
        }

        $contents = GeneratorTemplates::render($kind, $namespace, $className, $table);
        $owner = $package === null
            ? new ContributionOwner('application', 'Project')
            : new ContributionOwner('package', $package);
        $packageFiles = $package === null ? null : $this->packageFiles($package);
        $plan = new ChangePlan(
            'make:' . $kind,
            $relativePath,
            $owner,
            [new ChangeAction('create', $relativePath, $owner, null,
                hash('sha256', $contents), 'low', 'Generate ' . $kind . ' source.')],
            [],
            $conflicts,
            [$relativePath => null],
        );
        // Keep the prepared source out of the plan's portable/rendered data.
        // The weak key also prevents one Generator instance retaining old plans.
        $this->plannedFiles[$plan] = [
            'path' => $relativePath,
            'contents' => $contents,
            'package' => $package,
            'package_files' => $packageFiles,
        ];
        return $plan;
    }

    /** Recheck identity, ownership, and absence before publishing complete source. */
    public function apply(ChangePlan $plan): ChangeResult
    {
        $planned = $this->plannedFiles[$plan] ?? null;
        if ($planned === null || $plan->hasConflicts() || count($plan->actions) !== 1) {
            throw new GeneratorException('Generator plan is invalid.');
        }
        $action = $plan->actions[0];
        if ($action->kind !== 'create' || $action->subject !== $planned['path']
            || $action->before !== null
            || $action->after !== hash('sha256', $planned['contents'])) {
            throw new GeneratorException('Generator plan is invalid.');
        }
        if ($planned['package'] !== null) {
            $this->requirePackage($planned['package']);
            if ($this->packageFiles($planned['package']) !== $planned['package_files']) {
                throw new GeneratorException('Package ownership changed after review.');
            }
        }
        // Inspect before creating parents; an appeared file or an unsafe path
        // leaves every target untouched. The hard link below closes the final
        // create-if-absent race against another local writer.
        $this->inspectTarget($planned['path']);

        $created = [];
        $temporary = null;
        try {
            $this->createParents($planned['path'], $created);
            $target = $this->inspectTarget($planned['path']);
            $temporary = @tempnam(dirname($target), '.squehub-make-');
            if ($temporary === false) {
                throw new GeneratorException('Generated file could not be prepared.');
            }
            if (@file_put_contents($temporary, $planned['contents']) !== strlen($planned['contents'])) {
                throw new GeneratorException('Generated file could not be written.');
            }
            // tempnam() starts with owner-only permissions on POSIX. Publish
            // normal source-file permissions while respecting the caller's umask.
            if (!@chmod($temporary, 0666 & ~umask())) {
                throw new GeneratorException('Generated file permissions could not be set.');
            }
            // A same-directory hard link is an atomic create-if-absent operation on
            // cooperating local filesystems; rename() could replace another file.
            if (!@link($temporary, $target)) {
                throw new GeneratorException(file_exists($target) || is_link($target)
                    ? 'Target already exists.' : 'Generated file could not be published.');
            }
            if (@hash_file('sha256', $target) !== $action->after) {
                // Publication already happened. Preserve the visible target for
                // manual inspection and report a structured partial outcome.
                throw new ChangeApplyException(new ChangeResult($plan, [], $action, [],
                    false, $planned['path']));
            }
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                @unlink($temporary);
            }
            if (!isset($target) || !is_file($target)) {
                foreach (array_reverse($created) as $directory) {
                    @rmdir($directory);
                }
            }
        }
        return new ChangeResult($plan, [$action], null, [], true);
    }

    /** @return array<string,string> */
    private function packageFiles(string $package): array
    {
        try {
            return PackageFiles::fingerprints($this->root . '/Project/Packages/' . $package);
        } catch (PackageException $exception) {
            throw new GeneratorException('Package ownership could not be inspected.', 0, $exception);
        }
    }

    /** @return list<string> */
    private function classNameParts(string $name, bool $nested): array
    {
        if ($name === '' || strlen($name) > 240 || str_contains($name, '..')
            || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw new GeneratorException('Invalid generator name.');
        }
        $parts = explode('/', str_replace('\\', '/', $name));
        if (!$nested && count($parts) !== 1) {
            throw new GeneratorException('Seeder names must be single class names.');
        }
        foreach ($parts as &$part) {
            if (preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/D', $part) !== 1
                || in_array(strtolower($part), self::RESERVED, true)
                || preg_match('/\A(?:con|prn|aux|nul|com[1-9]|lpt[1-9])\z/iD', $part) === 1) {
                throw new GeneratorException('Invalid generator name.');
            }
            $part = ucfirst($part);
        }
        unset($part);
        return $parts;
    }

    /** @return array{string,string,?string} */
    private function migrationName(string $name): array
    {
        if (strlen($name) > 120
            || preg_match('/\A[a-z][a-z0-9]*(?:_[a-z0-9]+)*\z/D', $name) !== 1) {
            throw new GeneratorException('Invalid migration name. Use snake_case.');
        }
        $className = implode('', array_map('ucfirst', explode('_', $name)));
        if (in_array(strtolower($className), self::RESERVED, true)) {
            throw new GeneratorException('Invalid migration name.');
        }
        $table = null;
        if (preg_match('/\Acreate_([a-z][a-z0-9]*(?:_[a-z0-9]+)*)_table\z/D', $name, $matches) === 1) {
            $table = $matches[1];
            if (strlen($table) > 64) {
                throw new GeneratorException('Migration table name is too long.');
            }
        }
        return [$className, 'Database/Migrations/' . $this->clock->format('Y_m_d_His')
            . '_' . $name . '.php', $table];
    }

    private function requirePackage(string $package): void
    {
        if (strlen($package) > 120
            || preg_match('/\A[A-Z][A-Za-z0-9_]*\z/D', $package) !== 1
            || in_array(strtolower($package), self::RESERVED, true)
            || preg_match('/\A(?:con|prn|aux|nul|com[1-9]|lpt[1-9])\z/iD', $package) === 1) {
            throw new GeneratorException('Invalid Package name. Use its exact canonical spelling.');
        }
        $relative = 'Project/Packages/' . $package;
        $current = $this->root;
        foreach (explode('/', $relative) as $part) {
            $this->assertExactChild($current, $part);
            $current .= DIRECTORY_SEPARATOR . $part;
            if (is_link($current) || !is_dir($current) || !$this->contained($current)) {
                throw new GeneratorException('Package ' . $package . ' was not found.');
            }
        }
        // A copied directory is not necessarily a usable Package. Reuse the
        // static lifecycle inspection without executing its entry PHP.
        try {
            $descriptors = (new PackageManager(new Application($this->root)))->list();
        } catch (PackageException $exception) {
            throw new GeneratorException('Package state could not be validated.', 0, $exception);
        }
        foreach ($descriptors as $descriptor) {
            if ($descriptor->name() === $package) {
                if ($descriptor->status() === 'broken') {
                    throw new GeneratorException('Package is invalid; repair its entry or dependencies first.');
                }
                return;
            }
        }
        throw new GeneratorException('Package ' . $package . ' was not found.');
    }

    /** Verify every existing component, including spelling on case-insensitive hosts. */
    private function inspectTarget(string $relative): string
    {
        $parts = explode('/', $relative);
        $current = $this->root;
        foreach ($parts as $index => $part) {
            if (preg_match('/\A[A-Za-z0-9_.-]+\z/D', $part) !== 1
                || $part === '.' || $part === '..') {
                throw new GeneratorException('Unsafe target path.');
            }
            if (is_dir($current)) {
                $this->assertExactChild($current, $part);
            }
            $current .= DIRECTORY_SEPARATOR . $part;
            if (is_link($current) || (file_exists($current) && !$this->contained($current))) {
                throw new GeneratorException('Unsafe target path.');
            }
            if ($index < count($parts) - 1 && file_exists($current) && !is_dir($current)) {
                throw new GeneratorException('Unsafe target path.');
            }
        }
        if (file_exists($current) || is_link($current)) {
            throw new GeneratorException('Target already exists.');
        }
        return $current;
    }

    private function assertExactChild(string $directory, string $part): void
    {
        $entries = @scandir($directory);
        if ($entries === false) {
            throw new GeneratorException('Target directory could not be inspected.');
        }
        foreach ($entries as $entry) {
            if (strcasecmp($entry, $part) === 0 && $entry !== $part) {
                throw new GeneratorException('Unsafe target path casing.');
            }
        }
    }

    private function contained(string $path): bool
    {
        $resolved = realpath($path);
        if ($resolved === false) return false;
        $prefix = rtrim($this->root, '/\\') . DIRECTORY_SEPARATOR;
        return PHP_OS_FAMILY === 'Windows'
            ? strncasecmp($resolved, $prefix, strlen($prefix)) === 0
            : strncmp($resolved, $prefix, strlen($prefix)) === 0;
    }

    /** @param list<string> $created */
    private function createParents(string $relative, array &$created): void
    {
        $parts = explode('/', $relative);
        array_pop($parts);
        $current = $this->root;
        foreach ($parts as $part) {
            $this->assertExactChild($current, $part);
            $current .= DIRECTORY_SEPARATOR . $part;
            if (!file_exists($current)) {
                if (!@mkdir($current, 0775)) {
                    throw new GeneratorException('Target directory could not be created.');
                }
                $created[] = $current;
            }
            if (is_link($current) || !is_dir($current) || !$this->contained($current)) {
                throw new GeneratorException('Unsafe target path.');
            }
        }
    }

    private function rejectDuplicateMigrationClass(string $className): void
    {
        // Migrator also discovers older lowercase directory spellings. Check
        // every directory it reads so Linux cannot later see a duplicate class.
        $directories = [];
        foreach (['Database', 'database'] as $root) {
            foreach (['Migrations', 'migrations'] as $child) {
                $directory = realpath($this->root . '/' . $root . '/' . $child);
                if ($directory === false || !is_dir($directory)) continue;
                if (!$this->contained($directory)) {
                    throw new GeneratorException('Unsafe migration directory.');
                }
                $directories[$directory] = true;
            }
        }
        foreach (array_keys($directories) as $directory) {
            $files = @scandir($directory);
            if ($files === false) throw new GeneratorException('Migration directory could not be inspected.');
            foreach ($files as $file) {
                if (!str_ends_with(strtolower($file), '.php')) continue;
                $stem = pathinfo($file, PATHINFO_FILENAME);
                $stem = preg_replace('/\A\d{4}_\d{2}_\d{2}_(?:\d{6}_)?/', '', $stem) ?? '';
                $existing = implode('', array_map('ucfirst', preg_split('/[_-]+/', $stem) ?: []));
                if (strcasecmp($existing, $className) === 0) {
                    throw new GeneratorException('A migration with this class name already exists.');
                }
            }
        }
    }
}
