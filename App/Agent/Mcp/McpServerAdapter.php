<?php

declare(strict_types=1);

namespace App\Agent\Mcp;

use App\Agent\AgentManager;
use App\Agent\AgentContext;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;
use RuntimeException;

/**
 * Connect SqueHub's bounded Agent operations to the optional official MCP SDK.
 *
 * The SDK owns JSON-RPC parsing and STDIO framing. This adapter registers only
 * operations returned by AgentManager's capability-filtered inventory; the
 * manager checks permission again when each resource or tool is invoked.
 */
final class McpServerAdapter
{
    private const MAX_INPUT_LINE_BYTES = 256 * 1024;
    private const MAX_RESULT_BYTES = 64 * 1024;

    public static function available(): bool
    {
        return class_exists(Server::class) && class_exists(StdioTransport::class);
    }

    /**
     * Run the local protocol process until its input closes.
     *
     * @param resource|null $input  A supplied stream is useful for bounded protocol tests.
     * @param resource|null $output The ordinary command reserves STDOUT for MCP messages.
     */
    public function run(AgentManager $manager, mixed $input = null, mixed $output = null): int
    {
        if (!self::available()) {
            throw new RuntimeException('The optional MCP SDK is unavailable. Install mcp/sdk to use agent:mcp.');
        }

        $input ??= STDIN;
        $output ??= STDOUT;
        if (!is_resource($input) || !is_resource($output)) {
            throw new RuntimeException('MCP STDIO streams are unavailable.');
        }

        // This local STDIO integration deliberately advertises the 2025-11-25
        // handshake revision. SDK 0.8.1 also implements the newer stateless era.
        $builder = Server::builder()
            ->setServerInfo('SqueHub', AgentContext::VERSION, 'Capability-bound SqueHub application context')
            ->setProtocolVersion(ProtocolVersion::V2025_11_25)
            ->withoutModernEra()
            ->setPaginationLimit(50)
            ->setInstructions('Inspect SqueHub context first. Create a reviewable plan before any human-applied change.');

        foreach ($this->quiet(static fn (): array => $manager->resources()) as $resource) {
            $uri = $resource['uri'];
            $builder->addResource(
                handler: fn (): array => $this->bounded($this->quiet(
                    static fn (): array => $manager->resource($uri)
                )),
                uri: $uri,
                name: $resource['name'],
                description: $resource['description'],
                mimeType: $resource['mimeType'],
            );
        }

        foreach ($this->quiet(static fn (): array => $manager->tools()) as $tool) {
            $name = $tool['name'];
            $handler = match ($name) {
                'search_docs' => fn (string $query, int $limit = 5): array => $this->bounded(
                    $this->quiet(static fn (): array => $manager->tool('search_docs', [
                        'query' => $query, 'limit' => $limit,
                    ]))
                ),
                'create_plan' => fn (string $operation, string $target = ''): array => $this->bounded(
                    $this->quiet(static fn (): array => $manager->tool('create_plan', [
                        'operation' => $operation, 'target' => $target,
                    ]))
                ),
                'inspect_schema' => fn (string $table, string $connection = ''): array => $this->bounded(
                    $this->quiet(static fn (): array => $manager->tool('inspect_schema', [
                        'table' => $table, 'connection' => $connection,
                    ]))
                ),
                default => throw new RuntimeException('Unsupported Agent tool registration.'),
            };

            $builder->addTool(
                handler: $handler,
                name: $name,
                description: $tool['description'],
                annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, openWorldHint: false),
                inputSchema: $tool['inputSchema'],
                outputSchema: ['type' => 'object'],
            );
        }

        // No application logger is injected: SDK debug traces include tool
        // arguments. The default NullLogger keeps those arguments private.
        return $builder->build()->run(new StdioTransport(
            input: $input,
            output: $output,
            maxLineBytes: self::MAX_INPUT_LINE_BYTES,
        ));
    }

    /**
     * Discard incidental application output before it can corrupt STDIO.
     * Application route declarations may be inspected by a resource handler.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function quiet(callable $operation): mixed
    {
        $level = ob_get_level();
        ob_start(static fn (string $output): string => '');
        try {
            return $operation();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    /**
     * Keep one Agent response smaller than the STDIO client's usual frame cap.
     * The manager also bounds individual lists, excerpts, and plan operations.
     *
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function bounded(array $result): array
    {
        $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        if (strlen($json) > self::MAX_RESULT_BYTES) {
            throw new RuntimeException('Agent result exceeds the MCP response limit.');
        }
        return $result;
    }
}
