<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Enum\ProtocolVersion;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/** Exercise the optional MCP adapter against the official SDK over real STDIO. */
final class AgentMcpTest extends TestCase
{
    /** An official client must negotiate, inspect and call only published capabilities. */
    public function testOfficialClientCanInspectAndCallOverStdio(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Official SDK client STDIO pipe polling blocks on Windows; the live protocol client test below covers this host.');
        }
        self::assertTrue(class_exists(Client::class));
        $client = Client::builder()
            ->setClientInfo('SqueHub integration test', '1.0.0')
            ->setProtocolVersion(ProtocolVersion::V2025_11_25)
            ->setInitTimeout(10)
            ->setRequestTimeout(10)
            ->setMaxRetries(0)
            ->build();

        $client->connect(new StdioTransport(PHP_BINARY, ['squehub', 'agent:mcp'], self::root()));
        try {
            self::assertSame(ProtocolVersion::V2025_11_25, $client->getProtocolVersion());
            self::assertSame('2.0.0', $client->getServerInfo()?->version);

            $resources = $client->listResources()->resources;
            $uris = array_map(static fn ($resource): string => $resource->uri, $resources);
            self::assertContains('squehub://framework', $uris);
            self::assertNotContains('squehub://schema', $uris);

            $framework = $client->readResource('squehub://framework')->contents;
            self::assertCount(1, $framework);
            self::assertInstanceOf(TextResourceContents::class, $framework[0]);
            $context = json_decode($framework[0]->text, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('2025-11-25', $context['protocol']['supported_stdio']);
            self::assertSame('2.0.0', $context['framework']['version']);

            $names = array_map(static fn ($tool): string => $tool->name, $client->listTools()->tools);
            self::assertContains('search_docs', $names);
            self::assertNotContains('create_plan', $names);
            self::assertNotContains('inspect_schema', $names);

            $search = $client->callTool('search_docs', ['query' => 'routing', 'limit' => 2]);
            self::assertFalse($search->isError);
            self::assertIsArray($search->structuredContent);
            self::assertArrayNotHasKey('APP_KEY', $search->structuredContent);
        } finally {
            $client->disconnect();
        }
    }

    /** A live client sends each request after seeing the previous response. */
    public function testLiveClientCanNegotiateInspectAndRestart(): void
    {
        for ($run = 0; $run < 2; ++$run) {
            $input = new InputStream();
            $process = new Process([PHP_BINARY, 'squehub', 'agent:mcp'], self::root());
            // A cold Windows PHP process may need more time for the first
            // handshake; subsequent STDIO exchanges keep a tighter limit.
            $process->setTimeout(60);
            $process->setIdleTimeout(30);
            $process->setInput($input);
            $process->start();
            $output = '';
            $responses = [];
            try {
                $init = $this->request($process, $input, $output, $responses, 1, 'initialize', [
                    'protocolVersion' => '2025-11-25', 'capabilities' => [],
                    'clientInfo' => ['name' => 'SqueHub live client', 'version' => '1.0.0'],
                ]);
                self::assertSame('2025-11-25', $init['result']['protocolVersion'] ?? null);
                self::assertSame('2.0.0', $init['result']['serverInfo']['version'] ?? null);
                $process->setIdleTimeout(10);

                $input->write(json_encode(['jsonrpc' => '2.0',
                    'method' => 'notifications/initialized'], JSON_THROW_ON_ERROR) . "\n");
                $listed = $this->request($process, $input, $output, $responses, 2, 'resources/list');
                $uris = array_column($listed['result']['resources'] ?? [], 'uri');
                self::assertContains('squehub://framework', $uris);
                self::assertNotContains('squehub://schema', $uris);

                $read = $this->request($process, $input, $output, $responses, 3,
                    'resources/read', ['uri' => 'squehub://framework']);
                $context = json_decode($read['result']['contents'][0]['text'] ?? '', true,
                    512, JSON_THROW_ON_ERROR);
                self::assertSame('2025-11-25', $context['protocol']['supported_stdio']);

                $tools = $this->request($process, $input, $output, $responses, 4, 'tools/list');
                $names = array_column($tools['result']['tools'] ?? [], 'name');
                self::assertContains('search_docs', $names);
                self::assertNotContains('create_plan', $names);
                $search = $this->request($process, $input, $output, $responses, 5,
                    'tools/call', ['name' => 'search_docs',
                        'arguments' => ['query' => 'routing', 'limit' => 2]]);
                self::assertFalse($search['result']['isError'] ?? false);
                self::assertIsArray($search['result']['structuredContent'] ?? null);
            } finally {
                $input->close();
                try {
                    $process->wait();
                } finally {
                    if ($process->isRunning()) $process->stop(1);
                }
            }
            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            self::assertStringNotContainsString('SqueHub setup:', $process->getOutput());
            // Release Windows pipe handles before starting the next server.
            unset($process, $input);
            gc_collect_cycles();
        }
    }

    /** Malformed or oversized input must not poison a following valid frame. */
    public function testMalformedAndOversizedInputLeaveStdoutAsJsonRpc(): void
    {
        $frames = [
            '{invalid json}',
            str_repeat('x', 256 * 1024),
            json_encode([
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-11-25',
                    'capabilities' => [],
                    'clientInfo' => ['name' => 'SqueHub protocol test', 'version' => '1.0.0'],
                ],
            ], JSON_THROW_ON_ERROR),
            json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], JSON_THROW_ON_ERROR),
            json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/list', 'params' => []], JSON_THROW_ON_ERROR),
            json_encode(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'squehub/unsupported', 'params' => []], JSON_THROW_ON_ERROR),
            json_encode(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call',
                'params' => ['name' => 'search_docs', 'arguments' => [
                    'query' => str_repeat('MCP_SUPER_SECRET_DO_NOT_LEAK', 8),
                ]]], JSON_THROW_ON_ERROR),
        ];
        $process = new Process([PHP_BINARY, 'squehub', 'agent:mcp'], self::root());
        $process->setTimeout(15);
        $process->setInput(implode("\n", $frames) . "\n");
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $responses = [];
        foreach (preg_split('/\R/', trim($process->getOutput())) as $line) {
            if ($line === '') continue;
            $response = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('2.0', $response['jsonrpc'] ?? null);
            $responses[$response['id'] ?? 'notification'][] = $response;
        }
        self::assertArrayHasKey(1, $responses);
        self::assertSame('2025-11-25', $responses[1][0]['result']['protocolVersion'] ?? null);
        self::assertArrayHasKey(2, $responses);
        self::assertNotEmpty($responses[2][0]['result']['resources'] ?? []);
        self::assertArrayHasKey(3, $responses);
        self::assertArrayHasKey('error', $responses[3][0]);
        self::assertArrayHasKey(4, $responses);
        self::assertArrayHasKey('error', $responses[4][0]);
        self::assertStringNotContainsString('SqueHub setup:', $process->getOutput());
        self::assertStringNotContainsString('MCP_SUPER_SECRET_DO_NOT_LEAK',
            $process->getOutput() . $process->getErrorOutput());
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @param array<int,array<string,mixed>> $responses @return array<string,mixed> */
    private function request(Process $process, InputStream $input, string &$output,
        array &$responses, int $id, string $method, array $params = []): array
    {
        $input->write(json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method,
            'params' => $params], JSON_THROW_ON_ERROR) . "\n");
        // waitUntil() can drain a fast reply before it installs its callback.
        // The iterator includes already-buffered output as well as later frames.
        foreach ($process->getIterator(Process::ITER_KEEP_OUTPUT) as $type => $data) {
            if ($type !== Process::OUT) continue;
            $output .= $data;
            while (($end = strpos($output, "\n")) !== false) {
                $line = substr($output, 0, $end);
                $output = substr($output, $end + 1);
                if (trim($line) === '') continue;
                $frame = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($frame) && isset($frame['id']) && is_int($frame['id'])) {
                    $responses[$frame['id']] = $frame;
                }
            }
            if (isset($responses[$id])) break;
        }
        self::assertTrue(isset($responses[$id]), $process->getErrorOutput());
        self::assertArrayHasKey($id, $responses);
        return $responses[$id];
    }
}
