<?php

namespace App\Components;

/**
 * Legacy map-form notification adapter. The _notification key remains stable
 * for existing readers; SessionStore now ages it at request boundaries.
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
        $store = \session();
        $messages = $store->get('_notification', []);
        if (!isset($messages[$type])) return null;

        $message = $messages[$type];
        unset($messages[$type]);
        // Removing one type must not give its siblings another request of life.
        if ($messages === []) $store->forget('_notification');
        else $store->replaceKeepingFlash('_notification', $messages);
        return $message;
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
        $store = \session();
        $messages = $store->get('_notification', []);
        $messages[$type] = $message;
        $store->flash('_notification', $messages);
    }
}
