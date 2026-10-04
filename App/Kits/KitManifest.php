<?php

declare(strict_types=1);

namespace App\Kits;

use App\Packages\PackageName;
use JsonException;
use stdClass;

/**
 * Bounded, non-executable Kit composition metadata. File contents and secret
 * configuration values are deliberately absent from the public manifest API.
 */
final readonly class KitManifest
{
    private const HOOKS = [
        'beforeInstall', 'afterInstall', 'beforeEnable', 'afterEnable',
        'beforeDisable', 'afterDisable', 'beforeUpgrade', 'afterUpgrade',
        'beforeRemove', 'afterRemove',
    ];

    /**
     * @param list<string> $requires
     * @param list<array{source:string,target:string,kind:string}> $files
     * @param list<string> $hooks
     */
    private function __construct(
        public string $name,
        public string $version,
        public array $requires,
        public array $files,
        public array $hooks,
    ) {
    }

    /** Read and validate JSON without including any Kit PHP. */
    public static function read(string $path, string $expectedName): self
    {
        $size = @filesize($path);
        if ($size === false || $size > 262144 || $size === 0) {
            throw new KitException('Kit manifest is missing, too large, or unavailable.');
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new KitException('Kit manifest cannot be read.');
        }
        try {
            $data = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new KitException('Kit manifest is invalid JSON.', 0, $exception);
        }
        if (!$data instanceof stdClass || !self::exactKeys($data,
            ['format', 'name', 'version'], ['requires', 'files', 'hooks'])) {
            throw new KitException('Kit manifest fields are invalid.');
        }
        if ($data->format !== 1 || !is_string($data->name) || $data->name !== $expectedName
            || !KitName::valid($data->name)) {
            throw new KitException('Kit manifest format or identity is invalid.');
        }
        if (!is_string($data->version) || strlen($data->version) > 64
            || preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9]+(?:[.-][A-Za-z0-9]+)*)?\z/D',
                $data->version) !== 1) {
            throw new KitException('Kit version must be a bounded release identifier.');
        }

        $requires = $data->requires ?? [];
        if (!is_array($requires) || count($requires) > 64) {
            throw new KitException('Kit Package requirements must be a bounded list.');
        }
        $seen = [];
        foreach ($requires as $requirement) {
            if (!is_string($requirement) || !PackageName::valid($requirement)
                || isset($seen[strtolower($requirement)])) {
                throw new KitException('Kit Package requirement is invalid or duplicated.');
            }
            $seen[strtolower($requirement)] = true;
        }
        sort($requires, SORT_STRING);

        $mappings = $data->files ?? [];
        if (!is_array($mappings) || count($mappings) > 256) {
            throw new KitException('Kit file mappings must be a bounded list.');
        }
        $files = [];
        $sources = [];
        $targets = [];
        foreach ($mappings as $mapping) {
            if (!$mapping instanceof stdClass || !self::exactKeys($mapping, ['source', 'target'], [])
                || !is_string($mapping->source) || !is_string($mapping->target)) {
                throw new KitException('Kit file mapping is invalid.');
            }
            $source = $mapping->source;
            $target = $mapping->target;
            self::requireRelative($source);
            self::requireRelative($target);
            $kind = self::targetKind($expectedName, $target);
            if ($kind === null || ($kind === 'config' && !str_starts_with($source, 'Config/'))
                || ($kind === 'asset' && !str_starts_with($source, 'Assets/'))
                || (!in_array($kind, ['config', 'asset'], true)
                    && !str_starts_with($source, 'Templates/'))) {
                throw new KitException('Kit file mapping targets an unsupported application location.');
            }
            if (isset($sources[strtolower($source)]) || isset($targets[strtolower($target)])) {
                throw new KitException('Kit file mapping collides by spelling or casing.');
            }
            $sources[strtolower($source)] = true;
            $targets[strtolower($target)] = true;
            $files[] = ['source' => $source, 'target' => $target, 'kind' => $kind];
        }
        usort($files, static fn (array $a, array $b): int => strcmp($a['target'], $b['target']));

        $hooks = $data->hooks ?? [];
        if (!is_array($hooks) || count($hooks) > count(self::HOOKS)) {
            throw new KitException('Kit hooks must be a bounded list.');
        }
        $seen = [];
        foreach ($hooks as $hook) {
            if (!is_string($hook) || !in_array($hook, self::HOOKS, true) || isset($seen[$hook])) {
                throw new KitException('Kit lifecycle hook is invalid or duplicated.');
            }
            $seen[$hook] = true;
        }
        sort($hooks, SORT_STRING);
        return new self($expectedName, $data->version, $requires, $files, $hooks);
    }

    /** @param list<string> $required @param list<string> $optional */
    private static function exactKeys(stdClass $object, array $required, array $optional): bool
    {
        $keys = array_keys(get_object_vars($object));
        foreach ($required as $key) {
            if (!in_array($key, $keys, true)) { return false; }
        }
        return array_diff($keys, [...$required, ...$optional]) === [];
    }

    /** A path is data, never a drive/UNC path or an instruction to traverse. */
    public static function requireRelative(string $path): void
    {
        if ($path === '' || strlen($path) > 240 || str_contains($path, '\\')
            || preg_match('/[\x00-\x1F\x7F:]/', $path) === 1
            || str_starts_with($path, '/')) {
            throw new KitException('Kit file path is unsafe.');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..'
                || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $segment) !== 1
                || str_ends_with($segment, '.') || str_ends_with($segment, ' ')
                || preg_match('/\A(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|\z)/iD', $segment) === 1) {
                throw new KitException('Kit file path is unsafe.');
            }
        }
    }

    /** Allow only known application locations, without inventing a frontend compiler. */
    public static function targetKind(string $name, string $target): ?string
    {
        if (preg_match('~\AProject/(?:Controllers|Middleware|Models|Routes|Views|Scheduler|Validation|Api|Services|Utils)/~D',
                $target) === 1) {
            return 'generated';
        }
        if (preg_match('~\ADatabase/Migrations/[A-Za-z0-9_.-]+\.php\z~D', $target) === 1) {
            return 'migration';
        }
        if (preg_match('~\ADatabase/Seeders/[A-Za-z0-9_.-]+\.php\z~D', $target) === 1) {
            return 'seeder';
        }
        if (preg_match('~\ATests/(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+\.php\z~D', $target) === 1) {
            return 'test';
        }
        if (preg_match('~\AConfig/[A-Za-z0-9_]+\.php\z~D', $target) === 1) {
            return 'config';
        }
        if (str_starts_with($target, 'public/assets/Kits/' . $name . '/')
            && preg_match('~\.(?:css|js|mjs|png|jpe?g|gif|svg|ico|webp|avif|woff2?|ttf|otf|eot|json|webmanifest|map|txt|xml)\z~iD',
                $target) === 1) {
            return 'asset';
        }
        return null;
    }
}
