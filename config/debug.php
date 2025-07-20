<?php

use Whoops\Run;
use App\Core\Exceptions\CustomPrettyPageHandler;

$whoops = new Run();
$whoops->pushHandler(new CustomPrettyPageHandler());
$whoops->register();


if (DEBUG_MODE) {
    // Enable error reporting
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    // Use Whoops for pretty error pages
    if (class_exists(\Whoops\Run::class)) {
        $whoops = new \Whoops\Run;
        $handler = new \Whoops\Handler\PrettyPageHandler;

        // Optional: Customize page title or theme
        $handler->setPageTitle("Squehub Debug Mode - An Error Occurred");

        $whoops->pushHandler($handler);
        $whoops->register();
    } else {
        echo '<div style="background: #111; color: #fff; padding: 10px; font-family: monospace;">
                <strong>Website Debug Mode Is ON</strong> - Install <code>filp/whoops</code> for better error UI.
              </div>';
    }
} else {
    // In production: disable error display
    error_reporting(0);
    ini_set('display_errors', 0);
}



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



