<?php
// app/clis/addons/dump.php

require_once __DIR__ . '/../../../config.php';

use App\Core\Dumper;

$dumperClass = $argv[1] ?? null;

if (!$dumperClass) {
    echo "Usage: php squehub dump:run ClassName\n";
    echo "Example: php squehub dump:run UsersDumper\n";
    exit;
}

$file = BASE_DIR . "/database/dumper/{$dumperClass}.php";

if (!file_exists($file)) {
    echo "❌ File for [$dumperClass] not found in database/dumper.\n";
    exit;
}

require_once $file;

// Try to detect class without namespace
if (!class_exists($dumperClass)) {
    echo "❌ Class [$dumperClass] not found. Did you forget to define the class without a namespace?\n";
    exit;
}

$instance = new $dumperClass();

if (!($instance instanceof Dumper)) {
    echo "❌ [$dumperClass] must extend App\\Core\\Dumper.\n";
    exit;
}

$instance->run();
