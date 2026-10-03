<?php

declare(strict_types=1);

namespace App\Agent;

use App\Foundation\Application;
use Throwable;

/**
 * Provider-neutral Agent boundary. Every resource read and tool call checks
 * this Application's permissions again; MCP discovery never grants access.
 */
final class AgentManager
{
    private CapabilitySet $capabilities;
    private AgentContext $context;
    private AgentInspector $inspector;
    private AgentPlanService $plans;

    /** @param array<array-key,mixed>|null $cliInventory */
    public function __construct(Application $app, ?array $cliInventory = null)
    {
        $grants = $app->config()->get('agent.grants', []);
        if (!is_array($grants)) {
            throw new AgentException('Agent capability configuration is invalid.');
        }
        $commands = self::commandInventory($cliInventory);
        $this->capabilities = new CapabilitySet($app, $grants);
        $this->context = new AgentContext($app, $this->capabilities, $commands);
        $this->inspector = new AgentInspector($app, $this->capabilities);
        $this->plans = new AgentPlanService($app, $this->capabilities);
    }

    /** @return list<array{name:string,risk:string,mode:string,allowed:bool,scope:?string,scope_values:list<string>,supported:bool}> */
    public function capabilities(): array
    {
        return $this->capabilities->inventory();
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $plan = false;
        foreach ($this->capabilities() as $row) {
            if ($row['name'] === 'create_plan') $plan = $row['allowed'];
        }
        return AgentOutput::safe([
            'framework' => ['name' => 'SqueHub', 'version' => AgentContext::VERSION,
                'release' => 'unreleased'],
            'protocol' => ['transport' => 'stdio',
                'supported_stdio' => AgentContext::STDIO_PROTOCOL],
            'mode' => $plan ? 'inspection-and-proposal' : 'read-only',
            'application' => ['fingerprint' => $this->capabilities->applicationFingerprint()],
            'capabilities' => $this->capabilities(), 'remote_http' => false,
        ]);
    }

    /** @return list<array{uri:string,name:string,description:string,mimeType:string}> */
    public function resources(): array
    {
        $definitions = [
            ['squehub://framework', 'Framework', 'Installed framework and public API metadata.', 'read_framework_metadata'],
            ['squehub://application/contract', 'Application Contract', 'Registered public operation summary.', 'read_application_contract'],
            ['squehub://routes', 'Routes', 'Registered or validated cached route metadata.', 'read_routes'],
            ['squehub://packages', 'Packages and Kits', 'Activation and contribution metadata.', 'read_package_metadata'],
            ['squehub://health', 'Health', 'Non-probing health and infrastructure metadata.', 'read_health_metadata'],
            ['squehub://cli', 'CLI', 'Actual registered command inventory when supplied by the console.', 'read_framework_metadata'],
            ['squehub://schema', 'Schema', 'Configured database connection metadata only.', 'read_schema'],
        ];
        $items = [];
        foreach ($definitions as [$uri, $name, $description, $capability]) {
            if (!$this->discoverable($capability)) continue;
            $items[] = ['uri' => $uri, 'name' => $name,
                'description' => $description, 'mimeType' => 'application/json'];
        }
        return $items;
    }

    /** @return array<string,mixed> */
    public function resource(string $uri): array
    {
        try {
            $value = match ($uri) {
                'squehub://framework' => $this->authorized('read_framework_metadata',
                    fn (): array => $this->context->framework()),
                'squehub://application/contract' => $this->authorized('read_application_contract',
                    fn (): array => $this->context->applicationContract()),
                'squehub://routes' => $this->authorized('read_routes',
                    fn (): array => $this->context->routes()),
                'squehub://packages' => $this->authorized('read_package_metadata',
                    fn (): array => $this->context->packages()),
                'squehub://health' => $this->authorized('read_health_metadata',
                    fn (): array => $this->context->health()),
                'squehub://cli' => $this->authorized('read_framework_metadata',
                    fn (): array => $this->context->cli()),
                'squehub://schema' => $this->authorized('read_schema',
                    fn (): array => $this->context->schema()),
                default => throw new AgentException('Agent resource is unavailable.'),
            };
            return AgentOutput::safe($value);
        } catch (AgentException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new AgentException('Agent resource could not be inspected safely.');
        }
    }

    /** @return list<array{name:string,description:string,inputSchema:array<string,mixed>}> */
    public function tools(): array
    {
        $tools = [];
        if ($this->discoverable('read_docs')) {
            $tools[] = ['name' => 'search_docs', 'description' => 'Search bounded local SqueHub v2 documentation excerpts.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'query' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 120],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
                ], 'required' => ['query'], 'additionalProperties' => false]];
        }
        if ($this->discoverable('create_plan')) {
            $tools[] = ['name' => 'create_plan', 'description' => 'Create a review-only SqueHub ChangePlan for a supported operation.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'operation' => ['type' => 'string', 'enum' => AgentPlanService::OPERATIONS],
                    'target' => ['type' => 'string', 'maxLength' => 120],
                ], 'required' => ['operation'], 'additionalProperties' => false]];
        }
        if ($this->discoverable('read_schema')) {
            $tools[] = ['name' => 'inspect_schema', 'description' => 'Inspect one table using an explicitly granted connection.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'table' => ['type' => 'string', 'maxLength' => 64],
                    'connection' => ['type' => 'string', 'maxLength' => 128],
                ], 'required' => ['table'], 'additionalProperties' => false]];
        }
        return $tools;
    }

    /** @param array<string,mixed> $arguments @return array<string,mixed> */
    public function tool(string $name, array $arguments): array
    {
        try {
            $value = match ($name) {
                'search_docs' => $this->searchDocs($arguments),
                'create_plan' => $this->createPlan($arguments),
                'inspect_schema' => $this->inspectSchema($arguments),
                default => throw new AgentException('Agent tool is unavailable.'),
            };
            return AgentOutput::safe($value);
        } catch (AgentException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new AgentException('Agent tool could not complete safely.');
        }
    }

    /** @param array<string,mixed> $arguments @return array<string,mixed> */
    private function searchDocs(array $arguments): array
    {
        self::arguments($arguments, ['query', 'limit'], ['query']);
        if (!is_string($arguments['query']) || (isset($arguments['limit']) && !is_int($arguments['limit']))) {
            throw new AgentException('Documentation search arguments are invalid.');
        }
        return $this->inspector->searchDocs($arguments['query'], $arguments['limit'] ?? 5);
    }

    /** @param array<string,mixed> $arguments @return array<string,mixed> */
    private function createPlan(array $arguments): array
    {
        self::arguments($arguments, ['operation', 'target'], ['operation']);
        if (!is_string($arguments['operation'])
            || (isset($arguments['target']) && !is_string($arguments['target']))) {
            throw new AgentException('Agent plan request is invalid.');
        }
        return $this->plans->propose($arguments['operation'], $arguments['target'] ?? '');
    }

    /** @param array<string,mixed> $arguments @return array<string,mixed> */
    private function inspectSchema(array $arguments): array
    {
        self::arguments($arguments, ['table', 'connection'], ['table']);
        if (!is_string($arguments['table'])
            || (isset($arguments['connection']) && !is_string($arguments['connection']))) {
            throw new AgentException('Schema inspection arguments are invalid.');
        }
        return $this->inspector->inspectSchema($arguments['table'], $arguments['connection'] ?? '');
    }

    /** @param array<string,mixed> $arguments @param list<string> $allowed @param list<string> $required */
    private static function arguments(array $arguments, array $allowed, array $required): void
    {
        foreach (array_keys($arguments) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new AgentException('Agent tool arguments are invalid.');
            }
        }
        foreach ($required as $key) {
            if (!array_key_exists($key, $arguments)) {
                throw new AgentException('Agent tool arguments are invalid.');
            }
        }
    }

    /** @param callable():array<string,mixed> $read @return array<string,mixed> */
    private function authorized(string $capability, callable $read): array
    {
        if ($capability === 'read_schema') {
            if (!$this->discoverable($capability)) {
                throw new AgentException('Agent capability is unavailable or denied.');
            }
        } else {
            $this->capabilities->require($capability);
        }
        return $read();
    }

    private function discoverable(string $name): bool
    {
        foreach ($this->capabilities() as $capability) {
            if ($capability['name'] === $name) return $capability['allowed'];
        }
        return false;
    }

    /** @param array<array-key,mixed>|null $commands @return list<array{name:string,description:string}>|null */
    private static function commandInventory(?array $commands): ?array
    {
        if ($commands === null) return null;
        if (!array_is_list($commands) || count($commands) > 256) {
            throw new AgentException('Agent CLI inventory is invalid.');
        }
        $items = [];
        foreach ($commands as $row) {
            if (!is_array($row) || array_keys($row) !== ['name', 'description']
                || !is_string($row['name']) || !is_string($row['description'])
                || preg_match('/\A[a-z][a-z0-9:-]{0,79}\z/D', $row['name']) !== 1
                || strlen($row['description']) > 240
                || preg_match('/[\x00-\x1F\x7F]/', $row['description']) === 1) {
                throw new AgentException('Agent CLI inventory is invalid.');
            }
            $items[] = $row;
        }
        return $items;
    }
}
