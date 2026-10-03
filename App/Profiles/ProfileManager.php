<?php

declare(strict_types=1);

namespace App\Profiles;

use App\Bundles\BundleException;
use App\Bundles\BundlePath;
use App\Changes\ChangeAction;
use App\Changes\ChangePlan;
use App\Changes\ChangeResult;
use App\Contributions\ContributionOwner;
use JsonException;
use Throwable;
use WeakMap;

/**
 * Applies one optional frontend profile through a reviewable ChangePlan. The
 * plan carries hashes only; source bytes remain with this manager instance.
 * Publication never installs Node packages, executes PHP source, or replaces
 * a developer-owned file. Removal requires byte-for-byte owned files.
 */
final class ProfileManager
{
    private const MARKER = 'Project/Frontend/Profile.json';
    private const CONFIG = 'Config/Frontend.php';

    private readonly string $root;

    /** @var WeakMap<ChangePlan,array{mode:string,files:array<string,string>,config:string}> */
    private WeakMap $prepared;

    public function __construct(string $applicationRoot)
    {
        $root = realpath($applicationRoot);
        if ($root === false || !is_dir($root)) {
            throw new ProfileException('Application root is unavailable.');
        }
        $this->root = $root;
        $this->prepared = new WeakMap();
    }

    /** @return list<string> */
    public static function available(): array { return ['vite', 'react', 'vue']; }

    /** @return array{name:?string,spa:bool,valid:bool} */
    public function inspect(): array
    {
        $marker = $this->read(self::MARKER);
        if ($marker === null) return ['name' => null, 'spa' => false, 'valid' => true];
        try {
            $state = $this->state($marker);
            return ['name' => $state['name'], 'spa' => $state['spa'],
                'valid' => $this->matchesInstalled($state)];
        } catch (ProfileException) {
            return ['name' => null, 'spa' => false, 'valid' => false];
        }
    }

    public function planInstall(string $name, bool $spa = false): ChangePlan
    {
        $this->assertName($name);
        $owner = new ContributionOwner('application', 'frontend-profile');
        $files = ProfileTemplates::files($name);
        $before = $this->read(self::CONFIG);
        if ($before === null) throw new ProfileException('Frontend configuration is unavailable.');
        $after = self::configure($before, $name, $spa, false);
        $hashes = [];
        foreach ($files as $path => $contents) $hashes[$path] = hash('sha256', $contents);
        $marker = json_encode(['version' => 1, 'name' => $name, 'spa' => $spa,
            'files' => $hashes, 'config_before' => hash('sha256', $before),
            'config_after' => hash('sha256', $after)],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $files[self::MARKER] = $marker;
        $actions = [];
        $preconditions = [self::CONFIG => hash('sha256', $before)];
        $conflicts = [];
        if ($this->read(self::MARKER) !== null) $conflicts[] = 'A frontend profile is already selected.';
        if ($after === $before) $conflicts[] = 'Frontend configuration cannot select the requested profile safely.';
        foreach ($files as $path => $contents) {
            $existing = $this->read($path);
            $preconditions[$path] = null;
            if ($existing !== null || file_exists($this->target($path))) {
                $conflicts[] = 'Generated path already exists: ' . $path;
            }
            $actions[] = new ChangeAction('create', $path, $owner, null,
                hash('sha256', $contents), 'review', 'Publish the optional frontend source.', 'file');
        }
        $actions[] = new ChangeAction('modify', self::CONFIG, $owner,
            hash('sha256', $before), hash('sha256', $after), 'review',
            'Select the frontend adapter and optional SPA navigation policy.', 'configuration');
        $plan = new ChangePlan('profile:apply', $name, $owner, $actions,
            ['Adapter: vite. No Package or Kit activation is required.',
                'Node dependencies are not installed automatically.',
                'Static route review cannot prove conflicts in dynamic route declarations.'],
            $conflicts, $preconditions);
        $this->prepared[$plan] = ['mode' => 'install', 'files' => $files, 'config' => $after];
        return $plan;
    }

    public function planRemove(string $name): ChangePlan
    {
        $this->assertName($name);
        $marker = $this->read(self::MARKER);
        if ($marker === null) throw new ProfileException('No frontend profile is selected.');
        $state = $this->state($marker);
        if ($state['name'] !== $name) throw new ProfileException('A different frontend profile is selected.');
        $before = $this->read(self::CONFIG);
        if ($before === null) throw new ProfileException('Frontend configuration is unavailable.');
        $after = self::configure($before, $name, $state['spa'], true);
        $owner = new ContributionOwner('application', 'frontend-profile');
        $files = [];
        $actions = [];
        $preconditions = [self::CONFIG => $state['config_after'],
            self::MARKER => hash('sha256', $marker)];
        $conflicts = [];
        if (hash('sha256', $before) !== $state['config_after']
            || hash('sha256', $after) !== $state['config_before']) {
            $conflicts[] = 'Frontend configuration changed after profile installation.';
        }
        foreach ($state['files'] as $path => $hash) {
            $content = $this->read($path);
            $preconditions[$path] = $hash;
            if ($content === null || hash('sha256', $content) !== $hash) {
                $conflicts[] = 'Owned profile file changed or is missing: ' . $path;
            } else {
                $files[$path] = $content;
            }
            $actions[] = new ChangeAction('delete', $path, $owner, $hash, null,
                'destructive', 'Remove only unmodified profile-owned source.', 'file');
        }
        $files[self::MARKER] = $marker;
        $actions[] = new ChangeAction('delete', self::MARKER, $owner,
            hash('sha256', $marker), null, 'destructive', 'Remove the profile ownership marker.', 'file');
        $actions[] = new ChangeAction('modify', self::CONFIG, $owner,
            hash('sha256', $before), hash('sha256', $after), 'review',
            'Restore the frontend selection before this profile.', 'configuration');
        $plan = new ChangePlan('profile:remove', $name, $owner, $actions,
            ['Removal leaves npm-installed node_modules and unowned files for deliberate cleanup.'],
            $conflicts, $preconditions);
        $this->prepared[$plan] = ['mode' => 'remove', 'files' => $files, 'config' => $after];
        return $plan;
    }

    /**
     * Recheck all reviewed bytes under one application-local lock. A stale plan
     * cannot overwrite a concurrent edit. Failed installation removes only
     * files this call created, while changed files remain untouched.
     */
    public function apply(ChangePlan $plan): ChangeResult
    {
        $prepared = $this->prepared[$plan] ?? null;
        if ($prepared === null || $plan->hasConflicts()) {
            throw new ProfileException('Frontend profile plan is invalid or blocked.');
        }
        $storage = $this->root . '/Storage';
        if (!is_dir($storage) && !@mkdir($storage, 0775) && !is_dir($storage)) {
            throw new ProfileException('Profile lock directory is unavailable.');
        }
        $lockPath = $this->target('Storage/.frontend-profile.lock');
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) throw new ProfileException('Profile lock is unavailable.');
        $created = [];
        $configBefore = null;
        $configWritten = false;
        try {
            if (!flock($lock, LOCK_EX)) throw new ProfileException('Profile lock could not be acquired.');
            foreach ($plan->preconditions as $path => $expected) {
                $actual = $this->read($path);
                if (($actual === null ? null : hash('sha256', $actual)) !== $expected) {
                    throw new ProfileException('Frontend profile plan is stale. Review it again.');
                }
            }
            $configBefore = $this->read(self::CONFIG);
            if ($configBefore === null) throw new ProfileException('Frontend configuration is unavailable.');
            if ($prepared['mode'] === 'install') {
                foreach ($prepared['files'] as $path => $contents) {
                    $this->create($path, $contents);
                    $created[$path] = hash('sha256', $contents);
                }
                $this->replaceConfig($prepared['config']);
                $configWritten = true;
            } else {
                // A refused Config replacement leaves every owned file intact.
                $this->replaceConfig($prepared['config']);
                $configWritten = true;
                foreach (array_keys($prepared['files']) as $path) {
                    $expected = hash('sha256', $prepared['files'][$path]);
                    if ($this->read($path) === null
                        || hash('sha256', (string) $this->read($path)) !== $expected
                        || !@unlink($this->target($path))) {
                        throw new ProfileException('Profile removal stopped; inspect the remaining owned files.');
                    }
                }
            }
            $verified = $this->read(self::CONFIG) === $prepared['config'];
            foreach ($prepared['files'] as $path => $contents) {
                $actual = $this->read($path);
                $verified = $verified && ($prepared['mode'] === 'install'
                    ? $actual === $contents : $actual === null);
            }
            if (!$verified) throw new ProfileException('Profile publication could not be verified.');
            return new ChangeResult($plan, $plan->actions, null, [], true);
        } catch (Throwable $exception) {
            // Installation is create-first/config-last, so a failed config
            // replacement cannot leave a selected but incomplete profile.
            if ($prepared['mode'] === 'install') {
                if ($configWritten && $configBefore !== null) {
                    try { $this->replaceConfig($configBefore); } catch (Throwable) {}
                }
                foreach (array_reverse($created, true) as $path => $hash) {
                    try {
                        $current = $this->read($path);
                        if ($current !== null && hash('sha256', $current) === $hash) {
                            @unlink($this->target($path));
                        }
                    } catch (Throwable) {} // Never follow a path replaced during cleanup.
                }
            } elseif ($configWritten) {
                // Reconstitute only the exact reviewed bytes; if another
                // process changed a file, retain it rather than overwriting.
                foreach ($prepared['files'] as $path => $contents) {
                    try {
                        if ($this->read($path) === null) $this->create($path, $contents);
                    } catch (Throwable) {} // A changed path requires manual review.
                }
                if ($configBefore !== null) {
                    try { $this->replaceConfig($configBefore); } catch (Throwable) {}
                }
            }
            throw new ProfileException('Frontend profile could not be applied safely; inspect the project before retrying.',
                0, $exception);
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array{name:string,spa:bool,files:array<string,string>,config_after:string} $state */
    private function matchesInstalled(array $state): bool
    {
        $config = $this->read(self::CONFIG);
        if ($config === null || hash('sha256', $config) !== $state['config_after']) return false;
        foreach ($state['files'] as $path => $hash) {
            $content = $this->read($path);
            if ($content === null || hash('sha256', $content) !== $hash) return false;
        }
        return true;
    }

    /** @return array{name:string,spa:bool,files:array<string,string>,config_before:string,config_after:string} */
    private function state(string $contents): array
    {
        try { $state = json_decode($contents, true, 8, JSON_THROW_ON_ERROR); }
        catch (JsonException $exception) { throw new ProfileException('Profile marker is invalid.', 0, $exception); }
        if (!is_array($state) || ($state['version'] ?? null) !== 1
            || !is_string($state['name'] ?? null) || !is_bool($state['spa'] ?? null)) {
            throw new ProfileException('Profile marker is invalid.');
        }
        $this->assertName($state['name']);
        $expected = array_keys(ProfileTemplates::files($state['name']));
        if (!is_array($state['files'] ?? null) || array_keys($state['files']) !== $expected) {
            throw new ProfileException('Profile marker has unexpected paths.');
        }
        foreach (array_merge(array_values($state['files']),
            [$state['config_before'] ?? null, $state['config_after'] ?? null]) as $hash) {
            if (!is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1) {
                throw new ProfileException('Profile marker has invalid fingerprints.');
            }
        }
        return $state;
    }

    private function assertName(string $name): void
    {
        if (!in_array($name, self::available(), true)) {
            throw new ProfileException('Profile must be vite, react, or vue.');
        }
    }

    /** Configuration edits are exact, narrow replacements, never PHP evaluation. */
    private static function configure(string $source, string $name, bool $spa, bool $reverse): string
    {
        $entry = $name === 'react' ? 'Src/Main.jsx' : 'Src/Main.js';
        $replacements = [
            "'adapter' => 'none'," => "'adapter' => 'vite',",
            "'entries' => []," => "'entries' => ['app' => '{$entry}'],",
            "'development' => ['enabled' => false," => "'development' => ['enabled' => true,",
        ];
        if ($spa) {
            $replacements["'spa' => ['enabled' => false, 'prefix' => '/', 'view' => null, 'except' => []],"]
                = "'spa' => ['enabled' => true, 'prefix' => '/frontend', 'view' => 'Frontend.App', 'except' => []],";
        }
        if ($reverse) $replacements = array_flip($replacements);
        foreach ($replacements as $from => $to) {
            if (substr_count($source, $from) !== 1) return $source;
            $source = str_replace($from, $to, $source);
        }
        return $source;
    }

    private function target(string $relative): string
    {
        try { return BundlePath::target($this->root, $relative); }
        catch (BundleException $exception) {
            throw new ProfileException('Profile target is unsafe or conflicts by casing.', 0, $exception);
        }
    }

    private function read(string $relative): ?string
    {
        $path = $this->target($relative);
        if (!is_file($path)) return null;
        $contents = @file_get_contents($path);
        if (!is_string($contents)) throw new ProfileException('Profile target cannot be read.');
        return $contents;
    }

    private function create(string $relative, string $contents): void
    {
        $path = $this->target($relative);
        $parent = dirname($path);
        if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) {
            throw new ProfileException('Profile target directory cannot be created.');
        }
        $this->target($relative); // Recheck after directory creation.
        $handle = @fopen($path, 'x');
        if ($handle === false) throw new ProfileException('Profile target already exists or cannot be created.');
        try {
            $offset = 0;
            while ($offset < strlen($contents)) {
                $written = fwrite($handle, substr($contents, $offset));
                if ($written === false || $written === 0) {
                    throw new ProfileException('Profile target could not be written.');
                }
                $offset += $written;
            }
            if (!fflush($handle)) throw new ProfileException('Profile target could not be flushed.');
        } catch (Throwable $exception) {
            fclose($handle);
            @unlink($path);
            throw $exception;
        }
        fclose($handle);
    }

    private function replaceConfig(string $contents): void
    {
        $path = $this->target(self::CONFIG);
        $tmp = @tempnam(dirname($path), '.sqprofile-');
        if ($tmp === false) throw new ProfileException('Profile configuration staging failed.');
        try {
            if (@file_put_contents($tmp, $contents, LOCK_EX) !== strlen($contents)
                || !@rename($tmp, $path)) {
                throw new ProfileException('Profile configuration replacement failed.');
            }
        } finally { if (is_file($tmp)) @unlink($tmp); }
    }
}
