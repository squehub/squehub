<?php

declare(strict_types=1);

namespace App\Packages;

/**
 * Static Package inspection. No Package PHP is included during discovery.
 * Entry inheritance is also checked when an enabled Package is activated.
 */
final class PackageDiscovery
{
    public function __construct(private string $directory)
    {
    }

    /** @param array<string, array<string, mixed>> $records
     *  @return array<string, PackageDescriptor>
     */
    public function scan(array $records): array
    {
        if (!is_dir($this->directory)) {
            return $this->missingRecords([], $records);
        }
        PackageFiles::assertPhysical($this->directory);
        $names = @scandir($this->directory);
        if ($names === false) {
            throw new PackageException('Package directory cannot be read.');
        }
        $descriptors = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..' || !is_dir($this->directory . '/' . $name)) {
                continue;
            }
            $descriptors[$name] = $this->inspect($this->directory . '/' . $name, $name,
                $records[$name] ?? null);
        }
        $folded = [];
        foreach ($descriptors as $name => $descriptor) {
            $folded[strtolower($name)][] = $name;
        }
        foreach ($folded as $collisions) {
            if (count($collisions) < 2) {
                continue;
            }
            foreach ($collisions as $name) {
                $descriptors[$name] = $descriptors[$name]->withStatus('broken',
                    [...$descriptors[$name]->errors(), 'Package identity conflicts by casing.']);
            }
        }
        return $this->missingRecords($descriptors, $records);
    }

    /** @param array<string, mixed>|null $record */
    public function inspect(string $path, string $name, ?array $record = null, bool $source = false): PackageDescriptor
    {
        $errors = [];
        $entryClass = null;
        $version = null;
        $owner = null;
        $dependencies = [];
        if (!PackageName::valid($name)) {
            $errors[] = 'Package name is not an exact capitalized PHP identifier.';
        }
        try {
            PackageFiles::assertPhysical($path);
        } catch (PackageException) {
            $errors[] = 'Package directory is linked, reparsed, or unavailable.';
        }
        if ($errors === []) {
            try {
                // Managed install sources remain link-free. Installed Package
                // Views use the same contained-link policy as View rendering.
                PackageFiles::inspectTree($path, $source, !$source);
            } catch (PackageException) {
                $errors[] = 'Package contains an unsafe or unreadable entry.';
            }
        }
        $entry = $path . '/' . $name . '.php';
        if ($errors === [] && !is_file($entry)) {
            $errors[] = 'Expected Package entry file is missing.';
        }
        if ($errors === []) {
            $entryClass = $this->entryClass($entry, $name);
            if ($entryClass === null) {
                $errors[] = 'Package entry namespace, class, or ServiceProvider parent is invalid.';
            }
        }
        if ($errors === [] && is_file($path . '/composer.json')) {
            try {
                [$version, $owner, $dependencies] = $this->metadata($path . '/composer.json', $name);
            } catch (PackageException $exception) {
                $errors[] = $exception->getMessage();
            }
        }
        $status = $errors !== [] ? 'broken' : (($record['enabled'] ?? false) ? 'enabled' : 'disabled');
        return new PackageDescriptor($name, $path, $entryClass, $version, $owner,
            $dependencies, $status, $errors, $record);
    }

    /** @param array<string, PackageDescriptor> $descriptors
     *  @param array<string, array<string, mixed>> $records
     *  @return array<string, PackageDescriptor>
     */
    private function missingRecords(array $descriptors, array $records): array
    {
        foreach ($records as $name => $record) {
            if (!isset($descriptors[$name])) {
                $descriptors[$name] = new PackageDescriptor($name, $this->directory . '/' . $name,
                    null, null, null, [], 'broken', ['Package files are missing.'], $record);
            }
        }
        ksort($descriptors, SORT_STRING);
        return $descriptors;
    }

    /** @return array{?string, ?string, list<string>} */
    private function metadata(string $path, string $name): array
    {
        $size = @filesize($path);
        if ($size === false || $size > 262144) {
            throw new PackageException('Package metadata is too large or unavailable.');
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new PackageException('Package metadata cannot be read.');
        }
        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new PackageException('Package metadata is invalid JSON.', 0, $exception);
        }
        if (!is_array($data)) {
            throw new PackageException('Package metadata must be an object.');
        }
        $squehub = $data['extra']['squehub'] ?? [];
        if (!is_array($squehub) || (isset($squehub['name']) && $squehub['name'] !== $name)) {
            throw new PackageException('Package metadata identity does not match the directory.');
        }
        $version = $data['version'] ?? null;
        if ($version !== null && (!is_string($version) || $version === '' || strlen($version) > 64
            || preg_match('/[\x00-\x1F\x7F]/', $version))) {
            throw new PackageException('Package metadata version is invalid.');
        }
        // An owner is a static display label, never an authority or source URL.
        $owner = $squehub['owner'] ?? null;
        if (array_key_exists('owner', $squehub)
            && (!is_string($owner) || $owner === '' || strlen($owner) > 128
                || preg_match('/\A[\p{L}\p{N}](?:[\p{L}\p{N} ._-]*[\p{L}\p{N}])?\z/uD', $owner) !== 1)) {
            throw new PackageException('Package metadata owner is invalid.');
        }
        $dependencies = $squehub['requires'] ?? [];
        if (!is_array($dependencies) || !array_is_list($dependencies) || count($dependencies) > 64) {
            throw new PackageException('Package dependencies must be a bounded list.');
        }
        $seen = [];
        foreach ($dependencies as $dependency) {
            if (!is_string($dependency) || !PackageName::valid($dependency)
                || $dependency === $name || isset($seen[$dependency])) {
                throw new PackageException('Package dependency declaration is invalid.');
            }
            $seen[$dependency] = true;
        }
        // Equivalent declarations expose one portable inspection and graph order.
        sort($dependencies, SORT_STRING);
        return [$version, $owner, $dependencies];
    }

    /** Resolve only a literal expected class declaration and provider parent. */
    private function entryClass(string $file, string $name): ?string
    {
        $source = @file_get_contents($file);
        if ($source === false || strlen($source) > 262144) {
            return null;
        }
        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (\ParseError) {
            return null;
        }
        $significant = [];
        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $significant[] = $token;
        }
        $namespace = '';
        $uses = [];
        $depth = 0;
        $found = null;
        $count = count($significant);
        for ($i = 0; $i < $count; $i++) {
            $token = $significant[$i];
            $id = is_array($token) ? $token[0] : null;
            $literal = is_array($token) ? $token[1] : $token;
            if ($literal === '{') { $depth++; continue; }
            if ($literal === '}') { $depth--; continue; }
            if ($depth !== 0) { continue; }
            if ($id === T_NAMESPACE || $id === T_USE) {
                $parts = [];
                $part = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    $part = is_array($significant[$j]) ? $significant[$j][1] : $significant[$j];
                    if ($part === ';' || $part === '{') { break; }
                    $parts[] = is_array($significant[$j]) && $significant[$j][0] === T_AS
                        ? ' as ' : $part;
                }
                if ($j >= $count || $part !== ';') { return null; }
                $value = implode('', $parts);
                if ($id === T_NAMESPACE) {
                    $namespace = trim($value, '\\');
                } else {
                    foreach (explode(',', $value) as $import) {
                        if (preg_match('/\A([^ ]+)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\z/i', trim($import), $match)) {
                            $alias = $match[2] ?? substr($match[1], strrpos($match[1], '\\') + 1);
                            $uses[$alias] = trim($match[1], '\\');
                        }
                    }
                }
                $i = $j;
                continue;
            }
            if ($id !== T_CLASS) { continue; }
            $class = $significant[$i + 1] ?? null;
            if (!is_array($class) || $class[0] !== T_STRING || $class[1] !== $name || $found !== null) {
                return null;
            }
            $parent = null;
            for ($j = $i + 2; $j < $count; $j++) {
                $part = $significant[$j];
                $piece = is_array($part) ? $part[1] : $part;
                if ($piece === '{') { break; }
                if (is_array($part) && $part[0] === T_EXTENDS) {
                    $parentParts = [];
                    for ($k = $j + 1; $k < $count; $k++) {
                        $next = $significant[$k];
                        if (!is_array($next) || !in_array($next[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
                            break;
                        }
                        $parentParts[] = $next[1];
                    }
                    $parent = implode('', $parentParts);
                }
            }
            if ($parent === null) { return null; }
            $resolved = $uses[$parent] ?? (str_contains($parent, '\\')
                ? trim($parent, '\\') : $namespace . '\\' . $parent);
            if ($resolved !== 'App\\Plugins\\ServiceProvider') { return null; }
            $found = $namespace . '\\' . $name;
        }
        return in_array($found, ['Packages\\' . $name . '\\' . $name,
            'Project\\Packages\\' . $name . '\\' . $name], true) ? $found : null;
    }
}
