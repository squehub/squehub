<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Changes\ChangeAction;
use App\Changes\ChangePlan;
use App\Changes\ChangePlanMetadata;
use App\Clis\FileLookup;
use App\Contributions\ContributionOwner;
use App\Packages\PackageFiles;
use JsonException;
use Throwable;

/**
 * Describes migration source through the shared Change Plan without loading
 * PHP or contacting a database. Source visibility does not establish pending
 * history, SQL effects, rollback safety, or permission to execute.
 */
final class MigrationPlanner
{
    private const MAX_FILES = 4096;
    private const MAX_FILE_BYTES = 16777216;
    private const MAX_TOTAL_BYTES = 268435456;

    public function plan(string $applicationRoot): ChangePlan
    {
        if (is_link($applicationRoot)) {
            throw new MigrationException('Migration source root is unsafe.');
        }
        $physicalRoot = realpath($applicationRoot);
        if ($physicalRoot === false || !is_dir($physicalRoot)) {
            throw new MigrationException('Migration source root is unavailable.');
        }
        $root = rtrim(str_replace('\\', '/', $physicalRoot), '/');
        if ($root === '') $root = '/';
        try { PackageFiles::assertPhysical($root); }
        catch (Throwable) { throw new MigrationException('Migration source root is unsafe.'); }

        $files = [];
        $conflicts = [];
        $seenDirectories = [];
        $seenSubjects = [];
        $totalBytes = 0;
        foreach (['Database', 'database'] as $parent) {
            foreach (['Migrations', 'migrations'] as $child) {
                $relativeDirectory = $parent . '/' . $child;
                $directory = $root . '/' . $relativeDirectory;
                if (!file_exists($directory) && !is_link($directory)) continue;
                try { PackageFiles::assertPhysical($directory); }
                catch (Throwable) {
                    $conflicts[] = 'Migration source contains an unsafe directory.';
                    continue;
                }
                if (!is_dir($directory)) {
                    $conflicts[] = 'Migration source path is not a directory.';
                    continue;
                }
                $canonical = realpath($directory);
                if ($canonical === false || isset($seenDirectories[$canonical])) continue;
                $seenDirectories[$canonical] = true;
                $entries = @scandir($directory);
                if ($entries === false) {
                    $conflicts[] = 'Migration source directory cannot be listed.';
                    continue;
                }
                sort($entries, SORT_STRING);
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..' || !str_ends_with($entry, '.php')) continue;
                    $subject = $relativeDirectory . '/' . $entry;
                    $path = $directory . '/' . $entry;
                    if (!self::validName($entry) || count($files) >= self::MAX_FILES) {
                        $conflicts[] = 'Migration source has an unsafe name or exceeds its file limit.';
                        continue;
                    }
                    try { PackageFiles::assertPhysical($path); }
                    catch (Throwable) {
                        $conflicts[] = 'Migration source contains an unsafe file.';
                        continue;
                    }
                    if (!is_file($path)) {
                        $conflicts[] = 'Migration source entry is not a regular file.';
                        continue;
                    }
                    $size = @filesize($path);
                    if (!is_int($size) || $size > self::MAX_FILE_BYTES
                        || $totalBytes + $size > self::MAX_TOTAL_BYTES) {
                        $conflicts[] = 'Migration source exceeds its byte limit.';
                        continue;
                    }
                    $fold = strtolower($subject);
                    if (isset($seenSubjects[$fold]) && $seenSubjects[$fold] !== $subject) {
                        $conflicts[] = 'Migration source contains a filename casing collision.';
                        continue;
                    }
                    $seenSubjects[$fold] = $subject;
                    $hash = @hash_file('sha256', $path);
                    if (!is_string($hash)) {
                        $conflicts[] = 'Migration source cannot be fingerprinted.';
                        continue;
                    }
                    $totalBytes += $size;
                    $files[$subject] = ['name' => $entry, 'hash' => $hash];
                }
            }
        }
        ksort($files, SORT_STRING);
        $this->checkIdentities($files, $conflicts);
        $conflicts = array_values(array_unique($conflicts));
        sort($conflicts, SORT_STRING);

        $owner = new ContributionOwner('application', 'Project');
        $actions = [];
        foreach ($files as $subject => $file) {
            $actions[] = new ChangeAction('state', $subject, $owner, null, $file['hash'],
                'review', 'Candidate source only; migration history and SQL effects are unverified.',
                'migration');
        }
        $warnings = ['Migration history was not inspected; listed files are not confirmed pending.'];
        if ($seenDirectories === []) {
            $warnings[] = 'No migration source directory was found.';
        }
        $fingerprint = null;
        if ($conflicts === [] && $files !== []) {
            try {
                $fingerprint = hash('sha256', json_encode($files,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } catch (JsonException) {
                $conflicts[] = 'Migration source inventory cannot be encoded.';
            }
        }
        return new ChangePlan('migrate:plan', 'database-schema', $owner,
            $actions, $warnings, $conflicts, [],
            new ChangePlanMetadata('migration', $fingerprint,
                ['untrusted_source'], [],
                ['file_checksum', 'migration_pending', 'manual_review']));
    }

    /** @param array<string,array{name:string,hash:string}> $files @param list<string> $conflicts */
    private function checkIdentities(array $files, array &$conflicts): void
    {
        $names = [];
        $classes = [];
        foreach ($files as $file) {
            $name = $file['name'];
            foreach ($names as $previous) {
                if (FileLookup::sameFirstLetter($name, $previous)) {
                    $conflicts[] = 'Migration source contains a duplicate filename identity.';
                }
            }
            $names[] = $name;
            $class = self::className($name);
            if ($class === null) {
                $conflicts[] = 'Migration source has a filename without a valid migration class identity.';
                continue;
            }
            if (isset($classes[strtolower($class)])) {
                $conflicts[] = 'Migration source contains a duplicate class identity.';
            }
            $classes[strtolower($class)] = true;
        }
    }

    private static function validName(string $name): bool
    {
        return strlen($name) <= 255
            && preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_.-]*\.php\z/D', $name) === 1
            && !str_contains($name, '..')
            && preg_match('/\A(?:con|prn|aux|nul|com[1-9]|lpt[1-9])\.php\z/iD', $name) !== 1;
    }

    /** Match Migrator's filename-to-class rule without loading the class. */
    private static function className(string $name): ?string
    {
        $stem = pathinfo($name, PATHINFO_FILENAME);
        $stem = preg_replace('/\A\d{4}_\d{2}_\d{2}_(?:\d{6}_)?/', '', $stem) ?? '';
        $class = implode('', array_map('ucfirst', preg_split('/[_-]+/', $stem) ?: []));
        return preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $class) === 1 ? $class : null;
    }
}
