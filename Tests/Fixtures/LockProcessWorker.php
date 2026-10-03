<?php

declare(strict_types=1);

use App\Config\Repository;
use App\Database\DatabaseManager;
use App\Database\SystemModelClock;
use App\Locks\LockManager;
use App\Locks\Stores\DatabaseLockStore;
use App\Locks\Stores\FileLockStore;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if ($argc !== 6) exit(10);
[$script, $driver, $path, $namespace, $marker, $mode] = $argv;
$database = null;
try {
    if ($driver === 'file') {
        $store = new FileLockStore($path, $namespace);
    } elseif ($driver === 'database') {
        $database = new DatabaseManager(new Repository(['database' => [
            'default' => 'test', 'connections' => ['test' => [
                'driver' => 'sqlite', 'database' => $path,
            ]],
        ]]));
        $store = new DatabaseLockStore($database->connection());
    } else {
        exit(11);
    }
    $manager = new LockManager($store, $namespace, new SystemModelClock(), $driver);
    $handle = $manager->acquire('cross-process', 5);
    if (!$handle->acquired()) exit(12);
    if (file_put_contents($marker, 'acquired') !== 8) exit(13);
    if ($mode === 'crash') exit(0);
    if ($mode !== 'hold') exit(14);
    usleep(1_500_000);
    if (!$handle->release()) exit(15);
    $database?->disconnect();
    exit(0);
} catch (Throwable $failure) {
    fwrite(STDERR, $failure::class . ': ' . $failure->getMessage());
    exit(16);
}
