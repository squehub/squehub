<?php

declare(strict_types=1);

namespace App\Storage;

use App\Config\Repository;
use App\Database\ModelClock;
use App\Diagnostics\Diagnostics;
use App\Foundation\Application;
use App\Storage\Drivers\ArrayStorageDriver;
use App\Storage\Drivers\LocalStorageDriver;
use App\Storage\Drivers\S3StorageDriver;
use App\Storage\Providers\AwsS3ObjectClient;

/** Selects and lazily constructs Application-owned configured drives. */
final class StorageManager
{
    use StorageOperations;

    /** @var array<string, StorageDrive> */
    private array $instances = [];

    public function __construct(private Application $app, private ?Diagnostics $diagnostics = null, private ?ModelClock $clock = null)
    {
    }

    public function drive(string $name): StorageDrive
    {
        if ($name === '' || strlen($name) > 128 || !preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
            throw new StorageException('Invalid Storage drive name.');
        }
        if (isset($this->instances[$name])) return $this->instances[$name];
        $config = $this->app->config()->get('storage.drives', []);
        if (!is_array($config) || !isset($config[$name]) || !is_array($config[$name])) {
            throw new StorageException('Unknown Storage drive.');
        }
        $settings = $config[$name];
        $driverName = $settings['driver'] ?? null;
        if ($driverName === 'array') {
            $driver = new ArrayStorageDriver($this->clock);
        } elseif ($driverName === 'local') {
            $root = $settings['root'] ?? null;
            if ($root === null) $root = $this->app->basePath('Storage/Files');
            if (!is_string($root) || $root === '') throw new StorageException('Invalid Storage root configuration.');
            if (!preg_match('~^(?:[A-Za-z]:[/\\\\]|/|\\\\\\\\)~', $root)) $root = $this->app->basePath($root);
            $driver = new LocalStorageDriver($root);
        } elseif ($driverName === 's3') {
            $prefix = $settings['prefix'] ?? '';
            if (!is_string($prefix)) throw new StorageException('Invalid S3 Storage prefix configuration.');
            $driver = new S3StorageDriver(new AwsS3ObjectClient($settings), $prefix);
        } else {
            throw new StorageException('Unsupported Storage driver.');
        }
        return $this->instances[$name] = new StorageDrive($driver, $this->diagnostics);
    }

    protected function backend(): StorageDriver
    {
        $name = $this->app->config()->get('storage.default', 'local');
        if (!is_string($name)) throw new StorageException('Invalid default Storage drive.');
        return $this->drive($name);
    }
}
