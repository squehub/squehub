<?php

declare(strict_types=1);

use App\Database\Database as DatabaseBridge;

// Direct legacy includes still return PDO, now from the Application-owned pool.
try {
    $manager = DatabaseBridge::manager();
} catch (LogicException $exception) {
    // Standalone includes still bootstrap the Application when none exists.
    require __DIR__ . '/config.php';
    $manager = DatabaseBridge::manager();
}

return $manager->connection()->pdo();
