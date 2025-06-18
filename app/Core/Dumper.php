<?php
// app/core/dumper.php

namespace App\Core;

/**
 * Abstract Dumper class
 * -----------------------
 * Designed to be extended by data dump classes (e.g., seeders or test data).
 * Provides reusable methods for inserting and deleting multiple records.
 */
abstract class Dumper
{
    /**
     * Each subclass must implement this method.
     * This is where the actual data seeding logic will be placed.
     */
    abstract public function run();

    /**
     * Optional rollback method.
     * Can be overridden by subclasses to undo the `run()` logic.
     */
    public function rollback() {}

    /**
     * Helper method to insert multiple records into a model.
     *
     * @param string $model Fully qualified class name of the model (e.g., App\Models\User)
     * @param array $dataSet Array of associative arrays, each representing a record
     *
     * Example:
     * $this->dump(User::class, [
     *     ['name' => 'Mark', 'email' => 'mark@example.com'],
     *     ['name' => 'Jane', 'email' => 'jane@example.com']
     * ]);
     */
    protected function dump($model, array $dataSet)
    {
        foreach ($dataSet as $data) {
            $model::create($data);
        }
    }

    /**
     * Helper method to delete records based on a field and values.
     *
     * @param string $model Fully qualified class name of the model
     * @param string $field Field name to match
     * @param array $values Array of values to delete (by field match)
     *
     * Example:
     * $this->deleteBy(User::class, 'email', [
     *     'mark@example.com',
     *     'jane@example.com'
     * ]);
     */
    protected function deleteBy($model, $field, array $values)
    {
        foreach ($values as $value) {
            $record = $model::whereFirst($field, $value);
            if ($record) {
                $model::delete($record['id']);
            }
        }
    }
}
