<?php

declare(strict_types=1);

namespace App\Activation;

use App\Kits\KitException;
use App\Kits\KitStateStore;
use App\Packages\PackageException;
use App\Packages\PackageFiles;
use App\Packages\PackageSnapshot;
use App\Packages\PackageStateStore;
use JsonException;
use Throwable;

/**
 * One portable activation and ownership store for Packages and Kits.
 *
 * Reads are inert. The first explicit lifecycle write imports validated legacy
 * records into Project/Activation.json. The sibling lock coordinates SqueHub
 * writers; a complete temporary JSON file replaces the canonical file only
 * after all bytes have been written. It is not a transaction for Kit hooks or
 * published application files.
 */
final class ActivationStore
{
    private const MAX_BYTES = 16777216;

    private int $lockDepth = 0;

    public function __construct(private string $basePath)
    {
        $resolved = realpath($basePath);
        if ($resolved === false || !is_dir($resolved)) {
            throw new ActivationException('Application root is unavailable.');
        }
        $this->basePath = rtrim(str_replace('\\', '/', $resolved), '/');
    }

    /** @return array{packages:array<string,array<string,mixed>>,kits:array<string,array<string,mixed>>} */
    public function read(): array
    {
        // A legacy lowercase root may be read, but two case-equivalent roots
        // cannot form one portable application even before registry creation.
        // A noncanonical root may hold legacy app files, but not activation
        // metadata. Windows may alias Project/ to project/ while Linux does not.
        $root = $this->projectRootForRead();
        if ($root !== null && $root !== 'Project') {
            $legacyRoot = $this->basePath . '/' . $root;
            try { PackageFiles::assertPhysical($legacyRoot); }
            catch (PackageException $exception) {
                throw new ActivationException('Activation registry directory is unsafe.', 0, $exception);
            }
            $entries = @scandir($legacyRoot);
            if ($entries === false) {
                throw new ActivationException('Activation registry directory is unsafe.');
            }
            foreach ($entries as $entry) {
                foreach (['Activation.json', 'Activation.lock'] as $filename) {
                    if (strcasecmp($entry, $filename) === 0) {
                        throw new ActivationException('Activation registry requires canonical Project directory.');
                    }
                }
            }
            return ['packages' => [], 'kits' => []];
        }
        $path = $this->path();
        $this->assertProjectIfPresent();
        $this->assertExactFilenameIfPresent();
        // Legacy route loaders can read a lowercase project/ tree, but a
        // registry found there must not masquerade as canonical Project/.
        if (file_exists($path) || is_link($path)) {
            $this->assertCanonicalProjectName();
        }
        if (!file_exists($path) && !is_link($path)) {
            try {
                $packages = $this->sanitizeLegacyPackages(
                    (new PackageStateStore($this->packageDirectory(), $this))->readLegacy());
                $kits = (new KitStateStore($this->kitDirectory(), $this))->readLegacy();
                return $this->normalize($packages, $kits);
            } catch (PackageException|KitException $exception) {
                throw new ActivationException('Legacy activation state is invalid.', 0, $exception);
            }
        }
        $this->assertPhysicalFile($path);
        $size = @filesize($path);
        if ($size === false || $size > self::MAX_BYTES) {
            throw new ActivationException('Activation registry is too large or unavailable.');
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new ActivationException('Activation registry cannot be read.');
        }
        try {
            $shape = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
            $value = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ActivationException('Activation registry is invalid JSON.', 0, $exception);
        }
        if (!($shape instanceof \stdClass)
            || !(($shape->packages ?? null) instanceof \stdClass)
            || !(($shape->kits ?? null) instanceof \stdClass)
            || !is_array($value) || count($value) !== 3
            || array_diff(array_keys($value), ['format', 'packages', 'kits']) !== []
            || $value['format'] !== 1 || !is_array($value['packages']) || !is_array($value['kits'])) {
            throw new ActivationException('Activation registry format is unsupported.');
        }
        foreach (get_object_vars($shape->packages) as $record) {
            if (!($record instanceof \stdClass)
                || !(($record->files ?? null) instanceof \stdClass)
                || (isset($record->contribution_snapshot)
                    && !($record->contribution_snapshot instanceof \stdClass))) {
                throw new ActivationException('Activation registry Package record shape is invalid.');
            }
        }
        foreach (get_object_vars($shape->kits) as $record) {
            if (!($record instanceof \stdClass)
                || !(($record->definition ?? null) instanceof \stdClass)
                || !(($record->published ?? null) instanceof \stdClass)
                || !is_array($record->requires ?? null)) {
                throw new ActivationException('Activation registry Kit record shape is invalid.');
            }
        }
        return $this->normalize($value['packages'], $value['kits']);
    }

    /** @return array<string,array<string,mixed>> */
    public function packages(): array
    {
        return $this->read()['packages'];
    }

    /** @return array<string,array<string,mixed>> */
    public function kits(): array
    {
        return $this->read()['kits'];
    }

    /** SHA-256 of normalized logical state, including a legacy-only or empty app. */
    public function currentFingerprint(): string
    {
        $state = $this->read();
        return hash('sha256', $this->encode($state['packages'], $state['kits']));
    }

    /** @param array<string,array<string,mixed>> $records */
    public function fingerprintWithPackages(array $records): string
    {
        return $this->fingerprintWithState($records, $this->kits());
    }

    /** @param array<string,array<string,mixed>> $records */
    public function fingerprintWithKits(array $records): string
    {
        return $this->fingerprintWithState($this->packages(), $records);
    }

    /** @param array<string,array<string,mixed>> $packages @param array<string,array<string,mixed>> $kits */
    public function fingerprintWithState(array $packages, array $kits): string
    {
        return hash('sha256', $this->encode($packages, $kits));
    }

    /** @param array<string,array<string,mixed>> $records */
    public function writePackages(array $records): void
    {
        $this->withLock(function () use ($records): void {
            $this->writeCombined($records, $this->kits());
        });
    }

    /** @param array<string,array<string,mixed>> $records */
    public function writeKits(array $records): void
    {
        $this->withLock(function () use ($records): void {
            $this->writeCombined($this->packages(), $records);
        });
    }

    /**
     * Commit both domains in one replacement. Callers must recheck their
     * ChangePlan inside withLock before writing, since the store alone cannot
     * know which reviewed fingerprint a lifecycle operation expected.
     *
     * @param array<string,array<string,mixed>> $packages
     * @param array<string,array<string,mixed>> $kits
     */
    public function writeCombined(array $packages, array $kits): void
    {
        $this->withLock(function () use ($packages, $kits): void {
            // Never replace a corrupt canonical file or import malformed old
            // state merely because a caller supplied a plausible new map.
            $this->read();
            $json = $this->encode($packages, $kits);
            $this->assertProjectIfPresent();
            $directory = $this->projectDirectory();
            if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new ActivationException('Activation registry directory cannot be created.');
            }
            $this->assertProjectIfPresent();
            $this->assertExactFilenameIfPresent();
            if (file_exists($this->path()) || is_link($this->path())) {
                $this->assertPhysicalFile($this->path());
            }
            $temporary = @tempnam($directory, '.Activation-');
            if ($temporary === false) {
                throw new ActivationException('Activation registry temporary file cannot be created.');
            }
            try {
                $handle = @fopen($temporary, 'wb');
                if ($handle === false) {
                    throw new ActivationException('Activation registry temporary file cannot be opened.');
                }
                try {
                    $length = strlen($json);
                    $offset = 0;
                    while ($offset < $length) {
                        $written = @fwrite($handle, substr($json, $offset));
                        if ($written === false || $written === 0) {
                            throw new ActivationException('Activation registry could not be written.');
                        }
                        $offset += $written;
                    }
                    if (!@fflush($handle)) {
                        throw new ActivationException('Activation registry could not be flushed.');
                    }
                } finally {
                    fclose($handle);
                }
                if (!@rename($temporary, $this->path())) {
                    throw new ActivationException('Activation registry could not be replaced.');
                }
            } finally {
                if (is_file($temporary)) { @unlink($temporary); }
            }
        });
    }

    /**
     * One lock across Package and Kit mutations. Reentry within the same
     * Application is safe, allowing adapters to commit under a manager lock.
     * A different process waits for the entire reviewed lifecycle operation.
     */
    public function withLock(callable $callback): mixed
    {
        if ($this->lockDepth > 0) {
            ++$this->lockDepth;
            try { return $callback(); }
            finally { --$this->lockDepth; }
        }
        // A mutation must never create a second Project tree beside a legacy
        // casing variant or write through a case-insensitive path alias.
        $this->assertCanonicalProjectName();
        $this->assertProjectIfPresent();
        $directory = $this->projectDirectory();
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new ActivationException('Activation registry directory cannot be created.');
        }
        $this->assertProjectIfPresent();
        $this->assertExactFilenameIfPresent();
        $lockPath = $directory . '/Activation.lock';
        if (file_exists($lockPath) || is_link($lockPath)) {
            try { $this->assertPhysicalFile($lockPath); }
            catch (ActivationException $exception) {
                throw new ActivationLockException('Activation registry lock path is unsafe.', 0, $exception);
            }
        }
        $handle = @fopen($lockPath, 'c+b');
        if ($handle === false) {
            throw new ActivationLockException('Activation registry lock cannot be opened.');
        }
        try {
            if (!@flock($handle, LOCK_EX)) {
                throw new ActivationLockException('Activation registry lock cannot be acquired.');
            }
            $this->lockDepth = 1;
            try { return $callback(); }
            finally { $this->lockDepth = 0; }
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Canonical state wins after its first creation. Legacy files stay inert
     * for migration review; divergent or unreadable legacy data is reported
     * without revealing source labels or ownership contents.
     *
     * @return list<string>
     */
    public function legacyWarnings(): array
    {
        if ($this->projectRootForRead() !== 'Project') {
            $this->read();
            return [];
        }
        if (!file_exists($this->path()) && !is_link($this->path())) { return []; }
        $state = $this->read();
        $warnings = [];
        foreach ([
            ['Package', $this->packageDirectory() . '/State.json',
                fn (): array => $this->normalize($this->sanitizeLegacyPackages(
                    (new PackageStateStore($this->packageDirectory(), $this))->readLegacy()),
                    $state['kits'])['packages'],
                $state['packages']],
            ['Kit', $this->kitDirectory() . '/State.json',
                fn (): array => $this->normalize($state['packages'],
                    (new KitStateStore($this->kitDirectory(), $this))->readLegacy())['kits'],
                $state['kits']],
        ] as [$kind, $file, $readLegacy, $current]) {
            if (!file_exists($file) && !is_link($file)) { continue; }
            try {
                if ($readLegacy() !== $current) {
                    $warnings[] = $kind . ' legacy state differs from the activation registry.';
                }
            } catch (Throwable) {
                $warnings[] = $kind . ' legacy state is unreadable or invalid.';
            }
        }
        return $warnings;
    }

    /** Read-only permission hint; actual mutation still verifies every operation. */
    public function storageHealth(): array
    {
        $path = $this->path();
        $directory = $this->projectDirectory();
        // Reads may tolerate one legacy project/ root; lifecycle writes require
        // the exact Project/ root and must not create a second tree beside it.
        foreach ($this->projectRootEntries() as $entry) {
            if ($entry !== 'Project') {
                return ['exists' => false, 'writable' => false];
            }
        }
        return ['exists' => is_file($path),
            'writable' => is_file($path) ? is_writable($path)
                : (is_dir($directory) ? is_writable($directory) : is_writable($this->basePath))];
    }

    /** @param array<string,array<string,mixed>> $packages @param array<string,array<string,mixed>> $kits */
    private function encode(array $packages, array $kits): string
    {
        $state = $this->normalize($packages, $kits);
        foreach ($state['packages'] as &$record) {
            $record['files'] = (object) $record['files'];
        }
        unset($record);
        foreach ($state['kits'] as &$record) {
            $record['definition'] = (object) $record['definition'];
            $record['published'] = (object) $record['published'];
        }
        unset($record);
        try {
            $json = json_encode(['format' => 1, 'packages' => (object) $state['packages'],
                'kits' => (object) $state['kits']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ActivationException('Activation registry could not be encoded.', 0, $exception);
        }
        if (!is_string($json) || strlen($json) + 1 > self::MAX_BYTES) {
            throw new ActivationException('Activation registry is too large.');
        }
        return $json . "\n";
    }

    /** @param array<mixed> $packages @param array<mixed> $kits
     *  @return array{packages:array<string,array<string,mixed>>,kits:array<string,array<string,mixed>>}
     */
    private function normalize(array $packages, array $kits): array
    {
        foreach ($packages as $name => $record) {
            if (is_array($record) && array_key_exists('contribution_snapshot', $record)
                && PackageSnapshot::parse($record['contribution_snapshot'], (string) $name) === null) {
                throw new ActivationException('Activation registry Package snapshot is invalid.');
            }
        }
        try {
            $packages = PackageStateStore::validateRecords($packages);
            $kits = KitStateStore::validateRecords($kits);
        } catch (PackageException|KitException $exception) {
            throw new ActivationException('Activation registry contains invalid records.', 0, $exception);
        }
        foreach ($packages as $name => &$record) {
            if (!self::safePackageSource($record, $name)) {
                throw new ActivationException('Activation registry Package source label is unsafe.');
            }
            $ordered = [];
            foreach (['enabled', 'source_kind', 'source', 'files', 'contribution_snapshot'] as $key) {
                if (array_key_exists($key, $record)) { $ordered[$key] = $record[$key]; }
            }
            $record = $ordered;
            ksort($record['files'], SORT_STRING);
        }
        unset($record);
        foreach ($kits as &$record) {
            $ordered = [];
            foreach (['enabled', 'source_kind', 'source', 'definition', 'published', 'requires'] as $key) {
                $ordered[$key] = $record[$key];
            }
            $record = $ordered;
            ksort($record['definition'], SORT_STRING);
            ksort($record['published'], SORT_STRING);
            sort($record['requires'], SORT_STRING);
        }
        unset($record);
        return ['packages' => $packages, 'kits' => $kits];
    }

    private function path(): string { return $this->projectDirectory() . '/Activation.json'; }

    /**
     * Older hand-authored state may contain an unsafe source URL. Import an
     * opaque stable label instead of copying credentials into Activation.json.
     *
     * @param array<string,array<string,mixed>> $packages
     * @return array<string,array<string,mixed>>
     */
    private function sanitizeLegacyPackages(array $packages): array
    {
        foreach ($packages as $name => &$record) {
            if (!self::safePackageSource($record, $name)) {
                $record['source'] = $record['source_kind'] === 'manual' ? 'manual'
                    : $record['source_kind'] . ':legacy#'
                        . substr(hash('sha256', $record['source']), 0, 12);
            }
        }
        unset($record);
        return $packages;
    }

    /** @param array<string,mixed> $record */
    private static function safePackageSource(array $record, string $name): bool
    {
        $source = $record['source'];
        return match ($record['source_kind']) {
            'manual' => $source === 'manual',
            'local' => preg_match('/\Alocal:' . preg_quote($name, '/') . '#[a-f0-9]{12}\z/D', $source) === 1
                || preg_match('/\Alocal:legacy#[a-f0-9]{12}\z/D', $source) === 1,
            'git' => preg_match('/\Agit:[A-Za-z0-9.-]+\/' . preg_quote($name, '/')
                . '#[a-f0-9]{12}\z/D', $source) === 1
                || preg_match('/\Agit:legacy#[a-f0-9]{12}\z/D', $source) === 1,
            default => false,
        };
    }
    private function projectDirectory(): string { return $this->basePath . '/Project'; }
    private function packageDirectory(): string { return $this->projectDirectory() . '/Packages'; }
    private function kitDirectory(): string { return $this->projectDirectory() . '/Kits'; }

    private function assertProjectIfPresent(): void
    {
        $path = $this->projectDirectory();
        if (!file_exists($path) && !is_link($path)) { return; }
        try { PackageFiles::assertPhysical($path); }
        catch (PackageException $exception) {
            throw new ActivationException('Activation registry directory is unsafe.', 0, $exception);
        }
        if (!is_dir($path)) {
            throw new ActivationException('Activation registry directory is not a directory.');
        }
    }

    /** @return list<string> */
    private function projectRootEntries(): array
    {
        $entries = @scandir($this->basePath);
        if ($entries === false) {
            throw new ActivationException('Application root cannot be inspected.');
        }
        $matches = [];
        foreach ($entries as $entry) {
            if (strcasecmp($entry, 'Project') === 0) {
                $matches[] = $entry;
            }
        }
        return $matches;
    }

    private function projectRootForRead(): ?string
    {
        $roots = $this->projectRootEntries();
        if (count($roots) > 1) {
            throw new ActivationException('Project directory conflicts by casing.');
        }
        return $roots[0] ?? null;
    }

    private function assertCanonicalProjectName(): void
    {
        foreach ($this->projectRootEntries() as $entry) {
            if ($entry !== 'Project') {
                throw new ActivationException('Project directory conflicts by casing.');
            }
        }
    }

    private function assertPhysicalFile(string $path): void
    {
        try { PackageFiles::assertPhysical($path); }
        catch (PackageException $exception) {
            throw new ActivationException('Activation registry path is unsafe.', 0, $exception);
        }
        if (!is_file($path)) {
            throw new ActivationException('Activation registry path is not a file.');
        }
    }

    private function assertExactFilenameIfPresent(): void
    {
        $directory = $this->projectDirectory();
        if (!is_dir($directory)) { return; }
        $entries = @scandir($directory);
        if ($entries === false) {
            throw new ActivationException('Activation registry directory cannot be read.');
        }
        foreach ($entries as $entry) {
            foreach (['Activation.json', 'Activation.lock'] as $canonical) {
                if (strcasecmp($entry, $canonical) === 0 && $entry !== $canonical) {
                    throw new ActivationException('Activation registry filename conflicts by casing.');
                }
            }
        }
    }
}
