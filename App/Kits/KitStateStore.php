<?php

declare(strict_types=1);

namespace App\Kits;

use App\Activation\ActivationException;
use App\Activation\ActivationStore;
use App\Packages\PackageException;
use App\Packages\PackageFiles;
use App\Packages\PackageName;
use JsonException;

/**
 * Kit-shaped adapter over the shared SqueHub Activation Registry.
 *
 * readLegacy() validates the previous private State.json format. New writes
 * go through ActivationStore; Kit and Package activation now share one file.
 */
final class KitStateStore
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

    public function currentFingerprint(): string
    {
        try {
            return $this->activation()->currentFingerprint();
        } catch (ActivationException $exception) {
            throw new KitException('Activation registry could not inspect Kit state.', 0, $exception);
        }
    }

    /** @param array<string,array<string,mixed>> $records Legacy-only digest. */
    public function fingerprintWith(array $records): string
    {
        try {
            return $this->activation()->fingerprintWithKits($records);
        } catch (ActivationException $exception) {
            throw new KitException('Activation registry could not fingerprint Kit state.', 0, $exception);
        }
    }

    /** @param array<string,array<string,mixed>> $records */
    public static function fingerprintOf(array $records): string
    {
        return hash('sha256', self::encode($records));
    }

    /** @return array<string,array<string,mixed>> */
    public function read(): array
    {
        try {
            return $this->activation()->kits();
        } catch (ActivationException $exception) {
            throw new KitException('Activation registry could not read Kit state.', 0, $exception);
        }
    }

    /** Existing Kit State.json reader, used only when Activation.json is absent. */
    public function readLegacy(): array
    {
        $file = $this->directory . '/State.json';
        if (is_dir($this->directory) || is_link($this->directory)) {
            try { PackageFiles::assertPhysical($this->directory); }
            catch (PackageException $exception) {
                throw new KitException('Kit state directory is unsafe.', 0, $exception);
            }
        }
        if (is_link($file)) { throw new KitException('Kit state file is linked.'); }
        if (!file_exists($file)) { return []; }
        if (!is_file($file)) { throw new KitException('Kit state path is not a file.'); }
        $size = @filesize($file);
        if ($size === false || $size > 8388608) {
            throw new KitException('Kit state is too large or unavailable.');
        }
        $raw = @file_get_contents($file);
        if ($raw === false) { throw new KitException('Kit state cannot be read.'); }
        try {
            $state = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new KitException('Kit state is invalid JSON.', 0, $exception);
        }
        if (!is_array($state) || array_keys($state) !== ['version', 'kits']
            || $state['version'] !== 1 || !is_array($state['kits'])
            || (array_is_list($state['kits']) && $state['kits'] !== [])) {
            throw new KitException('Kit state format is unsupported.');
        }
        return self::validateRecords($state['kits']);
    }

    /** @param array<mixed> $raw @return array<string,array<string,mixed>> */
    public static function validateRecords(array $raw): array
    {
        if (count($raw) > 2048 || (array_is_list($raw) && $raw !== [])) {
            throw new KitException('Kit state contains too many or invalid records.');
        }
        $records = [];
        $folded = [];
        foreach ($raw as $name => $record) {
            if (!is_string($name) || !KitName::valid($name) || !is_array($record)
                || array_diff(array_keys($record),
                    ['enabled', 'source_kind', 'source', 'definition', 'published', 'requires']) !== []
                || !is_bool($record['enabled'] ?? null)
                || !in_array($record['source_kind'] ?? null, ['manual', 'local'], true)
                || !is_string($record['source'] ?? null)
                || strlen($record['source']) > 256
                || preg_match('/[\x00-\x1F\x7F]/', $record['source']) === 1
                || !is_array($record['definition'] ?? null)
                || count($record['definition']) > 10000
                || !is_array($record['published'] ?? null)
                || count($record['published']) > 10000
                || !is_array($record['requires'] ?? null)) {
                throw new KitException('Kit state contains an invalid record.');
            }
            $key = strtolower($name);
            if (isset($folded[$key])) {
                throw new KitException('Kit state contains case-colliding names.');
            }
            $folded[$key] = true;
            if ($record['source_kind'] === 'manual' && $record['source'] !== 'manual') {
                throw new KitException('Kit state source is invalid.');
            }
            if ($record['source_kind'] === 'local'
                && preg_match('/\Alocal:' . preg_quote($name, '/') . '#[a-f0-9]{12}\z/D',
                    $record['source']) !== 1) {
                throw new KitException('Kit state source is invalid.');
            }
            $paths = [];
            foreach ($record['definition'] as $path => $hash) {
                self::requireHashPath($path, $hash);
                $foldedPath = strtolower($path);
                if (isset($paths[$foldedPath])) {
                    throw new KitException('Kit state contains case-colliding files.');
                }
                $paths[$foldedPath] = true;
            }
            $paths = [];
            foreach ($record['published'] as $path => $item) {
                KitManifest::requireRelative((string) $path);
                if (!is_array($item) || array_keys($item) !== ['hash', 'kind']
                    || !is_string($item['kind'])
                    || !in_array($item['kind'], ['generated', 'migration', 'seeder', 'test', 'config', 'asset'], true)) {
                    throw new KitException('Kit state contains an invalid published file.');
                }
                self::requireHashPath($path, $item['hash'] ?? null);
                $foldedPath = strtolower($path);
                if (isset($paths[$foldedPath])) {
                    throw new KitException('Kit state contains case-colliding files.');
                }
                $paths[$foldedPath] = true;
            }
            if (!array_is_list($record['requires']) || count($record['requires']) > 64) {
                throw new KitException('Kit state requirements are invalid.');
            }
            $seen = [];
            foreach ($record['requires'] as $requirement) {
                if (!is_string($requirement) || !PackageName::valid($requirement)
                    || isset($seen[strtolower($requirement)])) {
                    throw new KitException('Kit state requirements are invalid.');
                }
                $seen[strtolower($requirement)] = true;
            }
            $records[$name] = $record;
        }
        ksort($records, SORT_STRING);
        return $records;
    }

    /** @param array<string,array<string,mixed>> $records */
    public function write(array $records): void
    {
        try {
            $this->activation()->writeKits($records);
        } catch (ActivationException $exception) {
            throw new KitException('Activation registry could not update Kit state.', 0, $exception);
        }
    }

    private static function requireHashPath(mixed $path, mixed $hash): void
    {
        if (!is_string($path) || !is_string($hash)
            || preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1) {
            throw new KitException('Kit state contains an invalid owned file.');
        }
        KitManifest::requireRelative($path);
    }

    /** @param array<string,array<string,mixed>> $records */
    private static function encode(array $records): string
    {
        ksort($records, SORT_STRING);
        foreach ($records as &$record) {
            $record['definition'] = (object) ($record['definition'] ?? []);
            $record['published'] = (object) ($record['published'] ?? []);
        }
        unset($record);
        try {
            $json = json_encode(['version' => 1, 'kits' => (object) $records],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new KitException('Kit state could not be encoded.', 0, $exception);
        }
        if (!is_string($json) || strlen($json) + 1 > 8388608) {
            throw new KitException('Kit state is too large to encode.');
        }
        return $json . "\n";
    }
}
