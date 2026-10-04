<?php

declare(strict_types=1);

namespace App\Kits;

use App\Packages\PackageException;
use App\Packages\PackageFiles;

/** Deterministic Kit discovery from physical files and JSON, never Kit PHP. */
final class KitDiscovery
{
    public function __construct(private string $directory)
    {
    }

    /** @param array<string,array<string,mixed>> $records @return array<string,KitDescriptor> */
    public function scan(array $records): array
    {
        $descriptors = [];
        if (is_dir($this->directory) || is_link($this->directory)) {
            try {
                PackageFiles::assertPhysical($this->directory);
                $names = @scandir($this->directory);
            } catch (PackageException $exception) {
                throw new KitException('Kit directory is unsafe.', 0, $exception);
            }
            if ($names === false) { throw new KitException('Kit directory cannot be read.'); }
            foreach ($names as $name) {
                if ($name === '.' || $name === '..' || !is_dir($this->directory . '/' . $name)) {
                    continue;
                }
                $descriptors[$name] = $this->inspect($this->directory . '/' . $name,
                    $name, $records[$name] ?? null);
            }
        }
        $folded = [];
        foreach (array_keys($descriptors) as $name) { $folded[strtolower($name)][] = $name; }
        foreach ($folded as $collisions) {
            if (count($collisions) < 2) { continue; }
            foreach ($collisions as $name) {
                $descriptor = $descriptors[$name];
                $descriptors[$name] = $descriptor->withStatus('broken',
                    [...$descriptor->errors(), 'Kit identity conflicts by casing.']);
            }
        }
        foreach ($records as $name => $record) {
            if (!isset($descriptors[$name])) {
                $descriptors[$name] = new KitDescriptor($name, $this->directory . '/' . $name,
                    null, 'broken', ['Kit definition files are missing.'], $record);
            }
        }
        ksort($descriptors, SORT_STRING);
        return $descriptors;
    }

    /** @param array<string,mixed>|null $record */
    public function inspect(string $path, string $name, ?array $record = null, bool $source = false): KitDescriptor
    {
        $errors = [];
        $manifest = null;
        if (!KitName::valid($name)) { $errors[] = 'Kit name is not an exact capitalized PHP identifier.'; }
        try {
            PackageFiles::assertPhysical($path);
            PackageFiles::inspectTree($path, $source);
        } catch (PackageException) {
            $errors[] = 'Kit source contains a linked, nonportable, or unreadable entry.';
        }
        if ($errors === []) {
            try {
                $manifest = KitManifest::read($path . '/kit.json', $name);
            } catch (KitException $exception) {
                $errors[] = $exception->getMessage();
            }
        }
        if ($errors === [] && (!is_file($path . '/' . $name . '.php')
            || !KitEntry::valid($path . '/' . $name . '.php', $name))) {
            $errors[] = 'Kit entry file, namespace, class, or Kit parent is invalid.';
        }
        $status = $errors !== [] ? 'broken' : (($record['enabled'] ?? false) ? 'enabled' : 'disabled');
        return new KitDescriptor($name, $path, $manifest, $status, $errors, $record);
    }
}
