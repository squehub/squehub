<?php

declare(strict_types=1);

namespace App\Profiler;

use App\Support\PhysicalPath;
use JsonException;
use Throwable;

/**
 * Private local development store. One namespace lock covers writes, reads,
 * and deterministic retention for cooperating processes. It is not a
 * distributed store. Runtime paths are fixed below the Application root;
 * neither request data nor profile metadata is used as a filesystem path.
 */
final class FileProfilerStore implements ProfilerStore
{
    private string $base;
    private string $root;
    /** @var list<string> */
    private array $parts;

    public function __construct(string $root, string $applicationRoot,
        private ProfilerSettings $settings)
    {
        $base = realpath($applicationRoot);
        if ($base === false || !is_dir($base)) {
            throw new ProfilerException('Profiler application root is unavailable.');
        }
        $this->base = rtrim(str_replace('\\', '/', $base), '/');
        $requested = rtrim(str_replace('\\', '/', $root), '/');
        if ($requested === '' || preg_match('/[\x00-\x1f\x7f]/', $requested)) {
            throw new ProfilerException('Profiler root must be inside the Application.');
        }
        // Walk the requested spelling back to the existing Application root.
        // A Windows 8.3 alias can make a textual prefix check reject that root.
        $cursor = $requested;
        $parts = [];
        while (!PhysicalPath::same($cursor, $this->base)) {
            $parent = str_replace('\\', '/', dirname($cursor));
            if ($parent === $cursor || count($parts) > 64) {
                throw new ProfilerException('Profiler root must be inside the Application.');
            }
            $parts[] = basename($cursor);
            $cursor = $parent;
        }
        if (!PhysicalPath::unlinked($cursor) || $parts === []) {
            throw new ProfilerException('Profiler root must be inside the Application.');
        }
        $this->parts = array_reverse($parts);
        foreach ($this->parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new ProfilerException('Profiler root path is invalid.');
            }
        }
        $this->root = $this->base . '/' . implode('/', $this->parts);
    }

    public function save(ProfileRecord $profile, int $now): void
    {
        if ($profile->bytes() > $this->settings->maxProfileBytes) {
            throw new ProfilerException('Profile exceeds the configured byte limit.');
        }
        $this->locked(function () use ($profile, $now): void {
            // Detect existing corruption before publishing another completed
            // profile. The consumer failure remains visible in Obs status.
            $this->prune($now);
            $path = $this->path($profile->id);
            $this->assertEntry($path);
            if (file_exists($path)) throw new ProfilerException('Profile identifier already exists.');
            $this->write($path, $profile);
            $this->prune($now);
        });
    }

    public function latest(int $limit, int $now): array
    {
        if ($limit < 1 || $limit > $this->settings->maxProfiles) {
            throw new ProfilerException('Profile list limit is invalid.');
        }
        return $this->locked(function () use ($limit, $now): array {
            $profiles = $this->prune($now);
            usort($profiles, ArrayProfilerStore::newestFirst(...));
            return array_slice($profiles, 0, $limit);
        });
    }

    public function find(string $id, int $now): ?ProfileRecord
    {
        ProfileRecord::identifier($id);
        return $this->locked(function () use ($id, $now): ?ProfileRecord {
            foreach ($this->prune($now) as $profile) {
                if ($profile->id === $id) return $profile;
            }
            return null;
        });
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function locked(callable $callback): mixed
    {
        $this->ensureRoot();
        $lockPath = $this->root . '/.profiles.lock';
        $this->assertEntry($lockPath);
        $lock = @fopen($lockPath, 'c+b');
        if ($lock === false) throw new ProfilerException('Profiler lock cannot be opened.');
        try {
            if (!@flock($lock, LOCK_EX)) throw new ProfilerException('Profiler lock cannot be acquired.');
            try {
                $this->ensureRoot();
                return $callback();
            } finally {
                @flock($lock, LOCK_UN);
            }
        } finally {
            fclose($lock);
        }
    }

    /** Resolve each path component after creation, rejecting links and junction escapes. */
    private function ensureRoot(): void
    {
        $parent = $this->base;
        foreach ($this->parts as $part) {
            $candidate = $parent . '/' . $part;
            if (@lstat($candidate) !== false && !PhysicalPath::unlinked($candidate)) {
                throw new ProfilerException('Profiler directory is unsafe.');
            }
            if (!is_dir($candidate) && !@mkdir($candidate, 0700) && !is_dir($candidate)) {
                throw new ProfilerException('Profiler directory cannot be created.');
            }
            if (!is_dir($candidate) || !PhysicalPath::unlinked($candidate)
                || !PhysicalPath::same(dirname($candidate), $parent)) {
                throw new ProfilerException('Profiler directory escaped the Application.');
            }
            $parent = $candidate;
        }
        if (!PhysicalPath::same($parent, $this->root)) {
            throw new ProfilerException('Profiler directory is unsafe.');
        }
    }

    private function path(string $id): string
    {
        ProfileRecord::identifier($id);
        return $this->root . '/' . $id . '.json';
    }

    private function assertEntry(string $path): void
    {
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new ProfilerException('Profiler entry path is unsafe.');
        }
    }

    private function write(string $path, ProfileRecord $profile): void
    {
        try {
            $json = json_encode($profile->toArray(),
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $failure) {
            throw new ProfilerException('Profile cannot be encoded.', 0, $failure);
        }
        if (strlen($json) > $this->settings->maxProfileBytes) {
            throw new ProfilerException('Profile exceeds the configured byte limit.');
        }
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $stream = @fopen($temporary, 'x+b');
        if ($stream === false) throw new ProfilerException('Profile cannot be written.');
        try {
            $offset = 0;
            while ($offset < strlen($json)) {
                $written = @fwrite($stream, substr($json, $offset));
                if ($written === false || $written === 0) {
                    throw new ProfilerException('Profile cannot be written.');
                }
                $offset += $written;
            }
            if (!@fflush($stream)) throw new ProfilerException('Profile cannot be flushed.');
        } catch (Throwable $failure) {
            fclose($stream);
            @unlink($temporary);
            throw $failure;
        } finally {
            if (is_resource($stream)) fclose($stream);
        }
        // The completed JSON is renamed while the namespace lock is held;
        // readers never observe a partially written profile.
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new ProfilerException('Profile cannot be published.');
        }
    }

    private function read(string $path): ProfileRecord
    {
        $this->assertEntry($path);
        $stream = @fopen($path, 'rb');
        if ($stream === false) throw new ProfilerException('Profile cannot be read.');
        try { $json = @stream_get_contents($stream, $this->settings->maxProfileBytes + 1); }
        finally { fclose($stream); }
        if ($json === false || strlen($json) > $this->settings->maxProfileBytes) {
            throw new ProfilerException('Profile record is corrupt.');
        }
        try { $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR); }
        catch (JsonException $failure) {
            throw new ProfilerException('Profile record is corrupt.', 0, $failure);
        }
        $profile = ProfileRecord::fromArray($data);
        if (count($profile->events) > $this->settings->maxEvents) {
            throw new ProfilerException('Profile record is corrupt.');
        }
        return $profile;
    }

    /** @return list<ProfileRecord> */
    private function prune(int $now): array
    {
        $names = @scandir($this->root);
        // Normal retention can temporarily hold exactly one newly published
        // record over the configured count. A much larger directory is treated
        // as unsafe rather than materializing an unbounded set of JSON files.
        if ($names === false || count($names) > $this->settings->maxProfiles + 4) {
            throw new ProfilerException('Profiler directory cannot be inspected safely.');
        }
        $profiles = [];
        $bytes = 0;
        foreach ($names as $name) {
            if (preg_match('/\A[a-f0-9]{32}\.json\z/D', $name) !== 1) continue;
            $profile = $this->read($this->root . '/' . $name);
            if ($name !== $profile->id . '.json') {
                throw new ProfilerException('Profile record is corrupt.');
            }
            $bytes += $profile->bytes();
            if ($bytes > $this->settings->maxBytes + $this->settings->maxProfileBytes) {
                throw new ProfilerException('Profiler directory exceeds its safe scan budget.');
            }
            $profiles[] = $profile;
        }
        usort($profiles, ArrayProfilerStore::oldestFirst(...));
        $remaining = count($profiles);
        $kept = [];
        foreach ($profiles as $profile) {
            if ($profile->recordedAt <= $now - $this->settings->maxAgeSeconds
                || $remaining > $this->settings->maxProfiles
                || $bytes > $this->settings->maxBytes) {
                $path = $this->path($profile->id);
                $this->assertEntry($path);
                if (!@unlink($path)) throw new ProfilerException('Profile cannot be pruned.');
                --$remaining;
                $bytes -= $profile->bytes();
                continue;
            }
            $kept[] = $profile;
        }
        return $kept;
    }
}
