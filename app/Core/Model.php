<?php
// app/core/Model.php

namespace App\Core;

use PDO;

/**
 * Base Model Class
 * Provides core database operations for models: CRUD and conditional querying.
 * Extend this class in individual model classes and set `protected static $table`.
 */
class Model
{
    // Name of the table; must be set in child model classes
    protected static $table;

    // Default primary key field (can be overridden in child class)
    protected static $primaryKey = 'id';

    /**
     * Fetch all rows from the table.
     *
     * @return array All rows as associative arrays
     */
    public static function all()
    {
        $stmt = Database::query("SELECT * FROM `" . static::$table . "`");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get the first record that matches a given column-value condition.
     *
     * @param string $column Column to check
     * @param mixed $value Value to match
     * @return array|false Returns the matched row or false if not found
     */
    public static function where($column, $value)
    {
        $stmt = Database::query(
            "SELECT * FROM `" . static::$table . "` WHERE `" . $column . "` = :value",
            ['value' => $value]
        );
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Alias of `where()` but semantically clearer when expecting a single row.
     * Adds LIMIT 1 to the query.
     *
     * @param string $column
     * @param mixed $value
     * @return array|false
     */
    public static function whereFirst($column, $value)
    {
        $stmt = Database::query(
            "SELECT * FROM `" . static::$table . "` WHERE `" . $column . "` = :value LIMIT 1",
            ['value' => $value]
        );
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Find a single row using its primary key.
     *
     * @param mixed $id Primary key value
     * @return array|false
     */
    public static function find($id)
    {
        $stmt = Database::query(
            "SELECT * FROM `" . static::$table . "` WHERE `" . static::$primaryKey . "` = :id",
            ['id' => $id]
        );
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Insert a new row into the table.
     *
     * @param array $data Associative array of column => value
     * @return string|false Returns last inserted ID or false
     */
    public static function create($data)
    {
        $columns = array_map(fn($col) => "`$col`", array_keys($data));
        $keysStr = implode(', ', $columns);

        $placeholders = ':' . implode(', :', array_keys($data));

        Database::query(
            "INSERT INTO `" . static::$table . "` ($keysStr) VALUES ($placeholders)",
            $data
        );

        return Database::connect()->lastInsertId();
    }

    /**
     * Update a row by primary key.
     *
     * @param mixed $id
     * @param array $data Associative array of columns to update
     * @return int Number of affected rows
     */
    public static function update($id, $data)
    {
        $setParts = array_map(fn($key) => "`$key` = :$key", array_keys($data));
        $setStr = implode(', ', $setParts);

        $data['id'] = $id;

        $stmt = Database::query(
            "UPDATE `" . static::$table . "` SET $setStr WHERE `" . static::$primaryKey . "` = :id",
            $data
        );

        return $stmt->rowCount();
    }

    /**
     * Delete a row by primary key.
     *
     * @param mixed $id
     * @return int Number of affected rows
     */
    public static function delete($id)
    {
        $stmt = Database::query(
            "DELETE FROM `" . static::$table . "` WHERE `" . static::$primaryKey . "` = :id",
            ['id' => $id]
        );

        return $stmt->rowCount();
    }

    /**
     * Get all rows that match a specific column-value condition.
     *
     * @param string $column
     * @param mixed $value
     * @return array List of matching rows
     */
    public static function whereAll($column, $value)
    {
        $stmt = Database::query(
            "SELECT * FROM `" . static::$table . "` WHERE `" . $column . "` = :value",
            ['value' => $value]
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
