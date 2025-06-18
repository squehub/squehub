<?php
namespace App\Core;

/**
 * Task Scheduler Class
 * 
 * Allows scheduling and running named tasks (e.g., for cron jobs, CLI commands, background workers).
 */
class Task
{
    /**
     * Stores all registered tasks with their names as keys.
     *
     * @var array<string, callable>
     */
    protected static $tasks = [];

    /**
     * Register a task with a unique name.
     *
     * @param string   $name     A unique identifier for the task.
     * @param callable $callback A callback function that defines the task logic.
     * @return void
     */
    public static function schedule(string $name, callable $callback)
    {
        self::$tasks[$name] = $callback;
    }

    /**
     * Run all scheduled tasks or a specific one by name.
     *
     * @param string|null $name The name of the specific task to run. If null, runs all tasks.
     * @return void
     */
    public static function run(?string $name = null)
    {
        if ($name) {
            if (isset(self::$tasks[$name])) {
                // Run the specified task
                call_user_func(self::$tasks[$name]);
            } else {
                // Handle undefined task name
                echo "No task found with the name: $name\n";
            }
        } else {
            // Run all registered tasks
            foreach (self::$tasks as $name => $task) {
                echo "Running task: $name\n";
                call_user_func($task);
            }
        }
    }

    /**
     * Get a list of all registered task names.
     *
     * @return array List of task names
     */
    public static function all()
    {
        return array_keys(self::$tasks);
    }
}
