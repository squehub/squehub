<?php
// ----------------------------------------------------
// DatabaseConfig.php - Initializes PDO DB Connection
// ----------------------------------------------------

/**
 * Loads configuration, validates credentials, and returns a PDO instance.
 * Falls back gracefully if connection fails, depending on the debug mode.
 */

// ✅ Load the application configuration file (typically loads from config.php or merged .env)
$config = require __DIR__ . '/config.php';

// ✅ Extract database credentials from config
$dbHost     = $config['database']['host']     ?? null;
$dbName     = $config['database']['database'] ?? null;
$dbUser     = $config['database']['user']     ?? null;
$dbPassword = $config['database']['password'] ?? ''; // Allow empty password if explicitly set

// ✅ Extract application environment configuration
$appEnv   = $config['app']['env']   ?? 'production';  // Default to production
$appDebug = $config['app']['debug'] ?? 'false';       // Debug mode control

// ✅ Validate essential database configuration
if (empty($dbHost) || empty($dbName) || empty($dbUser)) {
    throw new Exception('❌ Database configuration is incomplete. Please check your .env file or config.php.');
}

// ✅ Log a warning if the password is empty (common oversight in local setups)
if (empty($dbPassword)) {
    error_log('⚠️ Warning: Database password is empty. Ensure this is intentional.');
}

// ✅ Try to establish a PDO connection
try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName", // DSN (Data Source Name)
        $dbUser,
        $dbPassword,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Enable exceptions for errors
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Return results as associative arrays
            PDO::ATTR_EMULATE_PREPARES   => false,                  // Use real prepared statements
        ]
    );

    // ✅ Return PDO instance for global access
    return $pdo;

} catch (PDOException $e) {
    // ❌ If connection fails, show detailed error only in debug mode
    if ($appDebug === 'true') {
        echo '❌ Database connection failed: ' . $e->getMessage();
    } else {
        echo '❌ Database connection failed. Please try again later.';
        error_log('PDOException: ' . $e->getMessage()); // Log technical error privately
    }

    // Return null to indicate failure
    return null;
}
