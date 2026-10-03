<?php
namespace App\Core;

/**
 * Legacy notification adapter. Historical notification_<type> keys remain
 * visible to templates, while SessionStore owns their request lifetime.
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
        // Keep the historical key visible to legacy templates while the v2
        // store controls its lifetime across the next request boundary.
        \session()->flash("notification_{$type}", $message);
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
        $key = "notification_{$type}";
        $message = \session()->get($key);
        // Preserve the old empty-message behavior; get() historically left
        // empty values in place rather than consuming them.
        return empty($message) ? null : \session()->pull($key);
    }

    /**
     * Retrieve all available notification types at once.
     * Clears them from session after retrieval.
     *
     * @return array Associative array of notifications [type => message]
     */
    public static function all(): array
    {
        $types = ['success', 'error', 'info', 'warning'];
        $notifications = [];
        foreach ($types as $type) {
            $message = self::get($type);
            if ($message !== null) $notifications[$type] = $message;
        }

        return $notifications;
    }
}
