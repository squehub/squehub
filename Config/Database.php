<?php

declare(strict_types=1);

/** Map established DB_* environment keys to lazy named connection settings. */
/** @var \App\Foundation\Environment $environment */
$host = $environment->get('DB_HOST', 'localhost');
$name = $environment->get('DB_DATABASE', 'squehub');
$user = $environment->get('DB_USER', 'root');
$password = $environment->get('DB_PASSWORD', '');
$sqliteDatabase = $environment->get('DB_SQLITE_DATABASE', ':memory:');
if (is_string($sqliteDatabase) && $sqliteDatabase !== ':memory:'
    && !str_starts_with($sqliteDatabase, '/') && !str_starts_with($sqliteDatabase, '\\')
    && preg_match('/\A[A-Za-z]:[\\\\\/]/', $sqliteDatabase) !== 1) {
    // Anchor a portable project-relative SQLite path before PDO opens it.
    // Setup uses Storage/Database.sqlite; traversal cannot escape the project.
    $segments = preg_split('~[/\\\\]+~', $sqliteDatabase);
    if ($segments === false || in_array('..', $segments, true) || str_contains($sqliteDatabase, "\0")) {
        throw new \App\Config\ConfigurationException('SQLite database path is invalid.');
    }
    $sqliteDatabase = dirname(__DIR__) . '/' . str_replace('\\', '/', $sqliteDatabase);
}

return [
    'default' => $environment->get('DB_CONNECTION', 'mysql'),
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => $host,
            'port' => $environment->get('DB_PORT', 3306),
            'database' => $name,
            'username' => $user,
            'password' => $password,
            'charset' => 'utf8mb4',
        ],
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => $sqliteDatabase,
        ],
    ],
    // Existing config.php consumers expect these flat keys.
    'host' => $host,
    'database' => $name,
    'user' => $user,
    'password' => $password,
];
