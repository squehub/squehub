<?php
// app/components/ControlStructuresComponent.php
namespace App\Components;

class ControlStructuresComponent
{
    /**
     * Start an @if block.
     *
     * @param bool $condition The condition to evaluate.
     * @return string
     */
    public static function startIf($condition)
    {
        return $condition ? '<?php if (true): ?>' : '<?php if (false): ?>';
    }

    /**
     * Start an @else block.
     *
     * @return string
     */
    public static function startElse()
    {
        return '<?php else: ?>';
    }

    /**
     * End an @if or @else block.
     *
     * @return string
     */
    public static function endIf()
    {
        return '<?php endif; ?>';
    }

    /**
     * Start a @foreach block.
     *
     * @param array $items The array to iterate over.
     * @param string $variable The variable name for the current item.
     * @return string
     */
    public static function startForeach($items, $variable)
    {
        return '<?php foreach (' . $items . ' as ' . $variable . '): ?>';
    }

    /**
     * End a @foreach block.
     *
     * @return string
     */
    public static function endForeach()
    {
        return '<?php endforeach; ?>';
    }
}