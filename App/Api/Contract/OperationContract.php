<?php

declare(strict_types=1);

namespace App\Api\Contract;

/**
 * Explicit network behavior for one SqueHub route definition. Fluent methods
 * return copies so attaching a declaration cannot inherit later mutations.
 * Router execution and middleware order are independent of this description.
 */
final class OperationContract
{
    private ?string $summary = null;
    private ?string $description = null;
    /** @var list<string> */
    private array $tags = [];
    private bool $deprecated = false;
    private bool $visible = true;
    /** @var list<array<string,mixed>> */
    private array $parameters = [];
    /** @var array<string,mixed>|null */
    private ?array $requestBody = null;
    /** @var array<string,array<string,mixed>> */
    private array $responses = [];
    /** @var array<string,true> Standard errors acquire security headers at export time. */
    private array $standardErrors = [];
    /** @var array{type:string,token_abilities:list<string>,authorization_abilities:list<string>} */
    private array $security = ['type' => 'none', 'token_abilities' => [],
        'authorization_abilities' => []];

    public function __construct(private ?string $operationId = null)
    {
        if ($operationId !== null) self::identifier($operationId, 'operation ID');
    }

    public function operationId(): ?string { return $this->operationId; }
    public function isVisible(): bool { return $this->visible; }

    public function summary(string $text): self
    {
        $copy = clone $this;
        $copy->summary = self::text($text, 'summary', 512);
        return $copy;
    }

    public function description(string $text): self
    {
        $copy = clone $this;
        $copy->description = self::text($text, 'description', 8192);
        return $copy;
    }

    public function tags(string ...$names): self
    {
        $copy = clone $this;
        foreach ($names as $name) {
            self::identifier($name, 'tag');
            if (!in_array($name, $copy->tags, true)) $copy->tags[] = $name;
        }
        return $copy;
    }

    public function deprecated(): self
    {
        $copy = clone $this;
        $copy->deprecated = true;
        return $copy;
    }

    /** Keep an attached internal declaration out of public artifacts. */
    public function internal(): self
    {
        $copy = clone $this;
        $copy->visible = false;
        return $copy;
    }

    public function path(string $name, Schema $schema, ?string $description = null): self
    {
        return $this->parameter('path', $name, $schema, true, $description);
    }

    public function query(string $name, Schema $schema, bool $required = false,
        ?string $description = null): self
    {
        return $this->parameter('query', $name, $schema, $required, $description);
    }

    public function header(string $name, Schema $schema, bool $required = false,
        ?string $description = null): self
    {
        return $this->parameter('header', $name, $schema, $required, $description);
    }

    public function cookie(string $name, Schema $schema, bool $required = false,
        ?string $description = null): self
    {
        return $this->parameter('cookie', $name, $schema, $required, $description);
    }

    /**
     * Parameter identity follows HTTP header case-insensitivity. The normal
     * query/path styles are kept simple; this does not advertise deepObject.
     */
    private function parameter(string $location, string $name, Schema $schema,
        bool $required, ?string $description): self
    {
        if ($location === 'header') {
            self::headerName($name);
        } else {
            self::parameterName($name);
        }
        if ($description !== null) self::text($description, 'parameter description', 2048);
        $identity = $location . ':' . ($location === 'header' ? strtolower($name) : $name);
        foreach ($this->parameters as $existing) {
            if ($existing['identity'] === $identity) {
                throw new ContractException('A contract parameter is declared twice.');
            }
        }
        $copy = clone $this;
        $copy->parameters[] = ['identity' => $identity, 'name' => $name,
            'in' => $location, 'required' => $required, 'schema' => $schema,
            'description' => $description];
        return $copy;
    }

    public function body(Schema $schema, string $contentType = 'application/json',
        bool $required = true, ?string $description = null): self
    {
        self::contentType($contentType);
        if ($description !== null) self::text($description, 'request description', 2048);
        if ($this->requestBody !== null) {
            throw new ContractException('A contract request body is already declared.');
        }
        $copy = clone $this;
        $copy->requestBody = ['required' => $required, 'content_type' => $contentType,
            'schema' => $schema, 'description' => $description];
        return $copy;
    }

    /**
     * A response describes an actual status, content type and representation.
     * Header schemas are declarations, never captured response header values.
     *
     * @param array<string,Schema> $headers
     */
    public function response(int|string $status, ?Schema $schema = null,
        string $description = '', string $contentType = 'application/json',
        array $headers = []): self
    {
        $key = self::status($status);
        if (isset($this->responses[$key])) {
            throw new ContractException('A contract response status is declared twice.');
        }
        self::text($description, 'response description', 2048);
        if ($schema !== null) self::contentType($contentType);
        $normalizedHeaders = [];
        foreach ($headers as $name => $headerSchema) {
            if (!is_string($name) || !$headerSchema instanceof Schema) {
                throw new ContractException('Response headers require named Schema declarations.');
            }
            self::headerName($name);
            $folded = strtolower($name);
            if (isset($normalizedHeaders[$folded])) {
                throw new ContractException('A response header is declared twice.');
            }
            $normalizedHeaders[$folded] = ['name' => $name, 'description' => '',
                'schema' => $headerSchema];
        }
        $copy = clone $this;
        $copy->responses[$key] = ['description' => $description,
            'content_type' => $schema === null ? null : $contentType,
            'schema' => $schema, 'headers' => $normalizedHeaders];
        return $copy;
    }

    /** Reuse the real Phase 12B envelope; this never asserts every error exists. */
    public function error(int $status): self
    {
        $descriptions = [400 => 'Bad request', 401 => 'Authentication required',
            403 => 'Forbidden', 404 => 'Not found', 405 => 'Method not allowed',
            409 => 'Conflict', 422 => 'Validation failed', 429 => 'Rate limited',
            500 => 'Internal error', 503 => 'Service unavailable'];
        if (!isset($descriptions[$status])) {
            throw new ContractException('This standard API error status is not supported.');
        }
        $headers = match ($status) {
            405 => ['Allow' => Schema::string()],
            429 => ['Retry-After' => Schema::integer(),
                'X-RateLimit-Limit' => Schema::integer(),
                'X-RateLimit-Remaining' => Schema::integer(),
                'X-RateLimit-Reset' => Schema::integer()],
            default => [],
        };
        $copy = $this->response($status,
            Schema::ref($status === 422 ? 'SqueHubValidationError' : 'SqueHubApiError'),
            $descriptions[$status], headers: $headers);
        $copy->standardErrors[(string) $status] = true;
        return $copy;
    }

    /** Match ResourceCollection(Page)'s data/meta envelope exactly. */
    public function paginatedResponse(int $status, Schema $item,
        string $description = 'Paginated results'): self
    {
        return $this->response($status, Schema::object([
            'data' => Schema::array($item),
            'meta' => Schema::ref('SqueHubPaginationMeta'),
        ])->required(['data', 'meta']), $description);
    }

    /** SqueHub personal access tokens are HTTP Bearer credentials, not OAuth. */
    public function pat(array $tokenAbilities = []): self
    {
        $copy = clone $this;
        $copy->security['type'] = 'pat';
        $copy->security['token_abilities'] = self::abilities($tokenAbilities);
        return $copy;
    }

    public function session(): self
    {
        $copy = clone $this;
        $copy->security['type'] = 'session';
        $copy->security['token_abilities'] = [];
        return $copy;
    }

    /** Logical authorization abilities are documentation, not middleware. */
    public function authorizationAbilities(array $abilities): self
    {
        $copy = clone $this;
        $copy->security['authorization_abilities'] = self::abilities($abilities);
        return $copy;
    }

    /** Copy only public declarations; no controller or middleware identity leaks. */
    public function toArray(): array
    {
        $parameters = [];
        foreach ($this->parameters as $parameter) {
            unset($parameter['identity']);
            $parameter['schema'] = $parameter['schema']->toArray();
            $parameters[] = $parameter;
        }
        $body = $this->requestBody;
        if ($body !== null) $body['schema'] = $body['schema']->toArray();
        $responses = [];
        foreach ($this->responses as $status => $response) {
            $response['schema'] = $response['schema']?->toArray();
            $headers = [];
            foreach ($response['headers'] as $header) {
                $headers[$header['name']] = ['description' => $header['description'],
                    'schema' => $header['schema']->toArray()];
            }
            // A standard 401 advertises a Bearer challenge only when the
            // final declared security policy is PAT, regardless of call order.
            if ($status === 401 && isset($this->standardErrors['401'])
                && $this->security['type'] === 'pat') {
                $headers['WWW-Authenticate'] = ['description' => '',
                    'schema' => Schema::string()->toArray()];
            }
            $response['headers'] = $headers;
            $responses[$status] = $response;
        }
        return ['summary' => $this->summary, 'description' => $this->description,
            'tags' => $this->tags, 'deprecated' => $this->deprecated,
            'parameters' => $parameters, 'request_body' => $body,
            'responses' => $responses, 'security' => $this->security];
    }

    /** @internal Route cache persists public declarations, never PHP objects. */
    public function toCacheArray(): array
    {
        return ['operation_id' => $this->operationId, 'visible' => $this->visible,
            'declaration' => $this->toArray()];
    }

    /**
     * @internal Rebuild through the same validating builder API used by route
     * files. No native object serialization or arbitrary method is invoked.
     */
    public static function fromCacheArray(array $cache): self
    {
        if (array_keys($cache) !== ['operation_id', 'visible', 'declaration']
            || ($cache['operation_id'] !== null && !is_string($cache['operation_id']))
            || !is_bool($cache['visible']) || !is_array($cache['declaration'])) {
            throw new ContractException('Cached operation declaration is invalid.');
        }
        $value = $cache['declaration'];
        if (array_keys($value) !== ['summary', 'description', 'tags', 'deprecated',
            'parameters', 'request_body', 'responses', 'security']
            || !is_array($value['tags']) || !array_is_list($value['tags'])
            || !is_bool($value['deprecated']) || !is_array($value['parameters'])
            || !array_is_list($value['parameters']) || !is_array($value['responses'])
            || !is_array($value['security'])) {
            throw new ContractException('Cached operation declaration has an unsupported shape.');
        }
        $contract = new self($cache['operation_id']);
        if ($value['summary'] !== null) {
            if (!is_string($value['summary'])) throw new ContractException('Cached summary is invalid.');
            $contract = $contract->summary($value['summary']);
        }
        if ($value['description'] !== null) {
            if (!is_string($value['description'])) throw new ContractException('Cached description is invalid.');
            $contract = $contract->description($value['description']);
        }
        foreach ($value['tags'] as $tag) {
            if (!is_string($tag)) throw new ContractException('Cached tag is invalid.');
            $contract = $contract->tags($tag);
        }
        if ($value['deprecated']) $contract = $contract->deprecated();
        if (!$cache['visible']) $contract = $contract->internal();
        foreach ($value['parameters'] as $parameter) {
            if (!is_array($parameter) || array_keys($parameter) !== ['name', 'in',
                'required', 'schema', 'description'] || !is_string($parameter['name'])
                || !is_string($parameter['in']) || !is_bool($parameter['required'])
                || (!is_array($parameter['schema']) && !is_bool($parameter['schema']))
                || ($parameter['description'] !== null && !is_string($parameter['description']))) {
                throw new ContractException('Cached parameter is invalid.');
            }
            $schema = Schema::fromCacheArray($parameter['schema']);
            $name = $parameter['name'];
            $required = $parameter['required'];
            $description = $parameter['description'];
            $contract = match ($parameter['in']) {
                'path' => $contract->path($name, $schema, $description),
                'query' => $contract->query($name, $schema, $required, $description),
                'header' => $contract->header($name, $schema, $required, $description),
                'cookie' => $contract->cookie($name, $schema, $required, $description),
                default => throw new ContractException('Cached parameter location is invalid.'),
            };
            if ($parameter['in'] === 'path' && !$required) {
                throw new ContractException('Cached path parameter must be required.');
            }
        }
        if ($value['request_body'] !== null) {
            $body = $value['request_body'];
            if (!is_array($body) || array_keys($body) !== ['required', 'content_type',
                'schema', 'description'] || !is_bool($body['required'])
                || !is_string($body['content_type'])
                || (!is_array($body['schema']) && !is_bool($body['schema']))
                || ($body['description'] !== null && !is_string($body['description']))) {
                throw new ContractException('Cached request body is invalid.');
            }
            $contract = $contract->body(Schema::fromCacheArray($body['schema']),
                $body['content_type'], $body['required'], $body['description']);
        }
        foreach ($value['responses'] as $status => $response) {
            if (!is_array($response) || array_keys($response) !== ['description',
                'content_type', 'schema', 'headers'] || !is_string($response['description'])
                || ($response['content_type'] !== null && !is_string($response['content_type']))
                || ($response['schema'] !== null && !is_array($response['schema'])
                    && !is_bool($response['schema'])) || !is_array($response['headers'])) {
                throw new ContractException('Cached response is invalid.');
            }
            $headers = [];
            foreach ($response['headers'] as $name => $header) {
                if (!is_string($name) || !is_array($header)
                    || array_keys($header) !== ['description', 'schema']
                    || $header['description'] !== ''
                    || (!is_array($header['schema']) && !is_bool($header['schema']))) {
                    throw new ContractException('Cached response header is invalid.');
                }
                $headers[$name] = Schema::fromCacheArray($header['schema']);
            }
            $code = $status === 'default' ? 'default' : (is_int($status) ? $status : null);
            if ($code === null) throw new ContractException('Cached response status is invalid.');
            $schema = $response['schema'] === null ? null
                : Schema::fromCacheArray($response['schema']);
            $contract = $contract->response($code, $schema, $response['description'],
                $response['content_type'] ?? 'application/json', $headers);
        }
        $security = $value['security'];
        if (array_keys($security) !== ['type', 'token_abilities', 'authorization_abilities']
            || !is_string($security['type']) || !is_array($security['token_abilities'])
            || !is_array($security['authorization_abilities'])) {
            throw new ContractException('Cached security declaration is invalid.');
        }
        $contract = match ($security['type']) {
            'none' => $contract,
            'pat' => $contract->pat($security['token_abilities']),
            'session' => $contract->session(),
            default => throw new ContractException('Cached security type is invalid.'),
        };
        if ($security['type'] === 'none' && $security['token_abilities'] !== []) {
            throw new ContractException('Cached security abilities are invalid.');
        }
        $contract = $contract->authorizationAbilities($security['authorization_abilities']);
        return $contract;
    }

    private static function status(int|string $status): string
    {
        if ($status === 'default') return 'default';
        if (!is_int($status) || $status < 100 || $status > 599) {
            throw new ContractException('Contract response status must be 100-599 or default.');
        }
        return (string) $status;
    }

    public static function identifier(string $value, string $label): void
    {
        if (strlen($value) > 128 || preg_match('/\A[A-Za-z][A-Za-z0-9._-]*\z/D', $value) !== 1) {
            throw new ContractException('Contract ' . $label . ' requires a bounded identifier.');
        }
    }

    private static function parameterName(string $name): void
    {
        if (strlen($name) > 128 || preg_match('/\A[A-Za-z_][A-Za-z0-9_.-]*\z/D', $name) !== 1) {
            throw new ContractException('Contract parameter name is invalid.');
        }
    }

    private static function headerName(string $name): void
    {
        if (strlen($name) > 128 || preg_match("/\A[!#$%&'*+.^_`|~0-9A-Za-z-]+\z/D", $name) !== 1) {
            throw new ContractException('Contract header name is invalid.');
        }
    }

    private static function contentType(string $value): void
    {
        if (strlen($value) > 128 || preg_match('/\A[a-z0-9!#$&^_.+-]+\/[a-z0-9!#$&^_.+-]+\z/D', $value) !== 1) {
            throw new ContractException('Contract content type is invalid.');
        }
    }

    private static function text(string $value, string $label, int $max): string
    {
        if (strlen($value) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new ContractException('Contract ' . $label . ' is invalid.');
        }
        return $value;
    }

    /** @return list<string> */
    private static function abilities(array $abilities): array
    {
        if (!array_is_list($abilities) || count($abilities) > 64) {
            throw new ContractException('Contract abilities require a bounded list.');
        }
        foreach ($abilities as $ability) {
            if (!is_string($ability)) {
                throw new ContractException('Contract ability must be an identifier.');
            }
            self::identifier($ability, 'ability');
        }
        return array_values(array_unique($abilities));
    }
}
