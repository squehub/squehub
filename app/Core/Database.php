<?php
// app/core/Database.php

namespace App\Core;

use PDO;
use PDOException;

/**
 * Database Class
 * -------------------------
 * Handles a singleton PDO connection to the database.
 * Automatically loads environment variables and supports simple querying.
 */
class Database
{
    /**
     * @var PDO|null Singleton PDO instance
     */
    private static $pdo;

    /**
     * Establish a PDO connection if it doesn't already exist.
     *
     * @return PDO
     */
    public static function connect()
    {
        // If PDO instance doesn't exist yet, create it
        if (!self::$pdo) {
            self::loadEnv(); // Load .env variables manually

            // Fetch DB configuration from environment variables
            $config = [
                'host'     => $_ENV['DB_HOST']     ?? 'localhost',
                'database' => $_ENV['DB_DATABASE'] ?? null,
                'user'     => $_ENV['DB_USER']     ?? 'root',
                'password' => $_ENV['DB_PASSWORD'] ?? ''
            ];

            // Ensure a DB name is set, or terminate execution
            if (!$config['database']) {
                die("❌ Error: Database name (DB_DATABASE) is not set.");
            }

            try {
                // Attempt to create a new PDO instance
                self::$pdo = new PDO(
                    "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4",
                    $config['user'],
                    $config['password'],
                    [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                    ]
                );
            } catch (PDOException $e) {
                // On connection failure, display error
                die("❌ Database connection failed: " . $e->getMessage());
            }
        }

        return self::$pdo;
    }

    /**
     * Alias for connect() – for semantic clarity.
     *
     * @return PDO
     */
    public static function getInstance(): PDO
    {
        return self::connect();
    }

    /**
     * Execute a SQL query using prepared statements.
     *
     * @param string $sql
     * @param array $params
     * @return \PDOStatement
     */
    public static function query($sql, $params = [])
    {
        $stmt = self::connect()->prepare($sql);

        // Ensure parameters are in array format
        if (!is_array($params)) {
            $params = [$params];
        }

        // Flatten any nested arrays (e.g., for IN clauses)
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $params[$key] = implode(',', $value);
            }
        }

        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Load .env file manually (in case Dotenv is not used).
     *
     * Populates $_ENV with key-value pairs from .env.
     */
    private static function loadEnv()
    {
        $envPath = __DIR__ . '/../../.env';

        if (!file_exists($envPath)) {
            die("❌ Error: .env file not found in $envPath");
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue; // Ignore commented lines
            }

            // Split key=value pair and trim whitespace
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}
