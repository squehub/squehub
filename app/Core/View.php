<?php
// app/Core/View.php
namespace App\Core;

use App\Components\DateTimeComponent;
use App\Components\ControlStructuresComponent;

class View
{
    // Array to hold multiple base directories to search views in
    protected static $viewPaths = [];

    // Array to hold section contents during rendering
    protected static $sections = [];

    // Stack to manage nested sections for startSection/endSection calls
    protected static $sectionStack = [];

    // Current section name (not currently used, but reserved)
    protected static $currentSection = null;

    // Holds parent view name if using layout extends
    protected static $parentView = null;

    /**
     * Initialize default view paths including core, project, and packages
     */
    public static function initViewPaths()
    {
        // Core views directory
        self::$viewPaths[] = BASE_DIR . '/views/';

        // Project-specific views directory
        $projectViewsPath = BASE_DIR . '/project/Views/';
        if (is_dir($projectViewsPath)) {
            self::$viewPaths[] = $projectViewsPath;
        }

        // Scan packages directory for any package Views folders and add them
        $packagesDir = BASE_DIR . '/project/Packages/';
        if (is_dir($packagesDir)) {
            foreach (scandir($packagesDir) as $package) {
                if ($package === '.' || $package === '..') {
                    continue;
                }
                $possibleViewPath = $packagesDir . $package . '/Views/';
                if (is_dir($possibleViewPath)) {
                    self::$viewPaths[] = $possibleViewPath;
                }
            }
        }
    }

    /**
     * Return all registered view paths, initializing if empty
     * @return array
     */
    public static function getViewPaths()
    {
        if (empty(self::$viewPaths)) {
            self::initViewPaths();
        }
        return self::$viewPaths;
    }

    /**
     * Find the full path of a view file by searching all view paths
     * (Used mainly for legacy or raw .php views)
     *
     * @param string $viewName
     * @return string|false Full file path or false if not found
     */
    public static function findViewFile($viewName)
    {
        foreach (self::getViewPaths() as $path) {
            $fullPath = $path . $viewName . '.php';
            if (file_exists($fullPath)) {
                return $fullPath;
            }
        }
        return false;
    }

    /**
     * Main render method to display a view with optional data
     * Supports layouts via @extends and sections
     *
     * @param string $view View name with dot notation (e.g. 'user.profile')
     * @param array $data Associative array of variables to pass to view
     */
    public static function render($view, $data = [])
    {
        $viewFilePath = self::getViewPath($view);

        if (!file_exists($viewFilePath)) {
            $errorMessage = "The view file '{$viewFilePath}' was not found. Please ensure that the view exists in the correct directory.";
            return self::renderErrorPage($errorMessage);
        }

        // Extract data variables to be available in the view scope
        extract($data);

        // Capture raw view content output
        ob_start();
        require $viewFilePath;
        $content = ob_get_clean();

        // Parse Blade-like directives in the raw content
        $parsedContent = self::processBladeSyntax($content);

        // If a layout is set by @extends, render the layout with 'content' section
        if (self::$parentView) {
            $layout = self::$parentView;
            self::$parentView = null;

            // Assign the parsed content as the 'content' section for the layout
            self::$sections['content'] = $parsedContent;

            // Render the layout recursively
            return self::render($layout, $data);
        }

        // Replace any @yield directives with their section contents
        foreach (self::$sections as $section => $sectionContent) {
            $parsedContent = str_replace("<?php \App\Core\View::yieldSection('$section'); ?>", $sectionContent, $parsedContent);
        }

        // Finally, execute the fully parsed content with extracted variables
        self::executeParsedContent($parsedContent, $data, $view);
    }

    /**
     * Render a simple error page with message
     * @param string $message
     * @return string HTML content
     */
    private static function renderErrorPage($message)
    {
        return "<html><body><h1>Error</h1><p>$message</p></body></html>";
    }

    /**
     * Include another view inside a view, with optional variables
     * @param string $view
     * @param array $data
     */
    public static function include($view, $data = [])
    {
        $viewFilePath = self::getViewPath($view);
        if (!file_exists($viewFilePath)) {
            throw new \Exception("Included view file '$viewFilePath' not found.");
        }

        extract($data);

        // Read the file directly, skip require/ob_start
        $rawContent = file_get_contents($viewFilePath);
        $parsedContent = self::processBladeSyntax($rawContent);

        self::executeParsedContent($parsedContent, $data, $view);
    }


    /**
     * Use a layout/view as parent to extend from
     * @param string $view
     * @param array $data
     */
    public static function extends($view, $data = [])
    {
        $viewFilePath = self::getViewPath($view);
        if (!file_exists($viewFilePath)) {
            throw new \Exception("Extended view file '$viewFilePath' not found.");
        }

        extract($data);

        // Read the file directly, skip require/ob_start
        $rawContent = file_get_contents($viewFilePath);
        $parsedContent = self::processBladeSyntax($rawContent);

        // Mark this as the layout to be rendered next
        self::$parentView = $view;

        // Execute it (this will be captured in render after child is parsed)
        self::executeParsedContent($parsedContent, $data, $view);
    }


    /**
     * Start capturing a section block content
     * @param string $section Section name
     */
    public static function startSection($section)
    {
        array_push(self::$sectionStack, $section);
        ob_start();
    }

    /**
     * End capturing a section block and save content
     */
    public static function endSection()
    {
        if (!empty(self::$sectionStack)) {
            $section = array_pop(self::$sectionStack);
            self::$sections[$section] = ob_get_clean();
        }
    }

    /**
     * Output the content of a section or default
     * @param string $section Section name
     * @param string $default Default string if section empty
     */
    public static function yieldSection($section, $default = '')
    {
        echo self::$sections[$section] ?? $default;
    }

    // Optional helper methods for control structures outputting raw PHP tags

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
    }

    /**
     * Resolve a view name to a full file path by searching known view paths
     * Uses dot notation (dots replaced with directory separators)
     * Adds .squehub.php extension
     *
     * @param string $view
     * @return string|false Full path if found, else false
     */
    private static function getViewPath($view)
    {
        $viewFile = str_replace('.', '/', $view) . '.squehub.php';

        foreach (self::getViewPaths() as $path) {
            $fullPath = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $viewFile;
            if (file_exists($fullPath)) {
                return $fullPath;
            }
        }

        return false;
    }

    /**
     * Parse Blade-like syntax in view content and replace with PHP
     * Includes directives for sections, layout, loops, conditions, CSRF, etc.
     *
     * @param string $content Raw view content
     * @return string Parsed PHP content
     */
    private static function processBladeSyntax($content)
    {
        // Remove HTML comments
        $content = preg_replace('/<!--.*?-->/s', '', $content);

        // Session output helper
        $content = preg_replace('/\{\{\s*session\((.*?)\)\s*\}\}/s', '<?php echo session($1); ?>', $content);

        // @php ... @endphp directives
        $content = preg_replace('/@php/', '<?php ', $content);
        $content = preg_replace('/@endphp/', ' ?>', $content);

        // Notifications (success, error, etc.)
        $content = preg_replace_callback('/@notification\s*\(\s*[\'"](\w+)[\'"]\s*\)/', function ($matches) {
            $type = $matches[1];
            $color = $type === 'success' ? 'green' : ($type === 'error' ? 'red' : 'black');
            return "<?php if (\$message = \\App\\Core\\Notification::get('$type')): ?>
            <p style=\"color: $color; \"><?= htmlspecialchars(\$message) ?></p>
        <?php endif; ?>";
        }, $content);

        $content = preg_replace_callback('/@hasNotification\s*\(\s*[\'"](\w+)[\'"]\s*\)/', function ($matches) {
            $type = $matches[1];
            return "<?php if (\$message = \\App\\Core\\Notification::get('$type')): ?>";
        }, $content);

        $content = preg_replace('/@endhasNotification/', "<?php endif; ?>", $content);

        // Echo shorthand directive
        $content = preg_replace('/@echo\((.*?)\)/', '<?php echo $1; ?>', $content);

        // Curly brace output syntax
        $content = preg_replace('/{{\s*(.*?)\s*}}/', '<?= $1 ?>', $content);

        // Conditional directives
        $content = preg_replace('/@if\s*\((.*?)\)/s', '<?php if ($1): ?>', $content);
        $content = preg_replace('/@elseif\s*\((.*?)\)/s', '<?php elseif ($1): ?>', $content);
        $content = preg_replace('/@else/s', '<?php else: ?>', $content);
        $content = preg_replace('/@endif/s', '<?php endif; ?>', $content);

        // Loop directives
        $content = preg_replace('/@foreach\s*\((.*?)\)/s', '<?php foreach ($1): ?>', $content);
        $content = str_replace('@endforeach', '<?php endforeach; ?>', $content);
        $content = preg_replace('/@for\s*\((.*?)\)/s', '<?php for ($1): ?>', $content);
        $content = str_replace('@endfor', '<?php endfor; ?>', $content);
        $content = str_replace('@endwhile', '<?php endwhile; ?>', $content);
        $content = str_replace('@do', '<?php do { ?>', $content);
        $content = preg_replace('/@enddo\s*\((.*?)\)/s', '<?php } while ($1); ?>', $content);
        $content = preg_replace('/@while\s*\((.*?)\)/s', '<?php while ($1): ?>', $content);

        // ✅ SQUEHUB FEATURE: AUTO VARIABLE PASSING ENABLED FOR @extends AND @include
// ---------------------------------------------------------------------------
// When @extends() or @include() is used WITHOUT a second argument,
// SqueHub will automatically gather all defined view variables (e.g., $title, $user)
// and pass them to the layout or partial.
// It excludes internal framework vars like 'content', 'parsedContent', etc.
// This makes views cleaner and reduces boilerplate.


        // 🔁 Process all @extends('view.name', optional_variables) directives in the view content
        $content = preg_replace_callback('/@extends\(\s*[\'"]([a-zA-Z0-9._-]+)[\'"](?:\s*,\s*(.*?))?\)/', function ($matches) {
            $view = $matches[1];

            // 📦 If no custom data is passed, auto-pass all defined view variables
            $vars = isset($matches[2]) && trim($matches[2]) !== ''
                ? $matches[2]
                : "array_diff_key(get_defined_vars(), array_flip(['parsedContent', 'content', 'data', 'view', '__data', 'cacheFile', 'viewFilePath']))"; // Auto-pass all view variables safely

            // 🔧 Replace the directive with the actual PHP execution for View::extends()
            return "<?php \\App\\Core\\View::extends('$view', $vars); ?>";
        }, $content);

        // 🔁 Process all @include('partial.name', optional_variables) directives in the view content
        $content = preg_replace_callback('/@include\(\s*[\'"]([a-zA-Z0-9._-]+)[\'"](?:\s*,\s*(.*?))?\)/', function ($matches) {
            $view = $matches[1];

            // 📦 If no custom data is passed, auto-pass all defined view variables
            $vars = isset($matches[2]) && trim($matches[2]) !== ''
                ? $matches[2]
                : "array_diff_key(get_defined_vars(), array_flip(['parsedContent', 'content', 'data', 'view', '__data', 'cacheFile', 'viewFilePath']))"; // Auto-pass all view variables safely

            // 🔧 Replace the directive with the PHP call to View::include()
            return "<?php \\App\\Core\\View::include('$view', $vars); ?>";
        }, $content);


        // Section start directive
        $content = preg_replace_callback('/@section\(\'([a-zA-Z0-9_-]+)\'\)/', function ($matches) {
            return '<?php \App\Core\View::startSection(\'' . $matches[1] . '\'); ?>';
        }, $content);

        // Section end directive
        $content = str_replace('@endsection', '<?php \App\Core\View::endSection(); ?>', $content);

        // Yield directive with optional default
        $content = preg_replace_callback('/@yield\(\'([a-zA-Z0-9_-]+)\'(?:,\s*\'([^\']*)\')?\)/', function ($matches) {
            $section = $matches[1];
            $default = isset($matches[2]) ? addslashes($matches[2]) : '';
            return "<?php \App\Core\View::yieldSection('$section', '$default'); ?>";
        }, $content);

        // Date/time shortcuts
        $content = str_replace('@year', '<?php echo date("Y"); ?>', $content);
        $content = str_replace('@month', '<?php echo date("F"); ?>', $content);
        $content = str_replace('@date', '<?php echo date("Y-m-d"); ?>', $content);
        $content = str_replace('@time', '<?php echo date("H:i:s"); ?>', $content);

        $content = preg_replace_callback('/@datetime\(\'([^\']+)\'\)/', function ($matches) {
            $format = $matches[1];
            return '<?php echo date(\'' . $format . '\'); ?>';
        }, $content);

        // CSRF token injection for forms
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
     * Get or create cache directory for compiled views
     * @return string Cache directory path
     */
    private static function getCacheDir(): string
    {
        $cacheDir = BASE_DIR . '/storage/cache/';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        return $cacheDir;
    }

    /**
     * Generate cache filename based on view name and parsed content hash
     *
     * @param string $view View name
     * @param string $parsedContent Parsed PHP content of the view
     * @return string Cache file full path
     */
    private static function getCacheFileName(string $view, string $parsedContent): string
    {
        $cacheDir = self::getCacheDir();
        $hash = md5($view . $parsedContent);
        return $cacheDir . $hash . '.php';
    }

    /**
     * Execute the compiled PHP content of a view safely
     *
     * @param string $parsedContent Parsed PHP content
     * @param array $data Data variables to extract into scope
     * @param string|null $view View name (optional)
     */
    private static function executeParsedContent($parsedContent, $data = [], $view = null)
    {
        // Extract data variables
        extract($data);

        // If no view name provided, use a temporary file (for inline or dynamic content)
        if ($view === null) {
            $tempFile = tempnam(sys_get_temp_dir(), 'view_') . '.php';
            file_put_contents($tempFile, $parsedContent);

            try {
                include $tempFile;
            } finally {
                unlink($tempFile);
            }
            return;
        }

        // Use cache file for compiled views
        $cacheFile = self::getCacheFileName($view, $parsedContent);
        $viewFilePath = self::getViewPath($view);

        // Write cache if missing or stale
        if (!file_exists($cacheFile) || filemtime($cacheFile) < filemtime($viewFilePath)) {
            file_put_contents($cacheFile, $parsedContent);
        }

        // Include the cached compiled view
        include $cacheFile;
    }
}
