<?php
// app/core/Components/DateTimeComponent.php

namespace App\Components;

/**
 * Class DateTimeComponent
 *
 * Provides utility methods to retrieve various date and time values.
 */
class DateTimeComponent
{
    /**
     * Get the current year (e.g., "2025").
     *
     * @return string
     */
    public static function currentYear()
    {
        return date('Y');
    }

    /**
     * Get the current month name (e.g., "June").
     *
     * @return string
     */
    public static function currentMonth()
    {
        return date('F');
    }

    /**
     * Get the current date in "YYYY-MM-DD" format.
     *
     * @return string
     */
    public static function currentDate()
    {
        return date('Y-m-d');
    }

    /**
     * Get the current time in "HH:MM:SS" format (24-hour clock).
     *
     * @return string
     */
    public static function currentTime()
    {
        return date('H:i:s');
    }

    /**
     * Get the current date/time in a custom format.
     *
     * @param string $format Format string compatible with PHP's date() function.
     * @return string
     */
    public static function customFormat($format)
    {
        return date($format);
    }
}
