<?php
// public/index.php

/**
 * Entry point of the Squehub application.
 * 
 * Responsibilities:
 * - Loads the database connection
 * - Loads the application bootstrap
 * - Dispatches the HTTP request
 */

// Load database configuration
$databaseFile = __DIR__ . '/../Database.php';
if (!file_exists($databaseFile)) {
    http_response_code(500);
    exit('Error: Database configuration file is missing.');
}

$pdo = require $databaseFile;

// Load application bootstrap
$bootstrapFile = __DIR__ . '/../bootstrap.php';
if (!file_exists($bootstrapFile)) {
    http_response_code(500);
    exit('Error: Bootstrap file is missing.');
}

require_once $bootstrapFile;

// Route the request
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

try {
    $router->dispatch($method, $uri);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Unhandled exception: ' . $e->getMessage();
}
