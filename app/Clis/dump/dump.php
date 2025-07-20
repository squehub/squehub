<?php
// app/clis/dump/dump.php

require_once __DIR__ . '/../../../config.php';

use App\Core\Dumper;

$dumperClass = $argv[1] ?? null;
$action = $argv[2] ?? 'run'; // default to run if not specified

if (!$dumperClass) {
    echo "Usage: php squehub dump.php ClassName [run|rollback]\n";
    echo "Example: php squehub dump.php UsersDumper run\n";
    echo "         php squehub dump.php UsersDumper rollback\n";
    exit(1);
}

$file = BASE_DIR . "/database/dumper/{$dumperClass}.php";

if (!file_exists($file)) {
    echo "❌ File for [$dumperClass] not found in database/dumper.\n";
    exit(1);
}

require_once $file;

$fqcn = "Database\\Dumper\\{$dumperClass}";

if (!class_exists($fqcn)) {
    echo "❌ Class [$fqcn] not found. Did you forget to define the class with the correct namespace?\n";
    exit(1);
}

$instance = new $fqcn();

if (!($instance instanceof Dumper)) {
    echo "❌ [$fqcn] must extend App\\Core\\Dumper.\n";
    exit(1);
}

if (!method_exists($instance, $action)) {
    echo "❌ Method [$action] not found in class [$fqcn]. Use 'run' or 'rollback'.\n";
    exit(1);
}

// Call the requested method
$instance->$action();

echo "✅ Dumper {$dumperClass} {$action} executed successfully.\n";
