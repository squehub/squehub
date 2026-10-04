<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Legacy code uses BASE_DIR for views and packages. Point it at an isolated
// workspace so characterization tests cannot write into the application.
$testRoot = sys_get_temp_dir() . '/squehub-tests-' . bin2hex(random_bytes(8));
if (!mkdir($testRoot . '/Project/Packages', 0777, true) && !is_dir($testRoot . '/Project/Packages')) {
    throw new RuntimeException('Unable to create isolated SqueHub test workspace.');
}
define('BASE_DIR', $testRoot);

register_shutdown_function(static function () use ($testRoot): void {
    $remove = static function (string $path) use (&$remove): void {
        // An earlier cleanup may leave a broken link; never recurse into a
        // directory link or leave it behind because file_exists() is false.
        $entry = @lstat($path);
        $kind = $entry === false ? null : ($entry['mode'] & 0170000);
        if (is_link($path) || (PHP_OS_FAMILY === 'Windows' && $kind === 0)) {
            if (PHP_OS_FAMILY === 'Windows' && $kind === 0) {
                @rmdir($path);
            } elseif (!@unlink($path)) {
                @rmdir($path); // Windows may require rmdir() for directory links.
            }
            return;
        }

        if (is_dir($path)) {
            foreach (new DirectoryIterator($path) as $entry) {
                if (!$entry->isDot()) {
                    $remove($entry->getPathname());
                }
            }
            rmdir($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    };
    $remove($testRoot);
});
