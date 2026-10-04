<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Database\Database;
use App\Database\DatabaseManager;
use App\Database\DatabaseServiceProvider;
use App\Diagnostics\Diagnostic;
use App\Diagnostics\Diagnostics;
use App\Diagnostics\DiagnosticsServiceProvider;
use App\Foundation\Application;
use App\HttpClient\Http;
use App\HttpClient\HttpServiceProvider;
use App\Queue\Queue;
use App\Queue\QueueServiceProvider;
use App\Webhooks\Webhook;
use App\Webhooks\WebhookEvent;
use App\Webhooks\WebhookServiceProvider;
use App\Webhooks\WebhookSignature;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** A finite local receiver proves the real cURL transport signs exact wire bytes. */
final class WebhookLoopbackTest extends TestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef';

    public function testRealLoopbackDeliverySignsExactBodyWithoutExternalNetwork(): void
    {
        if (!extension_loaded('curl') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('cURL and pdo_sqlite are required for loopback webhook delivery.');
        }
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $number, $message);
        if ($socket === false) self::markTestSkipped('Loopback socket is unavailable.');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($address, strrpos($address, ':') + 1);

        $project = new TemporaryProject();
        $database = null;
        $server = null;
        try {
            $project->write('Config/App.php', '<?php return ["env" => "production", "debug" => false];');
            $project->write('Config/Database.php', '<?php return ' . var_export([
                'default' => 'test', 'connections' => ['test' => [
                    'driver' => 'sqlite', 'database' => $project->path('loopback.sqlite'),
                ]],
            ], true) . ';');
            $project->write('Config/Queue.php', '<?php return '
                . var_export(['default' => 'sync', 'connections' => ['sync' => ['driver' => 'sync']]], true) . ';');
            $project->write('Config/Webhooks.php', '<?php return ' . var_export([
                'delivery_store' => 'database',
                'endpoints' => ['local' => [
                    'url' => 'http://127.0.0.1:' . $port . '/webhook',
                    'allow_local_http' => true,
                    'secret' => self::SECRET,
                ]],
            ], true) . ';');

            $app = new Application($project->path());
            foreach ([DiagnosticsServiceProvider::class, DatabaseServiceProvider::class,
                QueueServiceProvider::class, HttpServiceProvider::class,
                WebhookServiceProvider::class] as $provider) {
                $app->register($provider);
            }
            $app->bootstrap();
            $database = $app->container()->make(DatabaseManager::class);
            require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_26_create_webhook_deliveries.php';
            (new \CreateWebhookDeliveries())->up($database->connection()->pdo(),
                $database->connection()->schema());

            $root = dirname(__DIR__, 2);
            $server = new Process([PHP_BINARY, 'Tests/Fixtures/WebhookLoopbackServer.php',
                (string) $port, $project->path('capture.json')], $root);
            $server->disableOutput();
            $server->start();
            $deadline = microtime(true) + 5;
            do {
                $probe = @stream_socket_client('tcp://127.0.0.1:' . $port,
                    $number, $message, 0.1);
                if ($probe !== false) { fclose($probe); break; }
                if (!$server->isRunning()) break;
                usleep(20000);
            } while (microtime(true) < $deadline);
            if ($probe === false) self::markTestSkipped('Local PHP webhook receiver could not start.');

            $event = Webhook::event('invoice.paid', [
                'marker' => 'SQUEHUB_WEBHOOK_WIRE_PRIVATE', 'note' => 'Lagos é',
            ]);
            $result = Webhook::endpoint('local')->send($event);
            self::assertTrue($result->successful());
            self::assertSame(204, $result->status());
            self::assertFileExists($project->path('capture.json'));
            $capture = json_decode((string) file_get_contents($project->path('capture.json')),
                true, 512, JSON_THROW_ON_ERROR);
            self::assertStringStartsWith('POST /webhook HTTP/1.', $capture['request_line']);
            self::assertSame($event->body(), $capture['body']);
            self::assertSame($event->id(), $capture['headers']['squehub-webhook-id']);
            self::assertSame($result->deliveryId(),
                $capture['headers']['squehub-webhook-delivery-id']);
            self::assertSame('application/json', $capture['headers']['content-type']);
            self::assertTrue(WebhookSignature::verify($event->id(), $result->deliveryId(),
                (int) $capture['headers']['squehub-webhook-timestamp'], $capture['body'],
                $capture['headers']['squehub-webhook-signature'], [self::SECRET]));
            self::assertSame($event->body(), WebhookEvent::fromJson($capture['body'])->body());
            $row = $database->connection()->raw(
                'SELECT `state`, `last_http_status` FROM `webhook_deliveries`')->fetch();
            self::assertSame('succeeded', $row['state']);
            self::assertSame(204, (int) $row['last_http_status']);
            self::assertStringNotContainsString('SQUEHUB_WEBHOOK_WIRE_PRIVATE',
                json_encode($app->container()->make(Diagnostics::class)->snapshot(),
                    JSON_THROW_ON_ERROR));
        } finally {
            if ($server !== null && $server->isRunning()) $server->stop(0);
            Webhook::setResolver(null);
            Queue::setResolver(null);
            Http::setResolver(null);
            Database::setResolver(null);
            Diagnostic::setResolver(null);
            $database?->disconnect();
            $project->remove();
        }
    }
}
