<?php

declare(strict_types=1);

use App\Auth\AuthManager;
use App\Auth\AuthServiceProvider;
use App\Database\DatabaseServiceProvider;
use App\Foundation\Application;
use App\Http\HttpServiceProvider;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Idempotency\IdempotencyServiceProvider;
use App\Idempotency\Middleware\IdempotentRequests;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use App\Security\Csrf\CsrfServiceProvider;
use App\Session\SessionServiceProvider;
use SqueHub\Tests\Fixtures\IdempotencyWorkerUser;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/IdempotencyWorkerUser.php';

[$script, $root, $role, $started, $release, $counter] = $argv;
$idempotencyKey = $argv[6] ?? 'shared-request-key';
$app = new Application($root);
foreach ([DatabaseServiceProvider::class, SessionServiceProvider::class,
    CsrfServiceProvider::class, HttpServiceProvider::class, RoutingServiceProvider::class,
    AuthServiceProvider::class, IdempotencyServiceProvider::class] as $provider) {
    $app->register($provider);
}
$app->bootstrap();
$user = IdempotencyWorkerUser::find(1);
if ($user === null) throw new RuntimeException('Worker identity is missing.');
$app->container()->make(AuthManager::class)->login($user);
$app->container()->make(RouteRegistry::class)->post('/api/operation',
    static function () use ($role, $started, $release, $counter): Response {
        $stream = fopen($counter, 'c+b');
        if ($stream === false || !flock($stream, LOCK_EX)) throw new RuntimeException('Counter unavailable.');
        try {
            $value = (int) stream_get_contents($stream);
            rewind($stream);
            ftruncate($stream, 0);
            fwrite($stream, (string) ($value + 1));
            fflush($stream);
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
        if ($role === 'crash') {
            file_put_contents($started, 'started');
            exit(0); // Leave an authentic in-progress record behind.
        }
        if ($role === 'leader') {
            file_put_contents($started, 'started');
            $deadline = hrtime(true) + 10_000_000_000;
            while (!file_exists($release)) {
                if (hrtime(true) >= $deadline) throw new RuntimeException('Worker release timed out.');
                usleep(10000);
            }
        }
        return new Response('result', 201, ['Content-Type' => 'text/plain']);
    })->through(['auth', IdempotentRequests::authenticated()]);
$response = $app->container()->make(Kernel::class)->handle(new Request('POST', '/api/operation',
    headers: ['Accept' => 'application/json', 'Content-Type' => 'application/json',
        'Idempotency-Key' => $idempotencyKey], rawBody: '{"value":1}'));
echo json_encode(['status' => $response->status(), 'body' => $response->content(),
    'replayed' => $response->header('Idempotency-Replayed')], JSON_THROW_ON_ERROR);
