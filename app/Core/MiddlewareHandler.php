<?php 
namespace App\Core;

/**
 * Abstract base class for all middleware in the Squehub framework.
 * Provides common utility methods and enforces the structure for middleware classes.
 */
abstract class MiddlewareHandler
{
    /**
     * Ensures that a PHP session is started if it's not already active.
     * Useful for middleware that relies on session data (like auth).
     */
    protected function startSessionIfNotStarted()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Redirects the user to a different path and stops further script execution.
     *
     * @param string $path The URL path to redirect to.
     */
    protected function redirect(string $path)
    {
        header("Location: {$path}");
        exit;
    }

    /**
     * All child middleware classes must implement this method.
     * It is the core logic that defines what the middleware should do.
     *
     * @param mixed $request The current request object or data.
     * @param callable $next The next middleware or controller to call if this middleware passes.
     * 
     * @return mixed The response, or a redirect/exception if middleware blocks the request.
     */
    abstract public function handle($request, $next);
}
