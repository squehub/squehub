<?php

declare(strict_types=1);

namespace App\Packages;

use App\Activation\ActivationException;
use App\Activation\ActivationStore;

/**
 * Package-shaped adapter over the shared SqueHub Activation Registry.
 *
 * Only readLegacy() understands the former State.json file. Normal reads and
 * writes use the Application-scoped ActivationStore, which keeps Package and
 * Kit state in one canonical, locked document.
 */
final class PackageStateStore
{
    private ?ActivationStore $store;

    public function __construct(private string $directory, ?ActivationStore $store = null)
    {
        $this->store = $store;
    }

    private function activation(): ActivationStore
    {
        return $this->store ??= new ActivationStore(dirname($this->directory, 2));
    }

    /** One normalized fingerprint guards both Package and Kit activation state. */
    public function currentFingerprint(): string
    {
        try {
            return $this->activation()->currentFingerprint();
        } catch (ActivationException $exception) {
            throw new PackageException('Activation registry could not inspect Package state.', 0, $exception);
        }
    }

    /** @param array<string, array<string, mixed>> $records Legacy-only digest. */
    public function fingerprintWith(array $records): string
    {
        try {
            return $this->activation()->fingerprintWithPackages($records);
        } catch (ActivationException $exception) {
            throw new PackageException('Activation registry could not fingerprint Package state.', 0, $exception);
        }
    }

    /** @param array<string, array<string, mixed>> $records */
    public static function fingerprintOf(array $records): string
    {
        return hash('sha256', self::encode($records));
    }

    /** @return array<string, array<string, mixed>> */
    public function read(): array
    {
        try {
            return $this->activation()->packages();
        } catch (ActivationException $exception) {
            throw new PackageException('Activation registry could not read Package state.', 0, $exception);
        }
    }

    /** Existing Package State.json reader, used only when Activation.json is absent. */
    public function readLegacy(): array
    {
        $file = $this->directory . '/State.json';
        if (is_dir($this->directory) || is_link($this->directory)) {
            PackageFiles::assertPhysical($this->directory);
        }
        if (is_link($file)) {
            throw new PackageException('Package state file is linked.');
        }
        if (!file_exists($file)) { return []; }
        if (!is_file($file)) {
            throw new PackageException('Package state path is not a file.');
        }
        $size = @filesize($file);
        if ($size === false || $size > 8388608) {
            throw new PackageException('Package state is too large or unavailable.');
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            throw new PackageException('Package state cannot be read.');
        }
        try {
            $state = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new PackageException('Package state is invalid JSON.', 0, $exception);
        }
        if (!is_array($state) || ($state['version'] ?? null) !== 1 || !is_array($state['packages'] ?? null)
            || array_is_list($state['packages']) && $state['packages'] !== []) {
            throw new PackageException('Package state format is unsupported.');
        }
        return self::validateRecords($state['packages']);
    }

    /** @param array<mixed> $raw @return array<string, array<string, mixed>> */
    public static function validateRecords(array $raw): array
    {
        if (count($raw) > 2048 || (array_is_list($raw) && $raw !== [])) {
            throw new PackageException('Package state contains too many or invalid records.');
        }
        $records = [];
        $folded = [];
        foreach ($raw as $name => $record) {
            if (is_array($record) && ($record['files'] ?? null) instanceof \stdClass) {
                $record['files'] = (array) $record['files'];
            }
            if (!is_string($name) || !PackageName::valid($name) || !is_array($record)
                || array_diff(array_keys($record),
                    ['enabled', 'source_kind', 'source', 'files', 'contribution_snapshot']) !== []
                || !is_bool($record['enabled'] ?? null)
                || !in_array($record['source_kind'] ?? null, ['manual', 'local', 'git'], true)
                || !is_string($record['source'] ?? null) || strlen($record['source']) > 256
                || preg_match('/[\x00-\x1F\x7F]/', $record['source'])
                || !is_array($record['files'] ?? null) || count($record['files']) > 10000) {
                throw new PackageException('Package state contains an invalid record.');
            }
            $key = strtolower($name);
            if (isset($folded[$key])) {
                throw new PackageException('Package state contains case-colliding names.');
            }
            $folded[$key] = true;
            $paths = [];
            foreach ($record['files'] as $path => $hash) {
                if (!is_string($path) || $path === '' || str_starts_with($path, '/')
                    || str_contains($path, '\\') || preg_match('/[\x00-\x1F\x7F:]/', $path)
                    || preg_match('~(^|/)\.\.?(?:/|$)~', $path)
                    || !is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1) {
                    throw new PackageException('Package state contains an invalid owned file.');
                }
                $foldedPath = strtolower($path);
                if (isset($paths[$foldedPath])) {
                    throw new PackageException('Package state contains case-colliding files.');
                }
                $paths[$foldedPath] = true;
            }
            if (array_key_exists('contribution_snapshot', $record)) {
                $snapshot = PackageSnapshot::parse($record['contribution_snapshot'], $name);
                if ($snapshot === null) {
                    unset($record['contribution_snapshot']);
                } else {
                    $record['contribution_snapshot'] = $snapshot;
                }
            }
            $records[$name] = $record;
        }
        ksort($records, SORT_STRING);
        return $records;
    }

    /** @param array<string, array<string, mixed>> $records */
    public function write(array $records): void
    {
        try {
            $this->activation()->writePackages($records);
        } catch (ActivationException $exception) {
            throw new PackageException('Activation registry could not update Package state.', 0, $exception);
        }
    }

    /** @param array<string, array<string, mixed>> $records */
    private static function encode(array $records): string
    {
        ksort($records, SORT_STRING);
        try {
            $json = json_encode(['version' => 1, 'packages' => (object) $records],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new PackageException('Package state could not be encoded.', 0, $exception);
        }
        if (!is_string($json) || strlen($json) + 1 > 8388608) {
            throw new PackageException('Package state could not be encoded.');
        }
        return $json . "\n";
    }
}
