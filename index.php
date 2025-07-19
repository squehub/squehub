<?php
// index.php

// Enforce minimum PHP version 8.0
if (version_compare(PHP_VERSION, '8.2.0', '<')) {
    http_response_code(500);
    exit('Squehub requires PHP 8.2 or higher. You are running PHP ' . PHP_VERSION);
}


// Turn on error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

/**
 * Entry point of the Squehub application.
 * 
 * - Initializes database connection.
 * - Loads the application bootstrap file.
 * - Dispatches the incoming request to the router.
 */

// Define the path to the Database configuration file
$databaseFile = __DIR__ . '/Database.php';

// Check if the Database.php file exists
if (file_exists($databaseFile)) {
    // Establish the database connection and assign PDO instance
    $pdo = require $databaseFile;
} else {
    // Output error and stop execution if database file is missing
    echo 'Database configuration file does not exist.';
    exit;
}

// Define the path to the bootstrap file (handles routes, middleware, etc.)
$bootstrapFile = __DIR__ . '/Bootstrap.php';

// Check if the bootstrap.php file exists
if (file_exists($bootstrapFile)) {
    // Load framework bootstrap to initialize the app
    require_once $bootstrapFile;
} else {
    // Output error and stop execution if bootstrap file is missing
    echo 'Bootstrap file does not exist.';
    exit;
}

// Parse the current request URI (e.g., /about or /user/1)
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Get the HTTP method (e.g., GET, POST)
$method = $_SERVER['REQUEST_METHOD'];

// Dispatch the request to the appropriate route handler
$router->dispatch($method, $uri);
