<?php
// app/core/View.php
namespace App\Core;

use App\Components\DateTimeComponent;
use App\Components\ControlStructuresComponent;

class View
{
    protected static $viewPath = BASE_DIR . '/views/';
    protected static $sections = [];
    protected static $sectionStack = []; // For nested sections
    protected static $currentSection = null;
    protected static $parentView = null; // To track layouts/extensions

/**
 * Render a view file.
 *
 * @param string $view The view name (e.g., 'home' or 'layouts.main').
 * @param array $data Data to pass to the view.
 * @throws \Exception If the view file is not found.
 */
public static function render($view, $data = [])
{
    $viewFilePath = self::getViewPath($view);

    // Check if the view file exists
    if (!file_exists($viewFilePath)) {
        // Custom error message with more context
        $errorMessage = "The view file '{$viewFilePath}' was not found. Please ensure that the view exists in the correct directory.";
        return self::renderErrorPage($errorMessage);
    }

    extract($data); // Extract variables for the view.

    // Capture the output of the view file.
    ob_start();
    require $viewFilePath;
    $content = ob_get_clean();

    // Process Blade-like syntax
    $parsedContent = self::processBladeSyntax($content);

    // Handle @extends to load layouts while keeping the passed data
    if (self::$parentView) {
        $layout = self::$parentView;
        self::$parentView = null; // Reset after use.

        // Store parsed content as 'content' section
        self::$sections['content'] = $parsedContent;

        // Render the parent layout with the same data
        return self::render($layout, $data);
    }

    // Replace yield placeholders in the final content with buffered sections.
    foreach (self::$sections as $section => $sectionContent) {
        $parsedContent = str_replace("<?php \App\Core\View::yieldSection('$section'); ?>", $sectionContent, $parsedContent);
    }

    // Execute the parsed content with extracted variables
    self::executeParsedContent($parsedContent, $data);
}




/**
 * Render an error page with a custom message.
 *
 * @param string $message The error message to display.
 * @return string The rendered error page.
 */
private static function renderErrorPage($message)
{
    // You can define a custom view for displaying errors
    return "<html><body><h1>Error</h1><p>$message</p></body></html>";
}




    /**
     * Include a view file.
     *
     * @param string $view The view name to include.
     * @throws \Exception If the included view file is not found.
     */
    public static function include($view)
    {
        $viewFilePath = self::getViewPath($view);
        if (!file_exists($viewFilePath)) {
            throw new \Exception("Included view file '$viewFilePath' not found.");
        }

        // Process and include the parsed content of the included file
        ob_start();
        require $viewFilePath;
        $content = ob_get_clean();

        // Parse Blade syntax for the included file
        $parsedContent = self::processBladeSyntax($content);

        // Execute the parsed content safely using a temporary file.
        self::executeParsedContent($parsedContent);
    }

    /**
     * Include a view file.
     *
     * @param string $view The view name to extends.
     * @throws \Exception If the extended view file is not found.
     */
    public static function extends($view)
    {
        $viewFilePath = self::getViewPath($view);
        if (!file_exists($viewFilePath)) {
            throw new \Exception("extended view file '$viewFilePath' not found.");
        }

        // Process and extended the parsed content of the included file
        ob_start();
        require $viewFilePath;
        $content = ob_get_clean();

        // Parse Blade syntax for the extended file
        $parsedContent = self::processBladeSyntax($content);

        // Execute the parsed content safely using a temporary file.
        self::executeParsedContent($parsedContent);
    }

    /**
     * Start a new section.
     *
     * @param string $section The section name.
     */
    public static function startSection($section)
    {
        array_push(self::$sectionStack, $section);
        ob_start();
    }

    /**
     * End the current section.
     */
    public static function endSection()
    {
        if (!empty(self::$sectionStack)) {
            $section = array_pop(self::$sectionStack);
            self::$sections[$section] = ob_get_clean();
        }
    }

    /**
     * Yield a section's content with an optional default value.
     *
     * @param string $section The section name.
     * @param string $default The default value if the section is not defined.
     */
    public static function yieldSection($section, $default = '')
    {
        echo self::$sections[$section] ?? $default;
    }

    public static function foreach($array, $alias)
    {
        echo "<?php foreach ($array as $alias): ?>";
    }
    public static function endforeach()
    {
        echo "<?php endforeach; ?>";
    }

    public static function for($start, $condition, $increment)
    {
        echo "<?php for ($start; $condition; $increment): ?>";
    }
    public static function endfor()
    {
        echo "<?php endfor; ?>";
    }

    public static function while($condition)
    {
        echo "<?php while ($condition): ?>";
    }
    public static function endwhile()
    {
        echo "<?php endwhile; ?>";
    }

    public static function do()
    {
        echo "<?php do { ?>";
    }
    public static function enddo()
    {
        echo "<?php } while (true); ?>";
    } // Fixed incorrect syntax


    /**
     * Get the full path to a view file.
     *
     * @param string $view The view name.
     * @return string The full path to the view file.
     */
    private static function getViewPath($view)
    {
        return self::$viewPath . str_replace('.', '/', $view) . '.squehub.php';
    }

    /**
     * Process Blade-like syntax into PHP code.
     *
     * @param string $content The view content to process.
     * @return string The processed content.
     */
    private static function processBladeSyntax($content)
    {
        // Remove HTML comments
        $content = preg_replace('/<!--.*?-->/s', '', $content);

        // Handle session() with Blade echo
        $content = preg_replace('/\{\{\s*session\((.*?)\)\s*\}\}/s', '<?php echo session($1); ?>', $content);

        // Handle PHP blocks
        $content = preg_replace('/@php/', '<?php ', $content);
        $content = preg_replace('/@endphp/', ' ?>', $content);

        $content = preg_replace_callback('/@notification\s*\(\s*[\'"](\w+)[\'"]\s*\)/', function ($matches) {
            $type = $matches[1];
            $color = $type === 'success' ? 'green' : ($type === 'error' ? 'red' : 'black');
            return "<?php if (\$message = \\App\\Core\\Notification::get('$type')): ?>
            <p style=\"color: $color; \"><?= htmlspecialchars(\$message) ?></p>
        <?php endif; ?>";
        }, $content);

        // Handle @hasNotification('type')
        $content = preg_replace_callback('/@hasNotification\s*\(\s*[\'"](\w+)[\'"]\s*\)/', function ($matches) {
            $type = $matches[1];
            return "<?php if (\$message = \\App\\Core\\Notification::get('$type')): ?>";
        }, $content);

        // Handle @endhasNotification
        $content = preg_replace('/@endhasNotification/', "<?php endif; ?>", $content);


        // Handle @echo directive
        $content = preg_replace('/@echo\((.*?)\)/', '<?php echo $1; ?>', $content);

        $content = preg_replace('/{{\s*(.*?)\s*}}/', '<?= $1 ?>', $content);

        // Handle conditionals
        $content = preg_replace('/@if\s*\((.*?)\)/s', '<?php if ($1): ?>', $content);
        $content = preg_replace('/@elseif\s*\((.*?)\)/s', '<?php elseif ($1): ?>', $content);
        $content = preg_replace('/@else/s', '<?php else: ?>', $content);
        $content = preg_replace('/@endif/s', '<?php endif; ?>', $content);
            

        // Handle loops
        $content = preg_replace('/@foreach\s*\((.*?)\)/s', '<?php foreach ($1): ?>', $content);
        $content = str_replace('@endforeach', '<?php endforeach; ?>', $content);
        $content = preg_replace('/@for\s*\((.*?)\)/s', '<?php for ($1): ?>', $content);
        $content = str_replace('@endfor', '<?php endfor; ?>', $content);
        $content = str_replace('@endwhile', '<?php endwhile; ?>', $content);
        $content = str_replace('@do', '<?php do { ?>', $content);
        $content = preg_replace('/@enddo\s*\((.*?)\)/s', '<?php } while ($1); ?>', $content);
        $content = preg_replace('/@while\s*\((.*?)\)/s', '<?php } while ($1); ?>', $content);

        // Handle @extends, @include, @section, etc.
        $content = preg_replace_callback('/@extends\(\'([a-zA-Z0-9._-]+)\'\)/', function ($matches) {
            return "<?php \App\Core\View::extends('{$matches[1]}'); ?>";
        }, $content);

        $content = preg_replace_callback('/@include\(\'([a-zA-Z0-9._-]+)\'\)/', function ($matches) {
            return '<?php \App\Core\View::include(\'' . $matches[1] . '\'); ?>';
        }, $content);

        $content = preg_replace_callback('/@section\(\'([a-zA-Z0-9_-]+)\'\)/', function ($matches) {
            return '<?php \App\Core\View::startSection(\'' . $matches[1] . '\'); ?>';
        }, $content);

        $content = str_replace('@endsection', '<?php \App\Core\View::endSection(); ?>', $content);

        $content = preg_replace_callback('/@yield\(\'([a-zA-Z0-9_-]+)\'(?:,\s*\'([^\']*)\')?\)/', function ($matches) {
            $section = $matches[1];
            $default = isset($matches[2]) ? addslashes($matches[2]) : ''; // Escape single quotes
            return "<?php \App\Core\View::yieldSection('$section', '$default'); ?>";
        }, $content);

        // Handle date/time directives
        $content = str_replace('@year', '<?php echo date("Y"); ?>', $content);
        $content = str_replace('@month', '<?php echo date("F"); ?>', $content);
        $content = str_replace('@date', '<?php echo date("Y-m-d"); ?>', $content);
        $content = str_replace('@time', '<?php echo date("H:i:s"); ?>', $content);
        $content = preg_replace_callback('/@datetime\(\'([^\']+)\'\)/', function ($matches) {
            $format = $matches[1];
            return '<?php echo date(\'' . $format . '\'); ?>';
        }, $content);

        // Handle @csrf
        if (!isset($_SESSION['_token'])) {
            $_SESSION['_token'] = bin2hex(random_bytes(32));
        }

        $content = preg_replace(
            '/@csrf/',
            '<input type="hidden" name="_token" value="' . $_SESSION['_token'] . '">',
            $content
        );

        return $content;
    }


    /**
     * Safely execute parsed content using a temporary file.
     *
     * @param string $parsedContent The parsed PHP content.
     */
    private static function executeParsedContent($parsedContent, $data = [])
    {
        extract($data); // Ensure all passed data is available

        $tempFile = tempnam(sys_get_temp_dir(), 'view_') . '.php';
        file_put_contents($tempFile, $parsedContent);

        try {
            include $tempFile;
        } finally {
            unlink($tempFile);
        }
    }
}
