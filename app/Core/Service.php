<?php
// app/Core/Service.php

namespace App\Core;

/**
 * Simple Service Container
 * 
 * Manages the registration and retrieval of service instances (e.g., mailers, loggers, etc.).
 */
class Service
{
    /**
     * Stores all registered service instances.
     *
     * @var array<string, object>
     */
    protected static $services = [];

    /**
     * Register a service instance using a unique name (alias).
     *
     * @param string $name     The alias for the service
     * @param object $instance The service object to store
     * @return void
     */
    public static function register(string $name, object $instance): void
    {
        self::$services[$name] = $instance;
    }

    /**
     * Retrieve a registered service instance by name.
     *
     * @param string $name The alias of the service
     * @return object|null Returns the instance or null if not found
     */
    public static function get(string $name)
    {
        return self::$services[$name] ?? null;
    }

    /**
     * Magic static call to access a registered service by method name.
     * Example: Service::mailer() will resolve the service registered with 'mailer'.
     *
     * @param string $method The alias of the service
     * @param array $args    (Unused) Parameters for the method (not used in this context)
     * @return mixed|null    The resolved service instance or null
     */
    public static function __callStatic($method, $args)
    {
        return self::get($method);
    }

    /**
     * Bulk register services from a config array.
     * Example:
     * [
     *     'mailer' => \App\Services\Mailer::class,
     *     'logger' => \App\Services\Logger::class,
     * ]
     *
     * @param array $config Associative array of alias => class
     * @return void
     */
    public static function loadFromConfig(array $config): void
    {
        foreach ($config as $alias => $class) {
            if (class_exists($class)) {
                self::register($alias, new $class());
            } else {
                error_log("Service class '{$class}' not found for alias '{$alias}'.");
            }
        }
    }
}
