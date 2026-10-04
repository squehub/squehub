<?php

declare(strict_types=1);

/**
 * Harmless child for cross-platform development-process tests. Ready and
 * trigger files synchronize the parent without assuming scheduler timing.
 */
$mode = $argv[1] ?? '';

if ($mode === 'emit') {
    fwrite(STDOUT, ($argv[2] ?? '') . "\n");
    fwrite(STDERR, ($argv[3] ?? '') . "\n");
    exit((int) ($argv[4] ?? 0));
}

if ($mode !== 'wait' && $mode !== 'trigger-exit') {
    fwrite(STDERR, "Unknown Dev fixture mode.\n");
    exit(64);
}

$ready = $argv[2] ?? '';
$trigger = $argv[3] ?? '';
$heartbeat = $argv[4] ?? '';
if ($ready === '' || $trigger === '' || $heartbeat === '') {
    fwrite(STDERR, "Dev fixture paths are required.\n");
    exit(64);
}

// The bounded deadline ensures even a broken supervisor cannot leave a test
// child alive indefinitely. Tests release or terminate it much sooner.
$deadline = microtime(true) + 15.0;
file_put_contents($ready, (string) getmypid());
while (!is_file($trigger) && microtime(true) < $deadline) {
    file_put_contents($heartbeat, '.', FILE_APPEND);
    usleep(50_000);
}

if (!is_file($trigger)) {
    fwrite(STDERR, "Dev fixture timed out.\n");
    exit(70);
}

if ($mode === 'trigger-exit') {
    fwrite(STDOUT, ($argv[5] ?? 'triggered') . "\n");
    fwrite(STDERR, ($argv[6] ?? 'fixture failure') . "\n");
    exit((int) ($argv[7] ?? 1));
}

fwrite(STDOUT, "Dev fixture stopped.\n");
