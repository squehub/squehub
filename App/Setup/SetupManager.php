<?php

declare(strict_types=1);

namespace App\Setup;

use App\Changes\ChangeAction;
use App\Changes\ChangeApplyException;
use App\Changes\ChangePlan;
use App\Changes\ChangeResult;
use App\Contributions\ContributionOwner;
use App\Foundation\EnvironmentSetup;
use App\Support\SecureRandom;
use Throwable;
use WeakMap;

/**
 * Plans optional application setup without booting services or executing code.
 *
 * ChangePlan carries only safe labels and stale-file fingerprints. The planned
 * non-secret choices remain private to this manager; the application key is
 * generated only after the reviewed plan is deliberately applied.
 */
final class SetupManager
{
    private const ENVIRONMENTS = ['development', 'local', 'staging', 'production'];
    private const DATABASES = ['sqlite', 'mysql'];
    private const SQLITE_FILE = 'Storage/Database.sqlite';

    private string $root;

    /** @var WeakMap<ChangePlan,array{updates:array<string,string>,generate_key:bool,storage:bool,sqlite:bool}> */
    private WeakMap $prepared;

    public function __construct(string $basePath)
    {
        $root = realpath($basePath);
        if ($root === false || !is_dir($root)) {
            throw new SetupException('Application root is unavailable.');
        }
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
        $this->prepared = new WeakMap();
    }

    /**
     * A safe status summary. Configured means a value exists, not that Doctor
     * has verified the selected database or optional infrastructure.
     *
     * @return array{environment_file:string,app_key:string,environment:string,debug:string,database:string,storage:string}
     */
    public function inspect(): array
    {
        $source = $this->source(false);
        // Template defaults describe an example, not the current application.
        $values = $source['missing'] ? [] : SetupEnvironmentEditor::parse($source['contents']);
        $environment = trim((string) ($values['APP_ENV'] ?? ''));
        $debug = $values['APP_DEBUG'] ?? null;
        $database = trim((string) ($values['DB_CONNECTION'] ?? ''));
        $key = (string) ($values['APP_KEY'] ?? '');
        $storage = $this->root . '/Storage';

        return [
            'environment_file' => $source['missing'] ? 'missing'
                : (EnvironmentSetup::status($this->root) === EnvironmentSetup::UNCHANGED
                    ? 'unchanged' : 'configured'),
            'app_key' => $key === '' ? 'missing' : ($this->validKey($key) ? 'configured' : 'invalid'),
            'environment' => $environment === '' ? 'missing'
                : (in_array($environment, self::ENVIRONMENTS, true) ? $environment : 'invalid'),
            'debug' => $debug === null ? 'missing'
                : (filter_var($debug, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === null
                    ? 'invalid' : (filter_var($debug, FILTER_VALIDATE_BOOLEAN) ? 'enabled' : 'disabled')),
            'database' => $database === '' ? 'missing'
                : (in_array($database, self::DATABASES, true) ? $database : 'invalid'),
            'storage' => !file_exists($storage) && !is_link($storage) ? 'missing'
                : (is_dir($storage) && !is_link($storage) && is_writable($storage)
                    ? 'writable' : 'unwritable'),
        ];
    }

    /**
     * Fresh applications require explicit environment and database decisions.
     * Existing nonblank choices are preserved unless the caller selects new
     * ones. No database, Package, migration or Seeder operation runs here.
     */
    public function plan(?string $environment = null, ?string $database = null): ChangePlan
    {
        $source = $this->source(true);
        $values = SetupEnvironmentEditor::parse($source['contents']);
        $storage = $this->root . '/Storage';
        if (is_link($storage) || (file_exists($storage) && (!is_dir($storage) || !is_writable($storage)))) {
            throw new SetupException('Application Storage directory is unsafe or not writable.');
        }
        $createStorage = !file_exists($storage);
        if ($createStorage && !is_writable($this->root)) {
            throw new SetupException('Application root is not writable for Storage creation.');
        }
        if ($source['missing'] && ($environment === null || $database === null)) {
            throw new SetupException('Choose an environment and database before creating .env.');
        }

        $environment ??= (string) ($values['APP_ENV'] ?? '');
        $database ??= (string) ($values['DB_CONNECTION'] ?? '');
        if (!in_array($environment, self::ENVIRONMENTS, true)) {
            throw new SetupException('Choose development, local, staging, or production for APP_ENV.');
        }
        if (!in_array($database, self::DATABASES, true)) {
            throw new SetupException('Choose sqlite or mysql for DB_CONNECTION.');
        }
        if (array_key_exists('APP_DEBUG', $values)
            && filter_var($values['APP_DEBUG'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === null) {
            // A bad boolean can stop normal Application bootstrap before the
            // command is reached; do not describe that state as configured.
            throw new SetupException('Existing APP_DEBUG is invalid; review it manually before Setup.');
        }

        $key = (string) ($values['APP_KEY'] ?? '');
        if (!$source['missing'] && $key !== '' && !$this->validKey($key)) {
            // Setup must not silently replace or rotate an existing key.
            throw new SetupException('Existing APP_KEY is invalid; review it manually before Setup.');
        }
        $generateKey = $source['missing'] || $key === '';
        $updates = [];
        if (($values['APP_ENV'] ?? null) !== $environment) {
            $updates['APP_ENV'] = $environment;
        }
        if ($environment === 'production' && ($values['APP_DEBUG'] ?? null) !== 'false') {
            // Only an explicitly reviewed production plan changes debug mode.
            $updates['APP_DEBUG'] = 'false';
        }
        if (($values['DB_CONNECTION'] ?? null) !== $database) {
            $updates['DB_CONNECTION'] = $database;
        }

        $sqlite = false;
        if ($database === 'sqlite') {
            $path = (string) ($values['DB_SQLITE_DATABASE'] ?? '');
            if ($source['missing'] || $path === '' || $path === ':memory:') {
                $path = self::SQLITE_FILE;
                $updates['DB_SQLITE_DATABASE'] = $path;
            }
            if ($path === self::SQLITE_FILE) {
                $file = $this->root . '/' . self::SQLITE_FILE;
                if (is_link($file) || (file_exists($file) && !is_file($file))) {
                    throw new SetupException('SQLite database path is unsafe.');
                }
                $sqlite = !file_exists($file);
            } else {
                // Doctor's SQLite connection can create a missing file. An
                // existing custom path is therefore accepted only when it
                // already points to a regular database file; Setup never
                // creates an unplanned file outside its canonical Storage.
                $this->assertExistingSqliteFile($path);
            }
        }

        $owner = new ContributionOwner('application', 'Project');
        $actions = [];
        $preconditions = [];
        if ($createStorage) {
            $actions[] = new ChangeAction('create', 'Storage', $owner, null, null,
                'review', 'Prepare the application Storage directory.');
            $preconditions['Storage'] = null;
        }
        if ($sqlite) {
            $actions[] = new ChangeAction('create', self::SQLITE_FILE, $owner, null, null,
                'review', 'Prepare an empty application SQLite file; migrations are separate.');
            $preconditions[self::SQLITE_FILE] = null;
        }
        if ($source['missing'] || $updates !== [] || $generateKey) {
            if (!is_writable($this->root)) {
                throw new SetupException('Application root is not writable for .env changes.');
            }
            $actions[] = new ChangeAction($source['missing'] ? 'create' : 'modify', '.env',
                $owner, $source['missing'] ? null : hash('sha256', $source['contents']), null,
                'review', 'Configure selected settings; generate APP_KEY only if missing.');
            $preconditions['.env'] = $source['missing'] ? null : hash('sha256', $source['contents']);
            if ($source['missing']) {
                $preconditions['.example.env'] = hash('sha256', $source['contents']);
            }
        }
        $warnings = ['Migrations, Seeders and Packages are not run by SqueHub Setup.'];
        if ($database === 'mysql') {
            $warnings[] = 'Review MySQL host, database, username and password manually in .env.';
        }
        $plan = new ChangePlan('setup', 'application', $owner, $actions, $warnings, [], $preconditions);
        $this->prepared[$plan] = ['updates' => $updates, 'generate_key' => $generateKey,
            'storage' => $createStorage, 'sqlite' => $sqlite];
        return $plan;
    }

    /**
     * Serializes cooperating Setup writers, rechecks the reviewed source, and
     * publishes one complete .env file. The lock contains no credentials.
     */
    public function apply(ChangePlan $plan): ChangeResult
    {
        $prepared = $this->prepared[$plan] ?? null;
        if ($prepared === null || $plan->operation !== 'setup' || $plan->hasConflicts()) {
            throw new SetupException('SqueHub Setup plan is invalid.');
        }
        if ($plan->actions === []) {
            return new ChangeResult($plan, [], null, [], true);
        }

        $lockPath = $this->root . '/.env.setup.lock';
        if (is_link($lockPath)) {
            throw new SetupException('SqueHub Setup lock path is unsafe.');
        }
        $lock = @fopen($lockPath, 'c+b');
        if ($lock === false) {
            throw new SetupException('SqueHub Setup cannot lock the application root.');
        }
        try {
            if (!@flock($lock, LOCK_EX)) {
                throw new SetupException('SqueHub Setup cannot lock the application root.');
            }
            $this->assertPreconditions($plan);
            $applied = [];
            $createdStorage = false;
            $createdSqlite = false;
            try {
                if ($prepared['storage']) {
                    $storage = $this->root . '/Storage';
                    // A fresh runtime directory may later hold database,
                    // session, or log data; do not grant access to other users.
                    $createdStorage = @mkdir($storage, 0700);
                    if (!$createdStorage || is_link($storage) || !is_dir($storage)) {
                        throw new SetupException('Application Storage directory could not be prepared.');
                    }
                    $applied[] = $plan->actions[count($applied)];
                }
                $this->assertStorage();
                if ($prepared['sqlite']) {
                    $file = $this->root . '/' . self::SQLITE_FILE;
                    $handle = @fopen($file, 'x+b');
                    $createdSqlite = $handle !== false;
                    if (!$createdSqlite || !@fclose($handle) || !@chmod($file, 0600)) {
                        throw new SetupException('SQLite database file could not be prepared.');
                    }
                    $applied[] = $plan->actions[count($applied)];
                }

                if (count($applied) < count($plan->actions)) {
                    $source = $this->source(true);
                    $updates = $prepared['updates'];
                    if ($prepared['generate_key']) {
                        $updates['APP_KEY'] = SecureRandom::applicationKey();
                    }
                    $contents = SetupEnvironmentEditor::update($source['contents'], $updates);
                    $this->publishEnvironment($contents, $source['missing']);
                    $applied[] = $plan->actions[count($applied)];
                }
            } catch (Throwable) {
                // The attempted action may have become visible before a later
                // verification failed. Report what was certainly applied and
                // give the operator a safe relative path to inspect.
                $failed = $plan->actions[count($applied)] ?? null;
                $env = $this->root . '/.env';
                $envBefore = $plan->preconditions['.env'] ?? null;
                $envNow = is_file($env) ? @hash_file('sha256', $env) : null;
                $recovery = $envNow !== $envBefore ? '.env' : null;
                if ($recovery === null && $createdSqlite) {
                    $recovery = self::SQLITE_FILE;
                }
                if ($recovery === null && $createdStorage) {
                    $recovery = 'Storage';
                }
                if ($applied !== [] || $recovery !== null) {
                    throw new ChangeApplyException(new ChangeResult($plan, $applied, $failed,
                        array_slice($plan->actions, count($applied) + 1), false, $recovery));
                }
                throw new SetupException('SqueHub Setup could not apply the reviewed plan.');
            }
            return new ChangeResult($plan, $applied, null, [], true);
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{contents:string,missing:bool} */
    private function source(bool $requireTemplate): array
    {
        $env = $this->root . '/.env';
        if (file_exists($env) || is_link($env)) {
            return ['contents' => $this->readFile($env), 'missing' => false];
        }
        $example = $this->root . '/.example.env';
        if (!is_file($example) || is_link($example)) {
            if ($requireTemplate) {
                throw new SetupException('Both .env and a readable .example.env template are unavailable.');
            }
            return ['contents' => '', 'missing' => true];
        }
        return ['contents' => $this->readFile($example), 'missing' => true];
    }

    private function readFile(string $path): string
    {
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            throw new SetupException('Application environment file is unreadable or unsafe.');
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new SetupException('Application environment file cannot be read.');
        }
        return $contents;
    }

    private function assertStorage(): void
    {
        $storage = $this->root . '/Storage';
        if (is_link($storage) || !is_dir($storage) || !is_writable($storage)) {
            throw new SetupException('Application Storage directory is missing or not writable.');
        }
    }

    private function assertExistingSqliteFile(string $path): void
    {
        $absolute = str_starts_with($path, '/') || str_starts_with($path, '\\')
            || preg_match('~\A[A-Za-z]:[/\\\\]~', $path) === 1;
        if (!$absolute) {
            $segments = preg_split('~[/\\\\]+~', $path);
            if ($segments === false || in_array('..', $segments, true)) {
                throw new SetupException('Existing SQLite database path is unsafe.');
            }
        }
        $target = $absolute ? $path : $this->root . '/' . str_replace('\\', '/', $path);
        if (is_link($target) || !is_file($target)) {
            throw new SetupException('Existing SQLite database file is unavailable; review its path manually.');
        }
    }

    private function assertPreconditions(ChangePlan $plan): void
    {
        foreach ($plan->preconditions as $subject => $expected) {
            if (!in_array($subject, ['.env', '.example.env', 'Storage', self::SQLITE_FILE], true)) {
                throw new SetupException('SqueHub Setup plan has an unsafe precondition.');
            }
            $path = $this->root . '/' . $subject;
            if (is_link($path)) {
                throw new SetupException('SqueHub Setup plan is stale or unsafe.');
            }
            $actual = is_file($path) ? @hash_file('sha256', $path)
                : (file_exists($path) ? 'present' : null);
            if ($actual !== $expected) {
                throw new SetupException('SqueHub Setup plan is stale; inspect and review a new plan.');
            }
        }
    }

    private function publishEnvironment(string $contents, bool $create): void
    {
        $target = $this->root . '/.env';
        $temporary = @tempnam($this->root, '.env.setup-');
        if ($temporary === false) {
            throw new SetupException('Application .env update could not be prepared.');
        }
        try {
            $previousPermissions = $create ? null : @fileperms($target);
            if (!$create && $previousPermissions === false) {
                throw new SetupException('Application .env permissions could not be read.');
            }
            $permissions = $create ? 0600 : ($previousPermissions & 0777);
            if (!@chmod($temporary, $permissions)) {
                throw new SetupException('Application .env permissions could not be prepared.');
            }
            $handle = @fopen($temporary, 'wb');
            if ($handle === false) {
                throw new SetupException('Application .env update could not be prepared.');
            }
            try {
                $length = strlen($contents);
                $offset = 0;
                while ($offset < $length) {
                    $written = @fwrite($handle, substr($contents, $offset));
                    if ($written === false || $written === 0) {
                        throw new SetupException('Application .env update could not be written.');
                    }
                    $offset += $written;
                }
                if (!@fflush($handle)) {
                    throw new SetupException('Application .env update could not be flushed.');
                }
            } finally {
                fclose($handle);
            }
            if ($create ? !@link($temporary, $target) : !@rename($temporary, $target)) {
                throw new SetupException('Application .env update could not be published.');
            }
            if (@hash_file('sha256', $target) !== hash('sha256', $contents)) {
                throw new SetupException('Application .env update could not be verified.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function validKey(string $key): bool
    {
        if (preg_match('~\Abase64:[A-Za-z0-9+/]{43}=\z~D', $key) !== 1) {
            return false;
        }
        $raw = base64_decode(substr($key, 7), true);
        return $raw !== false && strlen($raw) === 32 && 'base64:' . base64_encode($raw) === $key;
    }
}
