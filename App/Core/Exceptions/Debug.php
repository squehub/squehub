<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

use App\Foundation\Application;
use Whoops\Run;

// Legacy callers can include this file directly; resolve the same Application
// configuration used by the HTTP kernel instead of trusting DEBUG_MODE.
require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
if (!isset($squehubApp) || !$squehubApp instanceof Application) {
    $squehubApp = require dirname(__DIR__, 3) . '/Bootstrap/App.php';
}

if (!$squehubApp->isDebug()) {
    // Never register a detailed exception handler when debug is disabled.
    error_reporting(0);
    ini_set('display_errors', '0');
    return;
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

// The modern HTTP exception handler owns normal responses. Whoops remains
// available for direct legacy paths only while application debug is enabled.
if (class_exists(Run::class)) {
    $whoops = new Run();
    $whoops->pushHandler(new CustomPrettyPageHandler());
    $whoops->register();
}

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
