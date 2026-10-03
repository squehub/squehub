<?php

declare(strict_types=1);

/** Coordinated child process for the file store's cross-process lock test. */
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

[$script, $root, $prefix, $fingerprint, $barrier, $ready, $limit] = $argv;
$store = new \App\RateLimit\Stores\FileRateLimitStore($root, $prefix);
file_put_contents($ready, 'ready');
$deadline = microtime(true) + 10;
while (!file_exists($barrier)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, 'Barrier timed out.');
        exit(2);
    }
    usleep(1000);
}
try {
    $result = $store->consume($fingerprint, (int) $limit, 60, new DateTimeImmutable('@1000'));
    echo $result->allowed() ? 'allowed' : 'denied';
} catch (Throwable $failure) {
    fwrite(STDERR, $failure::class . ': ' . $failure->getMessage());
    exit(3);
}
