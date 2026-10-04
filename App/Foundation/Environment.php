<?php

declare(strict_types=1);

namespace App\Foundation;

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidFileException;
use RuntimeException;
use UnexpectedValueException;

/** Reads .env once and exposes values without publishing an environment dump. */
final class Environment
{
    /** @var array<string, string> Values this class copied into the process environment. */
    private static array $publishedValues = [];

    private bool $loaded = false;

    /** @var array<string, string|null> */
    private array $values = [];

    /** @var list<string> Keys copied from .env into $_ENV for legacy readers. */
    private array $publishedDotenvKeys = [];

    public function __construct(private string $basePath)
    {
    }

    public function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $file = $this->basePath . '/.env';
        if (file_exists($file)) {
            if (!is_file($file) || !is_readable($file)) {
                throw new RuntimeException('Application .env file cannot be read.');
            }
            $contents = file_get_contents($file);
            if ($contents === false) {
                throw new RuntimeException('Application .env file cannot be read.');
            }
            try {
                $parsed = Dotenv::parse($contents);
            } catch (InvalidFileException) {
                // The parser's diagnostic can contain the offending line.
                throw new RuntimeException('Application .env file is invalid.');
            }
            foreach ($parsed as $key => $value) {
                // Existing environment variables override values from .env.
                $external = getenv($key);
                $inherited = self::isPublishedValue($key);
                $resolved = $inherited ? ($external !== false ? $external : $value)
                    : ($_ENV[$key] ?? ($external !== false ? $external : $value));
                $this->values[$key] = $resolved;
                if ($inherited && $resolved === null) {
                    unset($_ENV[$key], self::$publishedValues[$key]);
                }
                // Legacy database code still reads $_ENV directly. A value
                // published by an earlier Application is not an external
                // override of this Application's newer .env contents.
                if ((!array_key_exists($key, $_ENV) || $inherited) && $resolved !== null) {
                    $_ENV[$key] = $resolved;
                    if ($external === false) {
                        $this->publishedDotenvKeys[] = $key;
                        self::$publishedValues[$key] = $resolved;
                    } else {
                        unset(self::$publishedValues[$key]);
                    }
                }
            }
        }

        $this->loaded = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->load();
        if (array_key_exists($key, $this->values)) {
            return $this->values[$key];
        }
        // A prior Application may have published this key for legacy readers.
        // It is not an override for a project without that .env entry.
        if (array_key_exists($key, $_ENV) && !self::isPublishedValue($key)) {
            return $_ENV[$key];
        }
        $external = getenv($key);
        return $external === false ? $default : $external;
    }

    /**
     * Identify .env values published for legacy code, without exposing them.
     * Child servers must unset these inherited copies so each request can
     * read the current .env file; genuine shell variables keep precedence.
     *
     * @return list<string>
     */
    public function publishedDotenvKeys(): array
    {
        $this->load();
        return $this->publishedDotenvKeys;
    }

    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value) || is_int($value)) {
            $boolean = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($boolean !== null) {
                return $boolean;
            }
        }
        throw new UnexpectedValueException("Environment value '{$key}' must be a boolean.");
    }

    private static function isPublishedValue(string $key): bool
    {
        return isset(self::$publishedValues[$key])
            && array_key_exists($key, $_ENV)
            && $_ENV[$key] === self::$publishedValues[$key];
    }
}
