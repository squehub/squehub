<?php

declare(strict_types=1);

namespace App\Agent;

use App\Database\DatabaseManager;
use App\Database\Identifier;
use App\Foundation\Application;
use App\Packages\PackageFiles;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/** Bounded read operations over documentation and schema metadata only. */
final class AgentInspector
{
    public function __construct(private Application $app, private CapabilitySet $capabilities)
    {
    }

    /**
     * Search only the installed public Documentation tree. Internal Docs
     * reports are outside this root. Entries and ancestors must be physical,
     * so an application link cannot redirect a search to .env, Storage,
     * another drive, or a network share.
     *
     * @return array{state:string,items:list<array{path:string,line:int,excerpt:string}>,truncated:bool}
     */
    public function searchDocs(string $query, int $limit = 5): array
    {
        $this->capabilities->require('read_docs');
        if (strlen($query) < 2 || strlen($query) > 120
            || preg_match('/[\x00-\x1F\x7F]/', $query) === 1
            || $limit < 1 || $limit > 20) {
            throw new AgentException('Documentation search arguments are invalid.');
        }
        $root = $this->app->basePath('Documentation');
        if (!is_dir($root) || is_link($root)) {
            return ['state' => 'unavailable', 'items' => [], 'truncated' => false];
        }
        try {
            PackageFiles::assertPhysical($root);
        } catch (Throwable) {
            throw new AgentException('Documentation root is unsafe.');
        }
        $paths = [];
        try {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                $root, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $entry) {
                if (count($paths) >= 512) break;
                $path = $entry->getPathname();
                if ($entry->isLink() || !$entry->isFile()
                    || strtolower($entry->getExtension()) !== 'md') continue;
                PackageFiles::assertPhysical($path);
                $paths[] = $path;
            }
        } catch (Throwable) {
            throw new AgentException('Documentation search is unavailable.');
        }
        sort($paths, SORT_STRING);
        $items = [];
        $truncated = false;
        foreach ($paths as $path) {
            $bytes = @filesize($path);
            if (!is_int($bytes) || $bytes > 262144) continue;
            $lines = @file($path, FILE_IGNORE_NEW_LINES);
            if (!is_array($lines)) continue;
            foreach ($lines as $lineNumber => $line) {
                if (stripos($line, $query) === false) continue;
                if (count($items) >= $limit) {
                    $truncated = true;
                    break 2;
                }
                $relative = substr(str_replace('\\', '/', $path),
                    strlen(str_replace('\\', '/', $this->app->basePath())) + 1);
                $items[] = ['path' => $relative, 'line' => $lineNumber + 1,
                    'excerpt' => self::excerpt($line, $query)];
            }
        }
        return ['state' => 'observed', 'items' => $items, 'truncated' => $truncated];
    }

    /** Only the existing Schema reader is called; no SQL comes from tool input. */
    public function inspectSchema(string $table, string $connection = ''): array
    {
        try {
            Identifier::simple($table);
        } catch (Throwable) {
            throw new AgentException('Schema table identifier is invalid.');
        }
        $name = $connection === '' ? $this->app->config()->get('database.default') : $connection;
        if (!is_string($name) || preg_match('/\A[A-Za-z][A-Za-z0-9._-]{0,127}\z/D', $name) !== 1) {
            throw new AgentException('Schema connection identifier is invalid.');
        }
        $this->capabilities->require('read_schema', $name);
        try {
            $schema = $this->app->container()->make(DatabaseManager::class)->schema($name);
            $exists = $schema->hasTable($table);
            return ['state' => 'observed', 'connection' => $name, 'table' => $table,
                'exists' => $exists, 'indexes' => $exists ? array_slice($schema->indexes($table), 0, 64) : [],
                'records_exposed' => false];
        } catch (Throwable) {
            throw new AgentException('Schema metadata could not be inspected.');
        }
    }

    private static function excerpt(string $line, string $query): string
    {
        $line = trim($line);
        $position = stripos($line, $query);
        if ($position === false) return '';
        $start = max(0, $position - 60);
        $text = substr($line, $start, 240);
        return preg_replace('/(?i)\b(?:APP_KEY|[A-Z0-9_]*(?:PASSWORD|SECRET|TOKEN|PRIVATE_KEY))\s*[:=]\s*\S+/',
            '[redacted]', $text) ?? '[redacted]';
    }
}
