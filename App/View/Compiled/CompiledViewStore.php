<?php

declare(strict_types=1);

namespace App\View\Compiled;

/**
 * Owns immutable compiled PHP under one Application's Storage/Views directory.
 * Source text and compiler output are framed into the identity; runtime data
 * never participates. A per-artifact OS lock covers inspection and publication
 * so cooperating PHP workers cannot observe a partially written final file.
 */
final class CompiledViewStore
{
    public const FORMAT_VERSION = '14N-compiled-lifecycle-1';
    private const FILE_PREFIX = 'squehub-view-';

    private readonly string $basePath;
    private readonly OpcacheBridge $opcache;

    public function __construct(string $basePath, ?OpcacheBridge $opcache = null,
        private readonly string $formatVersion = self::FORMAT_VERSION)
    {
        $physical = realpath($basePath);
        if ($physical === false || !is_dir($physical)) {
            throw new CompiledViewException('Compiled View application root is unavailable.');
        }
        $this->basePath = rtrim(str_replace('\\', '/', $physical), '/');
        $this->opcache = $opcache ?? new OpcacheBridge();
    }

    /** The framed fields prevent ambiguous identities and preserve View names. */
    public function fingerprint(string $view, string $source, string $compiled,
        string $mode): string
    {
        return hash('sha256', serialize([
            $this->formatVersion, $view, $source, $mode, $compiled,
        ]));
    }

    /**
     * @return array{path: string, compiled: bool}
     */
    public function prepare(string $view, string $source, string $compiled,
        string $mode): array
    {
        $compiled = self::artifactContent($compiled);
        $fingerprint = $this->fingerprint($view, $source, $compiled, $mode);
        $root = $this->root(true)
            ?? throw new CompiledViewException('Compiled View directory is unavailable.');
        $path = $root . '/' . self::FILE_PREFIX . $fingerprint . '.php';
        $lockPath = $root . '/.' . self::FILE_PREFIX . $fingerprint . '.lock';
        $this->checkEntry($lockPath);
        $lock = @fopen($lockPath, 'c+b');
        if ($lock === false) {
            throw new CompiledViewException('Compiled View lock is unavailable.');
        }
        try {
            if (!@flock($lock, LOCK_EX)) {
                throw new CompiledViewException('Compiled View lock could not be acquired.');
            }
            $this->checkRoot($root);
            $this->checkEntry($path);
            if ($this->matches($path, $compiled)) {
                return ['path' => $path, 'compiled' => false];
            }

            // A generated file contains executable PHP. Parse it without
            // running template code before it can become a reusable artifact.
            try {
                $tokens = token_get_all($compiled, TOKEN_PARSE);
                if ($tokens === []) {
                    throw new CompiledViewException('Compiled View source is empty.');
                }
            } catch (\ParseError $error) {
                throw new CompiledViewException('Compiled View source contains invalid PHP syntax.',
                    previous: $error);
            }

            $replacing = file_exists($path);
            if ($replacing) {
                // The old path might already have OPcache bytecode. Refuse to
                // replace it if targeted invalidation explicitly fails.
                $this->opcache->invalidate($path);
                if (!@unlink($path)) {
                    throw new CompiledViewException('Compiled View artifact could not be replaced.');
                }
            }
            $temporary = $root . '/.' . self::FILE_PREFIX . $fingerprint
                . '.tmp.' . bin2hex(random_bytes(16));
            $this->writeTemporary($temporary, $compiled);
            try {
                $this->checkRoot($root);
                $this->checkEntry($path);
                if (!@rename($temporary, $path)) {
                    // A non-cooperating publisher may have won the filename.
                    // Only its exact deterministic bytes are acceptable.
                    if ($this->matches($path, $compiled)) {
                        return ['path' => $path, 'compiled' => false];
                    }
                    throw new CompiledViewException('Compiled View artifact could not be published.');
                }
            } finally {
                if (file_exists($temporary)) {
                    @unlink($temporary);
                }
            }
            if ($replacing) {
                $this->opcache->invalidate($path);
            }
            if (!$this->matches($path, $compiled)) {
                throw new CompiledViewException('Compiled View artifact failed integrity validation.');
            }
            return ['path' => $path, 'compiled' => true];
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * A View can be loaded only while the same deterministic bytes remain at
     * its framework-generated path. This check also rejects linked entries.
     */
    public function matches(string $path, string $compiled): bool
    {
        $compiled = self::artifactContent($compiled);
        clearstatcache(true, $path);
        $this->checkEntry($path);
        if (!is_file($path)) {
            return false;
        }
        $size = @filesize($path);
        if ($size === false || $size !== strlen($compiled)) {
            return false;
        }
        $hash = @hash_file('sha256', $path);
        if ($hash === false) {
            throw new CompiledViewException('Compiled View artifact could not be read.');
        }
        return hash_equals(hash('sha256', $compiled), $hash);
    }

    /**
     * Remove only this subsystem's final artifacts. Per-fingerprint lock files
     * are retained: unlinking a live lock file could split two OS lock inodes.
     * Old temporary files are removed only after a conservative age threshold.
     */
    public function clear(): int
    {
        $root = $this->root(false);
        if ($root === null) {
            return 0;
        }
        $entries = @scandir($root);
        if ($entries === false) {
            throw new CompiledViewException('Compiled View directory could not be listed.');
        }
        $removed = 0;
        foreach ($entries as $entry) {
            $artifact = preg_match('/\A' . self::FILE_PREFIX . '[0-9a-f]{64}\.php\z/D', $entry) === 1;
            $temporary = preg_match('/\A\.' . self::FILE_PREFIX
                . '[0-9a-f]{64}\.tmp\.[0-9a-f]{32}\z/D', $entry) === 1;
            if (!$artifact && !$temporary) {
                continue;
            }
            $path = $root . '/' . $entry;
            if ($temporary) {
                $this->checkRoot($root);
                $modified = @filemtime($path);
                if ($modified === false || $modified > time() - 3600) {
                    continue;
                }
                if (!@unlink($path)) {
                    throw new CompiledViewException('Compiled View temporary artifact could not be removed.');
                }
                ++$removed;
                continue;
            }
            $fingerprint = substr($entry, strlen(self::FILE_PREFIX), 64);
            $lockPath = $root . '/.' . self::FILE_PREFIX . $fingerprint . '.lock';
            $this->checkEntry($lockPath);
            $lock = @fopen($lockPath, 'c+b');
            if ($lock === false) {
                throw new CompiledViewException('Compiled View lock is unavailable.');
            }
            try {
                if (!@flock($lock, LOCK_EX)) {
                    throw new CompiledViewException('Compiled View lock could not be acquired.');
                }
                $this->checkRoot($root);
                if (!file_exists($path) && !is_link($path)) {
                    continue;
                }
                if (!is_link($path)) {
                    $this->checkEntry($path);
                    $this->opcache->invalidate($path);
                }
                // unlink removes a symbolic link itself, never its target.
                if (!@unlink($path)) {
                    throw new CompiledViewException('Compiled View artifact could not be removed.');
                }
                ++$removed;
            } finally {
                @flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
        return $removed;
    }

    private function writeTemporary(string $path, string $compiled): void
    {
        $output = @fopen($path, 'x+b');
        if ($output === false) {
            throw new CompiledViewException('Compiled View temporary artifact could not be created.');
        }
        $complete = false;
        try {
            $offset = 0;
            $length = strlen($compiled);
            while ($offset < $length) {
                $written = @fwrite($output, substr($compiled, $offset));
                if ($written === false || $written === 0) {
                    throw new CompiledViewException('Compiled View temporary artifact could not be written.');
                }
                $offset += $written;
            }
            if (!@fflush($output)) {
                throw new CompiledViewException('Compiled View temporary artifact could not be flushed.');
            }
            $complete = true;
        } finally {
            fclose($output);
            if (!$complete && file_exists($path)) {
                @unlink($path);
            }
        }
    }

    /** An empty source still receives a non-empty, executable artifact. */
    private static function artifactContent(string $compiled): string
    {
        return $compiled === '' ? '<?php /* Empty SqueHub View. */ ?>' : $compiled;
    }

    /** @return string|null A missing root is normal for an idempotent clear. */
    private function root(bool $create): ?string
    {
        $storage = $this->basePath . '/Storage';
        $views = $storage . '/Views';
        foreach ([$storage, $views] as $directory) {
            // Another worker may have just created this directory. PHP's stat
            // cache must not turn that successful race into a false failure.
            clearstatcache(true, $directory);
            $this->checkEntry($directory);
            if (!is_dir($directory)) {
                clearstatcache(true, $directory);
                if (is_dir($directory)) {
                    continue;
                }
                if (file_exists($directory)) {
                    clearstatcache(true, $directory);
                    if (is_dir($directory)) {
                        continue;
                    }
                    throw new CompiledViewException('Compiled View directory is not a directory.');
                }
                if (!$create) {
                    return null;
                }
                if (!@mkdir($directory, 0775)) {
                    clearstatcache(true, $directory);
                }
                if (!is_dir($directory)) {
                    throw new CompiledViewException('Compiled View directory could not be created.');
                }
            }
            $this->checkEntry($directory);
        }
        return $views;
    }

    private function checkRoot(string $root): void
    {
        $this->checkEntry($this->basePath . '/Storage');
        $this->checkEntry($root);
        if (!is_dir($root)) {
            throw new CompiledViewException('Compiled View directory is unavailable.');
        }
    }

    /** Reject symlink/reparse entries before filesystem reads or writes. */
    private function checkEntry(string $path): void
    {
        if (is_link($path)) {
            throw new CompiledViewException('Compiled View storage contains an unsafe link.');
        }
        if (!file_exists($path)) {
            return;
        }
        $physical = realpath($path);
        if ($physical === false || !self::samePath($path, $physical)) {
            throw new CompiledViewException('Compiled View storage contains an unsafe path.');
        }
    }

    private static function samePath(string $left, string $right): bool
    {
        $left = rtrim(str_replace('\\', '/', $left), '/');
        $right = rtrim(str_replace('\\', '/', $right), '/');
        return DIRECTORY_SEPARATOR === '\\'
            ? strcasecmp($left, $right) === 0 : $left === $right;
    }
}
