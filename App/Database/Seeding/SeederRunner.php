<?php

declare(strict_types=1);

namespace App\Database\Seeding;

use App\Clis\FileLookup;
use App\Foundation\Application;
use ReflectionClass;
use Throwable;

/** Resolves application Seeders through the container in declaration order. */
final class SeederRunner
{
    /** @var list<class-string<Seeder>> */
    private array $stack = [];
    /** @var list<class-string<Seeder>> */
    private array $completed = [];
    private $onCompleted = null;
    private bool $running = false;

    public function __construct(private Application $app)
    {
    }

    /** @return list<class-string<Seeder>> */
    public function run(string $name = 'DatabaseSeeder', bool $force = false, ?callable $onCompleted = null): array
    {
        if ($this->running) {
            throw new SeederException('A Seeder run cannot be reentered.');
        }
        $this->assertEnvironment($force);
        $this->running = true;
        $this->stack = [];
        $this->completed = [];
        $this->onCompleted = $onCompleted;
        try {
            $this->call($name);
            return $this->completed;
        } finally {
            $this->onCompleted = null;
            $this->running = false;
        }
    }

    /**
     * List canonical application Seeder files without loading application code.
     *
     * Availability is not run history: this runner intentionally stores no
     * completed/pending state between processes.
     *
     * @return list<string>
     */
    public function available(): array
    {
        $path = $this->app->basePath() . '/Database/Seeders';
        if (!is_dir($path) && !is_link($path)) {
            return [];
        }
        $directory = FileLookup::seederDirectory($this->app->basePath());
        if ($directory === null) {
            throw new SeederException('Seeder directory is outside the application root or uses a linked path.');
        }
        $entries = @scandir($directory);
        if ($entries === false) {
            throw new SeederException('Seeder directory could not be inspected.');
        }
        $names = [];
        foreach ($entries as $file) {
            if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\.php\z/D', $file) !== 1) {
                continue;
            }
            $name = substr($file, 0, -4);
            if (FileLookup::seeder($this->app->basePath(), $name) !== null) {
                $names[] = $name;
            }
        }
        sort($names, SORT_STRING);
        return $names;
    }

    /**
     * Run one named Seeder's explicit inverse, never an inferred batch rollback.
     *
     * Preflight the class contract before construction so a nonreversible
     * Seeder cannot create side effects merely by being resolved. The caller
     * owns transaction boundaries, including rollback after an exception.
     */
    public function rollback(string $name, bool $force = false): void
    {
        if ($this->running) {
            throw new SeederException('A Seeder operation cannot be reentered.');
        }
        $this->assertEnvironment($force);
        $this->running = true;
        try {
            $class = $this->className($name);
            if (!is_subclass_of($class, ReversibleSeeder::class)) {
                throw new SeederException("Seeder {$class} does not support rollback.");
            }
            try {
                $seeder = $this->app->container()->make($class);
                if (!$seeder instanceof Seeder) {
                    throw new SeederException("{$class} must extend " . Seeder::class . '.');
                }
                if (!$seeder instanceof ReversibleSeeder) {
                    throw new SeederException("Seeder {$class} does not support rollback.");
                }
                $seeder->rollback();
            } catch (Throwable $exception) {
                throw new SeederException("Seeder {$class} rollback failed.", 0, $exception);
            }
        } finally {
            $this->running = false;
        }
    }

    private function assertEnvironment(bool $force): void
    {
        $environment = strtolower(trim($this->app->environment()));
        if (!$force && in_array($environment, ['production', 'prod', 'staging', 'stage', 'preprod'], true)) {
            throw new SeederException("Seeding is disabled in {$environment} without --force.");
        }
    }

    /** @internal Child calls share the active cycle stack and output callback. */
    public function call(string $name): void
    {
        if (!$this->running) {
            throw new SeederException('Call run() to execute Seeders.');
        }
        $class = $this->className($name);
        if (in_array($class, $this->stack, true)) {
            throw new SeederException('Seeder cycle: ' . implode(' -> ', [...$this->stack, $class]));
        }
        $this->stack[] = $class;
        try {
            try {
                $seeder = $this->app->container()->make($class);
                if (!$seeder instanceof Seeder) {
                    throw new SeederException("{$class} must extend " . Seeder::class . '.');
                }
                $seeder->useRunner($this);
                $seeder->run();
            } catch (Throwable $exception) {
                if ($exception instanceof SeederException && str_starts_with($exception->getMessage(), 'Seeder cycle:')) {
                    throw $exception;
                }
                throw new SeederException("Seeder {$class} failed.", 0, $exception);
            }
            $this->completed[] = $class;
            if ($this->onCompleted !== null) {
                ($this->onCompleted)($class);
            }
        } finally {
            array_pop($this->stack);
        }
    }

    /** Resolve one exact class and file under the canonical application directory. */
    private function className(string $name): string
    {
        $prefix = 'Database\\Seeders\\';
        $short = str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : $name;
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $short) !== 1) {
            throw new SeederException('Seeder name must be a class in Database\\Seeders.');
        }
        $path = FileLookup::seeder($this->app->basePath(), $short);
        if ($path === null) {
            throw new SeederException("Seeder {$prefix}{$short} was not found in Database/Seeders.");
        }
        $class = $prefix . $short;
        try {
            require_once $path;
            if (!class_exists($class, false) || (new ReflectionClass($class))->getName() !== $class) {
                throw new SeederException("Seeder class {$class} was not declared by its canonical file.");
            }
        } catch (Throwable $exception) {
            if ($exception instanceof SeederException) {
                throw $exception;
            }
            // User code may throw while loading. Keep CLI errors free of file
            // paths and application data while retaining the original cause.
            throw new SeederException("Seeder class {$class} could not be loaded.", 0, $exception);
        }
        return $class;
    }
}
