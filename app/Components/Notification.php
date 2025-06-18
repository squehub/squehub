<?php
// app/core/Components/Notification.php

namespace App\Components;

/**
 * Class Notification
 *
 * Handles flash-style session-based notifications (e.g., success, error, warning).
 * Notifications are stored in the session and automatically cleared after retrieval.
 */
class Notification
{
    /**
     * Retrieve and remove a notification of the given type from the session.
     *
     * @param string $type  The notification type (e.g., 'success', 'error').
     * @return string|null  The notification message if found, or null.
     */
    public static function get($type)
    {
        // Ensure session is started
        if (!isset($_SESSION)) {
            session_start();
        }

        // Check and return the message, then remove it
        if (isset($_SESSION['_notification'][$type])) {
            $message = $_SESSION['_notification'][$type];
            unset($_SESSION['_notification'][$type]); // Flash message - clear after use
            return $message;
        }

        return null;
    }

    /**
     * Store a notification of a given type in the session.
     *
     * @param string $type     The notification type (e.g., 'success', 'error').
     * @param string $message  The message content.
     * @return void
     */
    public static function set($type, $message)
    {
        // Ensure session is started
        if (!isset($_SESSION)) {
            session_start();
        }

        $_SESSION['_notification'][$type] = $message;
    }
}
