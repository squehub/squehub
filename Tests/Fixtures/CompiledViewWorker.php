<?php

declare(strict_types=1);

/** Coordinates one real PHP renderer in the compiled-View publication race. */
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

[$script, $root, $barrier, $ready] = $argv;

$app = new \App\Foundation\Application($root);
\App\Support\RuntimeContext::select($app);
file_put_contents($ready, 'ready');

$deadline = microtime(true) + 20;
while (!is_file($barrier)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, 'View compilation barrier timed out.');
        exit(2);
    }
    clearstatcache(true, $barrier);
    usleep(1000);
}

try {
    echo \App\Core\View::renderResult('Pages.Race')->html();
} catch (\Throwable $failure) {
    $details = [];
    do {
        $details[] = $failure::class . ': ' . $failure->getMessage();
        $failure = $failure->getPrevious();
    } while ($failure !== null);
    fwrite(STDERR, implode(' <- ', $details));
    exit(3);
}
