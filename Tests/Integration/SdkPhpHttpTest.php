<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Api\Contract\Contract;
use App\Api\Contract\ContractManager;
use App\Api\Sdk\SdkGenerator;
use App\Routing\Route;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\SdkHttpApplication;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__) . '/Fixtures/SdkHttpApplication.php';

/** Mandatory generated PHP client proof through real loopback HTTP and Kernel. */
final class SdkPhpHttpTest extends TestCase
{
    private TemporaryProject $project;
    private ?Process $server = null;
    private string $baseUrl;
    private string $token;

    protected function setUp(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('Generated PHP HTTP qualification requires local cURL.');
        }
        $this->project = new TemporaryProject();
        $this->project->write('Config/Api.php', '<?php return ' . var_export([
            'enabled' => true,
            'paths' => ['/api'],
            'versioning' => ['strategy' => 'header', 'header' => 'X-API-Version'],
        ], true) . ';');
        $this->token = 'sdk-local-' . bin2hex(random_bytes(12));
        $app = SdkHttpApplication::create($this->project->path(), $this->token);
        $generator = new SdkGenerator($app->container()->make(ContractManager::class));
        $result = $generator->generate('php', 'Generated/Php', $this->project->path(), 'v1');
        self::assertSame(6, $result['operations']);
        $autoload = $this->project->path('Generated/Php/autoload.php');
        self::assertFileExists($autoload);
        require_once $autoload;

        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        self::assertNotFalse($socket, 'Loopback socket is required for generated PHP HTTP proof.');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        $this->baseUrl = 'http://127.0.0.1:' . $port;
        $this->server = new Process([
            PHP_BINARY, dirname(__DIR__) . '/Fixtures/SdkKernelServer.php', (string) $port,
        ], dirname(__DIR__, 2), [
            'SQUEHUB_SDK_FIXTURE_ROOT' => $this->project->path(),
            'SQUEHUB_SDK_FIXTURE_TOKEN' => $this->token,
        ]);
        $this->server->start();
        $deadline = microtime(true) + 5;
        do {
            $probe = @stream_socket_client('tcp://127.0.0.1:' . $port,
                $errorNumber, $errorMessage, 0.1);
            if ($probe !== false) {
                fclose($probe);
                return;
            }
            if (!$this->server->isRunning()) break;
            usleep(20000);
        } while (microtime(true) < $deadline);
        self::fail('Local SqueHub SDK Kernel server could not start. '
            . $this->server->getErrorOutput());
    }

    protected function tearDown(): void
    {
        if ($this->server !== null && $this->server->isRunning()) {
            $port = (int) parse_url($this->baseUrl, PHP_URL_PORT);
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $port,
                $errorNumber, $errorMessage, 1);
            if ($socket !== false) {
                fwrite($socket, "GET /shutdown HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
                fclose($socket);
            }
            $deadline = microtime(true) + 3;
            while ($this->server->isRunning() && microtime(true) < $deadline) usleep(10000);
            if ($this->server->isRunning()) $this->server->stop(0);
        }
        Contract::setResolver(null);
        Route::setResolver(null);
        if (isset($this->project)) $this->project->remove();
    }

    public function testGeneratedClientUsesRealHttpForPathsBodiesPagesErrorsAndAuth(): void
    {
        $client = new \SqueHubGenerated\Client(
            baseUrl: $this->baseUrl,
            tokenProvider: fn (): string => $this->token,
        );

        $echo = $client->mirrorShow([
            'path' => ['value' => 'Lagos é &?=#'],
            'query' => ['q' => 'a &=?# é'],
        ]);
        self::assertSame('Lagos é &?=#', $echo['value']);
        self::assertSame('a &=?# é', $echo['q']);
        self::assertSame('v1', $echo['version']);

        $created = $client->itemsCreate(['body' => ['name' => 'Ada']]);
        self::assertInstanceOf(\SqueHubGenerated\Model\SdkItem::class, $created);
        self::assertSame(7, $created->getId());
        self::assertSame('Ada', $created->getName());

        $page = $client->itemsIndex();
        self::assertInstanceOf(\SqueHubGenerated\Page::class, $page);
        self::assertCount(2, $page->data);
        self::assertSame(1, $page->meta['page']);
        self::assertSame(3, $page->meta['total']);
        self::assertNull($client->itemsRemove(['path' => ['id' => 7]]));

        $secure = $client->secureShow();
        self::assertTrue($secure['authorized']);
        $withoutToken = new \SqueHubGenerated\Client(baseUrl: $this->baseUrl);
        try {
            $withoutToken->secureShow();
            self::fail('Missing PAT should yield an API error.');
        } catch (\SqueHubGenerated\ApiException $exception) {
            self::assertSame(401, $exception->status);
            self::assertSame('unauthenticated', $exception->errorCode);
            self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/D', $exception->requestId);
            self::assertStringNotContainsString($this->token, $exception->getMessage());
        }

        try {
            $client->errorsShow();
            self::fail('Declared 422 should yield an API error.');
        } catch (\SqueHubGenerated\ApiException $exception) {
            self::assertSame(422, $exception->status);
            self::assertSame('validation_failed', $exception->errorCode);
            self::assertSame('Bad item.', $exception->apiMessage);
            self::assertSame(['name' => ['Name is required.']], $exception->details);
            self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/D', $exception->requestId);
        }

        $this->expectException(\SqueHubGenerated\ProtocolException::class);
        $client->mirrorShow(['path' => ['value' => 'a/b']]);
    }
}
