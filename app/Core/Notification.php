<?php
namespace App\Core;

/**
 * Notification class for managing flash messages.
 * 
 * Supports storing messages in the session under common types (success, error, info, warning),
 * retrieving them, and auto-clearing after display.
 */
class Notification
{
    /**
     * Store a flash notification in the session.
     *
     * @param string $type    Notification type: 'success', 'error', 'info', or 'warning'
     * @param string $message The message to flash
     */
    public static function flash(string $type, string $message): void
    {
        startSessionIfNotStarted(); // Ensure session is active
        $_SESSION["notification_{$type}"] = $message;
    }

    /**
     * Retrieve a single flash notification by type.
     * Automatically removes it from the session after fetching.
     *
     * @param string $type
     * @return string|null
     */
    public static function get(string $type): ?string
    {
        startSessionIfNotStarted();

        // Check if a notification exists for the given type
        if (!empty($_SESSION["notification_{$type}"])) {
            $message = $_SESSION["notification_{$type}"];
            unset($_SESSION["notification_{$type}"]); // Remove it so it's not shown again
            return $message;
        }

        return null; // No notification found
    }

    /**
     * Retrieve all available notification types at once.
     * Clears them from session after retrieval.
     *
     * @return array Associative array of notifications [type => message]
     */
    public static function all(): array
    {
        startSessionIfNotStarted();

        // Define supported types
        $types = ['success', 'error', 'info', 'warning'];
        $notifications = [];

        // Loop through each type and fetch if it exists
        foreach ($types as $type) {
            if (!empty($_SESSION["notification_{$type}"])) {
                $notifications[$type] = $_SESSION["notification_{$type}"];
                unset($_SESSION["notification_{$type}"]);
            }
        }

        return $notifications;
    }
}
