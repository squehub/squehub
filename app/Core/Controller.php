<?php
//app/core/controller.php
namespace App\Core;

/**

 * Base Controller Class
 * ---------------------
 * All application controllers should extend this class to gain access to
 * middleware support and common controller utilities.
 */
class Controller
{
    /**
     * @var array List of middleware assigned to this controller.
     */
    protected array $middlewareStack = [];

    /**
     * Register middleware to the controller.
     *
     * This allows specific middleware (like Auth, RateLimiter, etc.)
     * to be executed before controller actions.
     *
     * @param array $middlewares Array of middleware class names or instances.
     */
    public function middleware(array $middlewares)
    {
        // Merge new middlewares with any already assigned
        $this->middlewareStack = array_merge($this->middlewareStack, $middlewares);
    }

    /**
     * Retrieve all middleware assigned to this controller.
     *
     * 
     * @return array
     */
    public function getMiddleware()
    {
        return $this->middlewareStack;
    }
}
