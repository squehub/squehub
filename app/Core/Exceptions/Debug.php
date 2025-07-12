<?php
//app/core/Exceptions/debug.php
namespace App\Core\Exceptions;

use Whoops\Run;
use App\Core\Exceptions\CustomPrettyPageHandler;

// ✅ Register a custom Whoops handler globally for development error display.
$whoops = new Run();
$whoops->pushHandler(new CustomPrettyPageHandler());
$whoops->register();

// ✅ Determine behavior based on debug mode status
if (DEBUG_MODE) {
    // Enable full error reporting
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    // Check if Whoops is available to show a styled error page
    if (class_exists(\Whoops\Run::class)) {
        $whoops = new \Whoops\Run;
        $handler = new \Whoops\Handler\PrettyPageHandler;

        // Customize the error page title shown in the browser
        $handler->setPageTitle("Squehub Debug Mode - An Error Occurred");

        // Register the PrettyPageHandler for graceful error rendering
        $whoops->pushHandler($handler);
        $whoops->register();
    } else {
        // Fallback error display if Whoops is not installed
        echo '<div style="background: #111; color: #fff; padding: 10px; font-family: monospace;">
                <strong>Website Debug Mode Is ON</strong> - Install <code>filp/whoops</code> for better error UI.
              </div>';
    }
} else {
    // In production mode, suppress all error output for security
    error_reporting(0);
    ini_set('display_errors', 0);
}

// ✅ Display a debug status bar when debug mode is enabled
if (DEBUG_MODE) {
    echo '<div style="
        position: fixed;
        bottom: 0;
        left: 0;
        width: 100%;
        background: #1f1f1f;
        color: #fff;
        padding: 10px;
        font-size: 14px;
        font-family: monospace;
        z-index: 9999;
        border-top: 1px solid #444;
    ">
       ⚙️ DEBUG MODE: ON; Squehub Debug Bar — Loaded at: ' . date('H:i:s') . '
    </div>';
}
