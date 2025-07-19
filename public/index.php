<?php
// public/index.php

// Enforce minimum PHP version 8.0
if (version_compare(PHP_VERSION, '8.2.0', '<')) {
    http_response_code(500);
    exit('Squehub requires PHP 8.2 or higher. You are running PHP ' . PHP_VERSION);
}


// ------------------------------------------------------------
// Entry point of the Squehub application (public directory)
// ------------------------------------------------------------

// Enable full error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// -----------------------------
// Load Database Configuration
// -----------------------------
$databaseFile = __DIR__ . '/../Database.php';
if (!file_exists($databaseFile)) {
    http_response_code(500);
    exit('Error: Database configuration file is missing.');
}

// Establish the database connection (PDO instance)
$pdo = require $databaseFile;

// -----------------------------
// Load Application Bootstrap
// -----------------------------
$bootstrapFile = __DIR__ . '/../Bootstrap.php';
if (!file_exists($bootstrapFile)) {
    http_response_code(500);
    exit('Error: Bootstrap file is missing.');
}

require_once $bootstrapFile;

// -----------------------------
// Parse Request
// -----------------------------
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// -----------------------------
// Dispatch Request to Router
// -----------------------------
try {
    $router->dispatch($method, $uri);
} catch (Throwable $e) {
    http_response_code(500);
    // For production, consider logging and showing a user-friendly error page instead
    echo 'Unhandled exception: ' . $e->getMessage();
}
