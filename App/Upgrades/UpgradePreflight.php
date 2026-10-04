<?php

declare(strict_types=1);

namespace App\Upgrades;

use App\Activation\ActivationStore;
use App\Bundles\BundlePath;
use App\Changes\ChangeAction;
use App\Changes\ChangePlan;
use App\Changes\ChangePlanMetadata;
use App\Contributions\ContributionOwner;
use App\Kits\KitDiscovery;
use App\Kits\KitName;
use App\Packages\PackageDiscovery;
use App\Packages\PackageFiles;
use App\Packages\PackageName;
use JsonException;
use Throwable;

/**
 * Inspects two physical source trees without booting either application.
 * PHP configuration, migrations, Package entries, and Kit hooks stay inert;
 * findings contain categories and hashes, never source or secret values.
 */
final class UpgradePreflight
{
    private const DIRECTORIES = ['App', 'Bootstrap', 'Config', 'Project', 'Database',
        'Assets', 'public', 'Scripts'];
    private const ROOT_FILES = ['composer.json', 'composer.lock', 'config.php', 'squehub'];
    private const MAX_FILES = 20000;
    private const MAX_FINDINGS = 1024;
    private const MAX_FILE_BYTES = 33554432;

    /**
     * The target is an operator-selected local source directory, not a URL or
     * package name. A report has no apply method: executable upgrades remain
     * separate, explicitly reviewed operations.
     */
    public function inspect(string $currentRoot, string $targetRoot): UpgradeReport
    {
        $current = $this->root($currentRoot);
        $target = $this->root($targetRoot);
        $findings = [];
        $currentFiles = $this->inventory($current, $findings, 'current');
        $targetFiles = $this->inventory($target, $findings, 'target');
        $this->composer($current, $target, $findings);

        $state = $this->activation($current, $findings);
        $owned = $this->ownedFiles($state, $findings);
        $this->packageAndKitRequirements($current, $target, $state, $findings);

        $actions = [];
        $preconditions = [];
        $lowerCurrent = [];
        foreach (array_keys($currentFiles) as $path) {
            $lowerCurrent[BundlePath::collisionKey($path)][] = $path;
        }
        foreach ($targetFiles as $path => $after) {
            if ($path === 'Project/Activation.json'
                || $path === 'Project/Activation.lock'
                || preg_match('~\AProject/(?:Packages|Kits)/State\.json\z~D', $path) === 1) {
                if (($currentFiles[$path] ?? null) !== $after) {
                    self::finding($findings, 'blocked', 'activation_state_separate', $path);
                }
                continue;
            }
            $equivalents = $lowerCurrent[BundlePath::collisionKey($path)] ?? [];
            if ($equivalents !== [] && !in_array($path, $equivalents, true)) {
                self::finding($findings, 'blocked', 'case_collision', $path);
                continue;
            }
            $before = $currentFiles[$path] ?? null;
            if ($before === $after) continue;
            $owner = self::owner($path);
            $kind = $before === null ? 'create' : 'modify';
            $risk = $kind === 'create' && !str_starts_with($path, 'Config/') ? 'low' : 'review';
            $actions[] = new ChangeAction($kind, $path, $owner, $before, $after, $risk);
            $preconditions[$path] = $before;
            if ($before !== null && isset($owned[$path]) && $owned[$path] !== $before) {
                self::finding($findings, 'blocked', 'owned_file_modified', $path);
            } elseif ($before !== null && $owner->type === 'application'
                && (str_starts_with($path, 'Project/') || str_starts_with($path, 'Assets/'))) {
                self::finding($findings, 'blocked', 'application_file_collision', $path);
            } elseif ($before !== null && str_starts_with($path, 'Config/')) {
                // Config is executable PHP. Without authoritative schema data,
                // a byte diff cannot prove that keys or defaults are compatible.
                self::finding($findings, 'unknown', 'config_semantics_unproven', $path);
            }
            if (str_starts_with($path, 'Database/Migrations/') && $kind === 'create') {
                self::finding($findings, 'review', 'new_migration_uninspected', $path);
            }
        }
        // A partial target tree cannot prove that an omitted framework file
        // should be removed. Report the gap rather than inventing a delete.
        foreach ($currentFiles as $path => $_before) {
            if (!isset($targetFiles[$path])
                && (str_starts_with($path, 'App/') || str_starts_with($path, 'Bootstrap/'))) {
                self::finding($findings, 'unknown', 'target_framework_file_missing', $path);
            }
        }
        usort($findings, static fn (array $a, array $b): int =>
            [$a['status'], $a['code'], $a['subject']] <=> [$b['status'], $b['code'], $b['subject']]);
        $conflicts = in_array('blocked', array_column($findings, 'status'), true)
            ? ['Upgrade preflight found blocked or unsafe changes.'] : [];
        $plan = new ChangePlan('upgrade:preflight', 'local-source',
            new ContributionOwner('framework', 'SqueHub'), $actions, [], $conflicts, $preconditions,
            new ChangePlanMetadata('upgrade', null, ['untrusted_source'],
                ['framework_version', 'php_runtime', 'extensions', 'composer_dependencies', 'filesystem_case'],
                ['file_checksum', 'ownership', 'manual_review']));
        return new UpgradeReport($plan, $findings);
    }

    private function root(string $path): string
    {
        // Validate the caller's spelling before realpath can erase a linked
        // ancestor (including one followed by a lexical "..").
        try { $resolved = BundlePath::existingInput($path); }
        catch (Throwable) { throw new UpgradeException('Upgrade source root is unsafe.'); }
        if (!is_dir($resolved)) {
            throw new UpgradeException('Upgrade source root is unavailable.');
        }
        $normalized = rtrim(str_replace('\\', '/', $resolved), '/');
        return $normalized === '' ? '/' : $normalized;
    }

    /**
     * Only named source roots are inventoried. Storage, vendor, .env, tests,
     * and unselected private files cannot enter a file Change Plan.
     *
     * @param list<array{status:string,code:string,subject:string}> $findings
     * @return array<string,string> Relative path to SHA-256.
     */
    private function inventory(string $root, array &$findings, string $side): array
    {
        $files = [];
        $seen = [];
        $entries = @scandir($root);
        if ($entries === false) throw new UpgradeException('Upgrade source root cannot be listed.');
        if (count($entries) > self::MAX_FILES) {
            self::finding($findings, 'blocked', 'source_entry_limit', $side);
            return [];
        }
        foreach (self::DIRECTORIES as $directory) {
            foreach ($entries as $entry) {
                if (strcasecmp($entry, $directory) === 0 && $entry !== $directory) {
                    self::finding($findings, 'blocked', 'source_root_case_collision', $side . '/' . $directory);
                }
            }
            if (is_dir($root . '/' . $directory) || is_link($root . '/' . $directory)) {
                $this->walk($root, $directory, $files, $seen, $findings, $side);
            }
        }
        foreach (self::ROOT_FILES as $filename) {
            if (is_file($root . '/' . $filename) || is_link($root . '/' . $filename)) {
                $this->walk($root, $filename, $files, $seen, $findings, $side);
            }
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    /** @param array<string,string> $files @param array<string,string> $seen
     *  @param list<array{status:string,code:string,subject:string}> $findings */
    private function walk(string $root, string $relative, array &$files, array &$seen,
        array &$findings, string $side): void
    {
        if (strlen($relative) > 512 || !self::safeRelative($relative)) {
            self::finding($findings, 'blocked', 'unsafe_source_path', $side);
            return;
        }
        // Exclude private directory segments before recursion, so a secret
        // container cannot be inventoried or expanded into many findings.
        if (self::privatePath($relative)) {
            self::finding($findings, 'blocked', 'private_source_excluded', $side);
            return;
        }
        if (count($seen) >= self::MAX_FILES) {
            self::finding($findings, 'blocked', 'source_entry_limit', $side);
            return;
        }
        try { $fold = BundlePath::collisionKey($relative); }
        catch (Throwable) {
            self::finding($findings, 'blocked', 'unsafe_source_path', $side);
            return;
        }
        if (isset($seen[$fold]) && $seen[$fold] !== $relative) {
            self::finding($findings, 'blocked', 'source_case_collision', $side . '/' . $relative);
            return;
        }
        $seen[$fold] = $relative;
        $path = $root . '/' . $relative;
        try { PackageFiles::assertPhysical($path); }
        catch (Throwable) {
            self::finding($findings, 'blocked', 'unsafe_source_entry', $side . '/' . $relative);
            return;
        }
        if (is_dir($path)) {
            $children = @scandir($path);
            if ($children === false) {
                self::finding($findings, 'blocked', 'unreadable_source_entry', $side . '/' . $relative);
                return;
            }
            if (count($children) > self::MAX_FILES) {
                self::finding($findings, 'blocked', 'source_entry_limit', $side);
                return;
            }
            sort($children, SORT_STRING);
            foreach ($children as $child) {
                if ($child === '.' || $child === '..') continue;
                $this->walk($root, $relative . '/' . $child, $files, $seen, $findings, $side);
            }
            return;
        }
        if (!is_file($path) || count($files) >= self::MAX_FILES) {
            self::finding($findings, 'blocked', 'source_entry_limit', $side . '/' . $relative);
            return;
        }
        $size = @filesize($path);
        if ($size === false || $size > self::MAX_FILE_BYTES) {
            self::finding($findings, 'blocked', 'source_file_limit', $side . '/' . $relative);
            return;
        }
        $hash = @hash_file('sha256', $path);
        if ($hash === false) {
            self::finding($findings, 'blocked', 'unreadable_source_entry', $side . '/' . $relative);
            return;
        }
        $files[$relative] = $hash;
    }

    private static function safeRelative(string $relative): bool
    {
        foreach (explode('/', $relative) as $part) {
            if ($part === '' || $part === '.' || $part === '..'
                || preg_match('/[\\\\:\x00-\x1f\x7f<>"|?*]/', $part) === 1
                || str_ends_with($part, ' ') || str_ends_with($part, '.')
                || preg_match('/\A(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|\z)/iD', $part) === 1) {
                return false;
            }
        }
        return true;
    }

    private static function privatePath(string $relative): bool
    {
        foreach (explode('/', $relative) as $part) {
            if ($part === '.git' || $part === '.env' || str_starts_with($part, '.env.')
                || preg_match('/\.(?:pem|key|p12|pfx)\z/iD', $part) === 1) return true;
        }
        return false;
    }

    /** @param list<array{status:string,code:string,subject:string}> $findings */
    private function composer(string $current, string $target, array &$findings): void
    {
        $before = $this->readComposer($current);
        $after = $this->readComposer($target);
        if ($after === null) {
            self::finding($findings, 'blocked', 'target_composer_unavailable', 'composer.json');
            return;
        }
        if ($before === null) {
            self::finding($findings, 'unknown', 'current_composer_unavailable', 'composer.json');
        } elseif (($before['name'] ?? null) !== ($after['name'] ?? null)) {
            self::finding($findings, 'blocked', 'composer_project_identity_changed', 'composer.json');
        }
        $requirements = $after['require'] ?? null;
        if (!is_array($requirements)) {
            self::finding($findings, 'blocked', 'composer_require_invalid', 'composer.json');
            return;
        }
        $php = $requirements['php'] ?? null;
        if (!is_string($php)) {
            self::finding($findings, 'unknown', 'php_constraint_missing', 'composer.json');
        } else {
            $result = self::constraint($php, PHP_VERSION);
            if ($result === false) self::finding($findings, 'blocked', 'php_requirement_mismatch', 'composer.json');
            elseif ($result === null) self::finding($findings, 'unknown', 'php_constraint_unsupported', 'composer.json');
        }
        foreach ($requirements as $name => $constraint) {
            if (!is_string($name) || !str_starts_with($name, 'ext-')) continue;
            $extension = substr($name, 4);
            if (!extension_loaded($extension)) {
                self::finding($findings, 'blocked', 'required_extension_missing', 'composer.json');
                continue;
            }
            $version = phpversion($extension);
            if (!is_string($constraint) || ($constraint !== '*' && !is_string($version))) {
                self::finding($findings, 'unknown', 'extension_constraint_unverified', 'composer.json');
                continue;
            }
            if ($constraint !== '*') {
                $result = self::constraint($constraint, $version);
                if ($result === false) self::finding($findings, 'blocked', 'extension_version_mismatch', 'composer.json');
                elseif ($result === null) self::finding($findings, 'unknown', 'extension_constraint_unverified', 'composer.json');
            }
        }
        if ($before !== null && ($before['require'] ?? null) !== $requirements) {
            self::finding($findings, 'review', 'composer_requirements_changed', 'composer.json');
        }
        if (!is_file($target . '/composer.lock')) {
            self::finding($findings, 'unknown', 'target_lock_missing', 'composer.lock');
        }
        if (isset($after['extra']['squehub']['framework'])) {
            // This development tree has no authoritative framework release
            // version to compare with a declared compatibility range.
            self::finding($findings, 'unknown', 'framework_constraint_unverified', 'composer.json');
        }
    }

    /** @return array<string,mixed>|null */
    private function readComposer(string $root): ?array
    {
        $file = $root . '/composer.json';
        if (!is_file($file) || is_link($file)) return null;
        $size = @filesize($file);
        if ($size === false || $size > 1048576) return null;
        $raw = @file_get_contents($file);
        if ($raw === false) return null;
        try { $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException) { return null; }
        return is_array($value) && !array_is_list($value) ? $value : null;
    }

    /** Supports only simple constraints whose meaning can be checked without Composer's dev-only Semver package. */
    private static function constraint(string $constraint, string $actual): ?bool
    {
        if ($constraint === '*') return true;
        if (preg_match('/\A\^(\d+)\.(\d+)(?:\.(\d+))?\z/D', $constraint, $match) === 1) {
            $major = (int) $match[1];
            $minor = (int) $match[2];
            $patch = isset($match[3]) ? (int) $match[3] : 0;
            $upper = $major > 0 ? ($major + 1) . '.0.0'
                : ($minor > 0 ? '0.' . ($minor + 1) . '.0' : '0.0.' . ($patch + 1));
            return version_compare($actual, "$major.$minor.$patch", '>=')
                && version_compare($actual, $upper, '<');
        }
        if (preg_match('/\A>=\s*(\d+\.\d+(?:\.\d+)?)\z/D', $constraint, $match) === 1) {
            return version_compare($actual, $match[1], '>=');
        }
        if (preg_match('/\A\d+\.\d+(?:\.\d+)?\z/D', $constraint) === 1) {
            return version_compare($actual, $constraint, '==');
        }
        return null;
    }

    /** @param list<array{status:string,code:string,subject:string}> $findings
     *  @return array{packages:array<string,array<string,mixed>>,kits:array<string,array<string,mixed>>} */
    private function activation(string $root, array &$findings): array
    {
        try { return (new ActivationStore($root))->read(); }
        catch (Throwable) {
            self::finding($findings, 'blocked', 'activation_metadata_invalid', 'Project/Activation.json');
            return ['packages' => [], 'kits' => []];
        }
    }

    /** @param array{packages:array<string,array<string,mixed>>,kits:array<string,array<string,mixed>>} $state
     *  @param list<array{status:string,code:string,subject:string}> $findings
     *  @return array<string,string> */
    private function ownedFiles(array $state, array &$findings): array
    {
        $owned = [];
        $claimants = [];
        foreach ($state['packages'] as $name => $record) {
            foreach (($record['files'] ?? []) as $relative => $hash) {
                if (!is_string($hash)) continue;
                $path = 'Project/Packages/' . $name . '/' . $relative;
                $owned[$path] = $hash;
                $claimants[$path] = true;
            }
        }
        foreach ($state['kits'] as $record) {
            foreach (($record['published'] ?? []) as $relative => $metadata) {
                if (is_array($metadata) && is_string($metadata['hash'] ?? null)) {
                    if (isset($claimants[$relative])) {
                        self::finding($findings, 'blocked', 'shared_ownership', $relative);
                    }
                    $owned[$relative] = $metadata['hash'];
                    $claimants[$relative] = true;
                }
            }
        }
        return $owned;
    }

    /** No entry PHP is included. The existing discoverers parse only static metadata and class tokens. */
    private function packageAndKitRequirements(string $current, string $target, array $state,
        array &$findings): void
    {
        try {
            $currentPackages = (new PackageDiscovery($current . '/Project/Packages'))
                ->scan($state['packages']);
            $targetPackages = (new PackageDiscovery($target . '/Project/Packages'))->scan([]);
            $packages = [];
            foreach ($targetPackages as $name => $candidate) {
                $packages[$name] = (new PackageDiscovery($target . '/Project/Packages'))
                    ->inspect($candidate->path(), $name, $state['packages'][$name] ?? null, true);
            }
            $kitCandidates = (new KitDiscovery($target . '/Project/Kits'))->scan([]);
            $kits = [];
            foreach ($kitCandidates as $name => $candidate) {
                $kits[$name] = (new KitDiscovery($target . '/Project/Kits'))
                    ->inspect($candidate->path(), $name, $state['kits'][$name] ?? null, true);
            }
        } catch (Throwable) {
            self::finding($findings, 'blocked', 'package_or_kit_metadata_invalid', 'Project');
            return;
        }
        // The target might be a partial source tree, so an enabled current
        // contribution omitted there is unresolved rather than a delete.
        foreach ($state['packages'] as $name => $record) {
            if (($record['enabled'] ?? false) === true && !isset($packages[$name])) {
                self::finding($findings, 'unknown', 'enabled_package_absent_from_target',
                    PackageName::valid($name) ? 'Project/Packages/' . $name : 'Project/Packages');
            }
        }
        foreach ($state['kits'] as $name => $record) {
            if (($record['enabled'] ?? false) === true && !isset($kits[$name])) {
                self::finding($findings, 'unknown', 'enabled_kit_absent_from_target',
                    KitName::valid($name) ? 'Project/Kits/' . $name : 'Project/Kits');
            }
        }
        foreach ($packages as $name => $package) {
            $subject = PackageName::valid($name) ? 'Project/Packages/' . $name : 'Project/Packages';
            if ($package->errors() !== []) {
                self::finding($findings, 'blocked', 'package_invalid', $subject);
                continue;
            }
            foreach ($package->dependencies() as $dependency) {
                $available = $packages[$dependency] ?? $currentPackages[$dependency] ?? null;
                if ($available === null || $available->status() !== 'enabled') {
                    self::finding($findings, $package->status() === 'enabled' ? 'blocked' : 'review',
                        'package_dependency_unavailable', $subject);
                }
            }
        }
        foreach ($kits as $name => $kit) {
            $subject = KitName::valid($name) ? 'Project/Kits/' . $name : 'Project/Kits';
            if ($kit->errors() !== []) {
                self::finding($findings, 'blocked', 'kit_invalid', $subject);
                continue;
            }
            foreach ($kit->requires() as $dependency) {
                $available = $packages[$dependency] ?? $currentPackages[$dependency] ?? null;
                if ($available === null || $available->status() !== 'enabled') {
                    self::finding($findings, $kit->status() === 'enabled' ? 'blocked' : 'review',
                        'kit_requirement_unavailable', $subject);
                }
            }
        }
    }

    private static function owner(string $path): ContributionOwner
    {
        if (preg_match('~\AProject/Packages/([A-Z][A-Za-z0-9_]*)/~D', $path, $match) === 1) {
            return new ContributionOwner('package', $match[1]);
        }
        if (preg_match('~\AProject/Kits/([A-Z][A-Za-z0-9_]*)/~D', $path, $match) === 1) {
            return new ContributionOwner('kit', $match[1]);
        }
        return str_starts_with($path, 'App/') || str_starts_with($path, 'Bootstrap/')
            ? new ContributionOwner('framework', 'SqueHub')
            : new ContributionOwner('application', 'Project');
    }

    /** @param list<array{status:string,code:string,subject:string}> $findings */
    private static function finding(array &$findings, string $status, string $code, string $subject): void
    {
        if (count($findings) >= self::MAX_FINDINGS) return;
        $finding = ['status' => $status, 'code' => $code, 'subject' => $subject];
        if (in_array($finding, $findings, true)) return;
        if (count($findings) === self::MAX_FINDINGS - 1) {
            $findings[] = ['status' => 'blocked', 'code' => 'finding_limit', 'subject' => 'source'];
            return;
        }
        $findings[] = $finding;
    }
}
