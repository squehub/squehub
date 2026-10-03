<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Database\ModelClock;
use App\Reliability\CircuitBreaker;
use App\Reliability\CircuitOpenException;
use App\Reliability\CircuitPolicy;
use App\Reliability\FileCircuitStore;

[$script, $root, $namespace, $second, $marker, $mode] = $argv;
$clock = new class ((int) $second) implements ModelClock {
    public function __construct(private int $second)
    {
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $this->second);
    }
};
$breaker = new CircuitBreaker(new FileCircuitStore($root, $namespace), $clock);
$policy = new CircuitPolicy(1, 10, 5, [], [503]);
try {
    $breaker->run('peer', $policy, static function () use ($marker, $mode): string {
        file_put_contents($marker, 'entered');
        if ($mode === 'crash') exit(0);
        if ($mode === 'hold') {
            $release = $marker . '.release';
            $deadline = microtime(true) + 5;
            while (!file_exists($release) && microtime(true) < $deadline) {
                usleep(10000);
            }
            if (!file_exists($release)) throw new RuntimeException('Probe release barrier timed out.');
        }
        return 'complete';
    });
    echo 'entered';
} catch (CircuitOpenException) {
    echo 'blocked';
}
