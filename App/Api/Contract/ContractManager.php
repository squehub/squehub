<?php

declare(strict_types=1);

namespace App\Api\Contract;

use App\Api\ApiResource;
use App\Api\ApiVersionPolicy;
use App\Config\Repository;
use App\Routing\RouteRegistry;
use JsonException;

/**
 * Application-scoped registry and normalizer for declared network behavior.
 * This is SqueHub's model: exporters consume a detached value snapshot rather
 * than making OpenAPI Objects the framework's runtime representation.
 */
final class ContractManager
{
    /** @var array<string,Schema> */
    private array $schemas = [];
    /** @var array<string,string> Public tag descriptions are declared separately from operations. */
    private array $tags = [];
    /** @var array<string,array{schema:Schema,description:?string}> */
    private array $webhooks = [];
    /** @var array{title:string,version:string,description:?string}|null */
    private ?array $info = null;
    /** @var list<string>|null */
    private ?array $servers = null;
    /** @var array<string,VerificationCase> Explicit cases are private runtime state. */
    private array $verificationCases = [];

    public function __construct(private RouteRegistry $routes, private Repository $config,
        private OpenApiCompiler $compiler)
    {
    }

    public function schema(string $name, Schema $schema): void
    {
        self::componentName($name);
        if (isset($this->schemas[$name])) {
            throw new ContractException('A contract schema name is already registered.');
        }
        $this->schemas[$name] = $schema;
    }

    /** Register an executable case without running its setup or HTTP handler. */
    public function verify(string $name): VerificationCase
    {
        OperationContract::identifier($name, 'verification case name');
        if (isset($this->verificationCases[$name])) {
            throw new ContractException('A verification case name is already registered.');
        }
        return $this->verificationCases[$name] = new VerificationCase($name);
    }

    /** @return list<VerificationCase> */
    public function verificationCases(): array
    {
        $cases = $this->verificationCases;
        ksort($cases, SORT_STRING);
        return array_values($cases);
    }

    /**
     * The resource class supplies its own optional schema. Export never
     * constructs a resource, Model, or fake source to discover field names.
     *
     * @param class-string<ApiResource> $resourceClass
     */
    public function resource(string $name, string $resourceClass): void
    {
        if (!is_subclass_of($resourceClass, ApiResource::class)) {
            throw new ContractException('Contract resource must extend ApiResource.');
        }
        $schema = $resourceClass::contractSchema();
        if ($schema === null) {
            throw new ContractException('Contract resource has no declared schema.');
        }
        $this->schema($name, $schema);
    }

    public function tag(string $name, string $description): void
    {
        OperationContract::identifier($name, 'tag');
        self::text($description, 8192, true);
        if (isset($this->tags[$name])) {
            throw new ContractException('A contract tag is already registered.');
        }
        $this->tags[$name] = $description;
    }

    /** Register an outgoing event type, never a configured endpoint URL. */
    public function webhook(string $type, Schema $dataSchema,
        ?string $description = null): void
    {
        if (strlen($type) > 128
            || preg_match('/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/D', $type) !== 1) {
            throw new ContractException('Contract webhook type is invalid.');
        }
        if ($description !== null) self::text($description, 8192);
        if (isset($this->webhooks[$type])) {
            throw new ContractException('A webhook event contract is already registered.');
        }
        $this->webhooks[$type] = ['schema' => $dataSchema, 'description' => $description];
    }

    public function info(string $title, string $version,
        ?string $description = null): void
    {
        self::text($title, 256, true);
        self::text($version, 128, true);
        if ($description !== null) self::text($description, 8192);
        $this->info = ['title' => $title, 'version' => $version,
            'description' => $description];
    }

    /** Servers are explicit, never derived from Host or forwarding headers. */
    public function server(string $url): void
    {
        self::serverUrl($url);
        if ($this->servers === null) $this->servers = [];
        if (in_array($url, $this->servers, true)) {
            throw new ContractException('A contract server URL is already registered.');
        }
        $this->servers[] = $url;
    }

    /**
     * Return a deterministic versioned SqueHub artifact. The copied fields are
     * public declarations only: no controller, middleware, secret config, or
     * request-scoped data is included. References remain references, including
     * intentional recursive schemas.
     */
    public function application(?string $apiVersion = null): array
    {
        if ($apiVersion !== null) $apiVersion = ApiVersionPolicy::normalizeIdentifier($apiVersion);
        $metadata = $this->metadata();
        $schemas = [];
        foreach ($this->schemas as $name => $schema) $schemas[$name] = $schema->toArray();

        $operations = [];
        $seenIds = [];
        $seenPaths = [];
        $strategy = $this->versionStrategy();
        foreach ($this->routes->all() as $route) {
            $contract = $route->contractValue();
            if ($contract === null || !$contract->isVisible()) continue;
            $version = $route->apiVersionValue();
            if ($apiVersion !== null && $version !== $apiVersion) continue;
            $definition = $contract->toArray();
            if ($definition['responses'] === []) {
                throw new ContractException('Public contract operations require a response.');
            }
            $this->validatePathParameters($route->uri(), $definition['parameters']);
            if ($version !== null && $strategy === 'header') {
                $this->addVersionHeader($definition, $version);
            }
            if ($definition['security']['type'] === 'session') {
                $definition['security']['cookie_name'] = $this->sessionCookieName();
            }
            $this->sortOperationFields($definition);
            $baseId = $contract->operationId() ?? $route->nameValue();
            if ($baseId === null) {
                throw new ContractException('Public routes need a name or explicit operation ID.');
            }
            OperationContract::identifier($baseId, 'operation ID');
            foreach ($route->methods() as $method) {
                $id = count($route->methods()) > 1 ? $baseId . '.' . strtolower($method) : $baseId;
                OperationContract::identifier($id, 'operation ID');
                if (isset($seenIds[$id])) {
                    throw new ContractException('Contract operation ID is duplicated.');
                }
                $identity = $method . ' ' . $route->uri();
                if (isset($seenPaths[$identity])) {
                    throw new ContractException('Contract method and path are duplicated.');
                }
                $seenIds[$id] = true;
                $seenPaths[$identity] = true;
                $operations[] = ['operation_id' => $id, 'method' => $method,
                    'path' => $route->uri(), 'route_name' => $route->nameValue(),
                    'api_version' => $version] + $definition;
            }
        }
        usort($operations, static fn (array $left, array $right): int =>
            [$left['path'], $left['method'], $left['operation_id']]
            <=> [$right['path'], $right['method'], $right['operation_id']]);

        $webhooks = [];
        foreach ($this->webhooks as $type => $declaration) {
            $webhooks[] = ['type' => $type, 'description' => $declaration['description'],
                'data_schema' => $declaration['schema']->toArray()];
        }
        usort($webhooks, static fn (array $left, array $right): int =>
            strcmp($left['type'], $right['type']));

        $this->addRequiredBuiltins($schemas, $operations, $webhooks);
        ksort($schemas, SORT_STRING);
        $tags = $this->tags;
        ksort($tags, SORT_STRING);
        $artifact = [
            'squehub_contract' => '1',
            'application' => $metadata,
            'tags' => $tags,
            'schemas' => $schemas,
            'operations' => $operations,
            'webhooks' => $webhooks,
        ];
        $this->validateReferences($artifact, $schemas);
        return self::canonicalize($artifact);
    }

    public function openApi(?string $apiVersion = null): array
    {
        return $this->compiler->compile($this->application($apiVersion));
    }

    /** Machine output never includes status text; CLI controls stdout/stderr. */
    public function json(string $format = 'openapi', ?string $apiVersion = null,
        bool $pretty = false): string
    {
        $document = match ($format) {
            'openapi' => $this->openApi($apiVersion),
            'squehub' => $this->application($apiVersion),
            default => throw new ContractException('Contract export format is invalid.'),
        };
        if ($format === 'squehub') {
            // JSON distinguishes a registry map from a list even when empty.
            foreach (['schemas', 'tags'] as $map) {
                if ($document[$map] === []) $document[$map] = new \stdClass();
            }
        }
        try {
            return json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE | ($pretty ? JSON_PRETTY_PRINT : 0)) . "\n";
        } catch (JsonException $exception) {
            throw new ContractException('Contract output could not be encoded.', 0, $exception);
        }
    }

    /** @return array{title:string,version:string,description:?string,servers:list<string>} */
    private function metadata(): array
    {
        $title = $this->info['title'] ?? $this->config->get('contract.title', 'SqueHub API');
        $version = $this->info['version'] ?? $this->config->get('contract.version', '1.0.0');
        $description = $this->info !== null ? $this->info['description']
            : $this->config->get('contract.description');
        if (!is_string($title) || !is_string($version)
            || ($description !== null && !is_string($description))) {
            throw new ContractException('Contract application metadata is invalid.');
        }
        self::text($title, 256, true);
        self::text($version, 128, true);
        if ($description !== null) self::text($description, 8192);
        $servers = $this->servers ?? $this->config->get('contract.servers', ['/']);
        if (!is_array($servers) || !array_is_list($servers) || count($servers) > 16) {
            throw new ContractException('Contract servers require a bounded list.');
        }
        foreach ($servers as $server) {
            if (!is_string($server)) throw new ContractException('Contract server URL is invalid.');
            self::serverUrl($server);
        }
        $servers = array_values(array_unique($servers));
        sort($servers, SORT_STRING);
        return ['title' => $title, 'version' => $version,
            'description' => $description, 'servers' => $servers];
    }

    private function versionStrategy(): string
    {
        $strategy = $this->config->get('api.versioning.strategy', 'uri');
        if (!in_array($strategy, ['uri', 'header'], true)) {
            throw new ContractException('Configured API version strategy is invalid.');
        }
        return $strategy;
    }

    private function addVersionHeader(array &$definition, string $version): void
    {
        $name = $this->config->get('api.versioning.header', 'X-API-Version');
        if (!is_string($name) || strlen($name) > 64
            || preg_match("/\A[!#$%&'*+.^_`|~0-9A-Za-z-]+\z/D", $name) !== 1) {
            throw new ContractException('Configured API version header is invalid.');
        }
        foreach ($definition['parameters'] as $parameter) {
            if ($parameter['in'] === 'header' && strcasecmp($parameter['name'], $name) === 0) {
                throw new ContractException('API version header conflicts with a declared parameter.');
            }
        }
        $definition['parameters'][] = ['name' => $name, 'in' => 'header',
            'required' => true, 'schema' => ['type' => 'string', 'const' => $version],
            'description' => 'Required API version'];
    }

    private function sessionCookieName(): string
    {
        $name = $this->config->get('session.name', 'squehub_session');
        if (!is_string($name) || strlen($name) > 128
            || preg_match("/\A[!#$%&'*+.^_`|~0-9A-Za-z-]+\z/D", $name) !== 1) {
            throw new ContractException('Configured session cookie name is invalid.');
        }
        return $name;
    }

    /** @param list<array<string,mixed>> $parameters */
    private function validatePathParameters(string $path, array $parameters): void
    {
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $path, $matches);
        $expected = $matches[1];
        sort($expected, SORT_STRING);
        $declared = [];
        foreach ($parameters as $parameter) {
            if ($parameter['in'] === 'path') {
                if ($parameter['required'] !== true) {
                    throw new ContractException('Path parameters must be required.');
                }
                $declared[] = $parameter['name'];
            }
        }
        sort($declared, SORT_STRING);
        if ($expected !== $declared) {
            throw new ContractException('Route placeholders and contract path parameters differ.');
        }
    }

    private function sortOperationFields(array &$operation): void
    {
        sort($operation['tags'], SORT_STRING);
        $locations = ['path' => 0, 'query' => 1, 'header' => 2, 'cookie' => 3];
        usort($operation['parameters'], static function (array $left, array $right) use ($locations): int {
            return [$locations[$left['in']], strtolower($left['name'])]
                <=> [$locations[$right['in']], strtolower($right['name'])];
        });
        uksort($operation['responses'], static function (string|int $left, string|int $right): int {
            if ($left === 'default') return 1;
            if ($right === 'default') return -1;
            return (int) $left <=> (int) $right;
        });
    }

    /** Built-ins mirror existing response shapes and are inserted only when used. */
    private function addRequiredBuiltins(array &$schemas, array $operations,
        array $webhooks): void
    {
        // JSON's default slash escaping would conceal local $ref paths from
        // this bounded built-in lookup and leave valid declarations unresolved.
        $serialized = json_encode([$operations, $schemas, $webhooks],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $apiError = Schema::object([
            'error' => Schema::object([
                'code' => Schema::string(), 'message' => Schema::string(),
                'details' => Schema::true(),
            ])->required(['code', 'message']),
            'request_id' => Schema::string(),
        ])->required(['error', 'request_id']);
        $validation = Schema::object([
            'error' => Schema::object([
                'code' => Schema::string(), 'message' => Schema::string(),
                'details' => Schema::object()->additionalProperties(Schema::array(Schema::string())),
            ])->required(['code', 'message', 'details']),
            'request_id' => Schema::string(),
        ])->required(['error', 'request_id']);
        $page = Schema::object([
            'page' => Schema::integer(), 'per_page' => Schema::integer(),
            'total' => Schema::integer(), 'pages' => Schema::integer(),
            'from' => Schema::integer()->nullable(), 'to' => Schema::integer()->nullable(),
            'has_next' => Schema::boolean(), 'has_previous' => Schema::boolean(),
        ])->required(['page', 'per_page', 'total', 'pages', 'from', 'to',
            'has_next', 'has_previous']);
        foreach (['SqueHubApiError' => $apiError,
            'SqueHubValidationError' => $validation,
            'SqueHubPaginationMeta' => $page] as $name => $schema) {
            if (str_contains($serialized, '#/components/schemas/' . $name)) {
                if (isset($schemas[$name])) {
                    throw new ContractException('A framework contract schema name is already registered.');
                }
                $schemas[$name] = $schema->toArray();
            }
        }
    }

    /** @param array<string,mixed> $artifact @param array<string,mixed> $schemas */
    private function validateReferences(array $artifact, array $schemas): void
    {
        $walk = static function (mixed $value) use (&$walk, $schemas): void {
            if (!is_array($value)) return;
            foreach ($value as $key => $nested) {
                if ($key === '$ref') {
                    if (!is_string($nested)
                        || preg_match('~\A#/components/schemas/([A-Za-z_][A-Za-z0-9._-]{0,127})\z~D', $nested, $matches) !== 1
                        || !array_key_exists($matches[1], $schemas)) {
                        throw new ContractException('Contract schema reference is unresolved.');
                    }
                } else {
                    $walk($nested);
                }
            }
        };
        $walk($artifact);
    }

    /** Sort named maps but preserve lists, such as oneOf and examples. */
    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $nested) $value[$key] = self::canonicalize($nested);
        return $value;
    }

    private static function componentName(string $name): void
    {
        if (strlen($name) > 128 || preg_match('/\A[A-Za-z_][A-Za-z0-9._-]*\z/D', $name) !== 1) {
            throw new ContractException('Contract schema name is invalid.');
        }
    }

    private static function text(string $value, int $max, bool $nonempty = false): void
    {
        if (($nonempty && trim($value) === '') || strlen($value) > $max
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new ContractException('Contract public metadata is invalid.');
        }
    }

    private static function serverUrl(string $url): void
    {
        if ($url === '/') return;
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F]/', $url)) {
            throw new ContractException('Contract server URL is invalid.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || isset($parts['query'])) {
            throw new ContractException('Contract server URL must be an explicit HTTPS origin or /.');
        }
    }
}
