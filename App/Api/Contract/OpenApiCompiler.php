<?php

declare(strict_types=1);

namespace App\Api\Contract;


/**
 * Exports a normalized SqueHub application contract as OpenAPI 3.2.1.
 * This bridge does not inspect controllers, contact peers, or mutate routes.
 */
final class OpenApiCompiler
{
    public const VERSION = '3.2.1';
    public const DIALECT = 'https://spec.openapis.org/oas/3.2/dialect/2026-02-26';

    /** @param array<string, mixed> $contract @return array<string, mixed> */
    public function compile(array $contract): array
    {
        if (($contract['squehub_contract'] ?? null) !== '1') {
            throw new ContractException('Unsupported SqueHub application contract version.');
        }
        $application = $contract['application'] ?? null;
        if (!is_array($application) || !self::nonempty($application['title'] ?? null)
            || !self::nonempty($application['version'] ?? null)) {
            throw new ContractException('Application contract requires a title and API version.');
        }

        $info = ['title' => $application['title'], 'version' => $application['version']];
        if (self::nonempty($application['description'] ?? null)) {
            $info['description'] = $application['description'];
        }
        $document = [
            'openapi' => self::VERSION,
            'jsonSchemaDialect' => self::DIALECT,
            'info' => $info,
        ];
        $servers = $application['servers'] ?? [];
        if (!is_array($servers) || !array_is_list($servers)) {
            throw new ContractException('Application servers must be a list.');
        }
        if ($servers !== []) {
            $urls = [];
            foreach ($servers as $url) {
                if (!self::safeServerUrl($url)) {
                    throw new ContractException('Application server URL is invalid.');
                }
                $urls[$url] = true;
            }
            $urls = array_keys($urls);
            sort($urls, SORT_STRING);
            $document['servers'] = array_map(static fn (string $url): array => ['url' => $url], $urls);
        }

        $schemas = $contract['schemas'] ?? [];
        if (!is_array($schemas)) {
            throw new ContractException('Contract schemas must be a named map.');
        }
        $components = [];
        if ($schemas !== []) {
            ksort($schemas, SORT_STRING);
            foreach ($schemas as $name => $schema) {
                if (!self::componentName($name) || !self::schemaValue($schema)) {
                    throw new ContractException('Contract schema component is invalid.');
                }
                $components['schemas'][$name] = self::sortMap($schema);
            }
        }

        $operations = $contract['operations'] ?? [];
        if (!is_array($operations) || !array_is_list($operations)) {
            throw new ContractException('Contract operations must be a list.');
        }
        $paths = [];
        $operationIds = [];
        $tagNames = [];
        $declaredTags = $contract['tags'] ?? [];
        if (!is_array($declaredTags) || ($declaredTags !== [] && array_is_list($declaredTags))) {
            throw new ContractException('Contract tags must be a named map.');
        }
        foreach ($declaredTags as $name => $description) {
            if (!is_string($name) || strlen($name) > 128
                || preg_match('/\A[A-Za-z][A-Za-z0-9._-]*\z/D', $name) !== 1
                || !self::nonempty($description) || strlen($description) > 8192) {
                throw new ContractException('Contract tag declaration is invalid.');
            }
            $tagNames[$name] = $description;
        }
        $securitySchemes = [];
        $sessionCookie = null;
        foreach ($operations as $operation) {
            if (!is_array($operation)) {
                throw new ContractException('Contract operation is invalid.');
            }
            $id = $operation['operation_id'] ?? null;
            $method = $operation['method'] ?? null;
            $path = $operation['path'] ?? null;
            if (!self::nonempty($id) || !is_string($method)
                || !in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)
                || !is_string($path) || !str_starts_with($path, '/')) {
                throw new ContractException('Contract operation identity, method, or path is invalid.');
            }
            if (isset($operationIds[$id]) || isset($paths[$path][strtolower($method)])) {
                throw new ContractException('Duplicate public contract operation.');
            }
            $operationIds[$id] = true;

            $export = ['operationId' => $id];
            foreach (['summary', 'description'] as $field) {
                if (self::nonempty($operation[$field] ?? null)) {
                    $export[$field] = $operation[$field];
                }
            }
            $tags = $operation['tags'] ?? [];
            if (!is_array($tags) || !array_is_list($tags)) {
                throw new ContractException('Contract operation tags must be a list.');
            }
            if ($tags !== []) {
                foreach ($tags as $tag) {
                    if (!self::nonempty($tag)) {
                        throw new ContractException('Contract tag name is invalid.');
                    }
                    $tagNames[$tag] ??= null;
                }
                $tags = array_keys(array_fill_keys($tags, true));
                sort($tags, SORT_STRING);
                $export['tags'] = $tags;
            }
            if (($operation['deprecated'] ?? false) === true) {
                $export['deprecated'] = true;
            }
            if (self::nonempty($operation['api_version'] ?? null)) {
                $export['x-squehub-api-version'] = $operation['api_version'];
            }

            $parameters = $operation['parameters'] ?? [];
            if (!is_array($parameters) || !array_is_list($parameters)) {
                throw new ContractException('Contract parameters must be a list.');
            }
            $exportedParameters = [];
            $parameterNames = [];
            foreach ($parameters as $parameter) {
                $entry = self::parameter($parameter);
                $key = $entry['in'] . ':' . ($entry['in'] === 'header'
                    ? strtolower($entry['name']) : $entry['name']);
                if (isset($parameterNames[$key])) {
                    throw new ContractException('Duplicate contract parameter.');
                }
                $parameterNames[$key] = true;
                $exportedParameters[] = $entry;
            }
            preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $path, $placeholders);
            $routeParameters = $placeholders[1];
            $declaredPathParameters = array_values(array_map(
                static fn (array $parameter): string => $parameter['name'],
                array_filter($exportedParameters,
                    static fn (array $parameter): bool => $parameter['in'] === 'path')
            ));
            sort($routeParameters, SORT_STRING);
            sort($declaredPathParameters, SORT_STRING);
            if ($routeParameters !== $declaredPathParameters) {
                throw new ContractException('Contract path parameters must match route placeholders.');
            }
            usort($exportedParameters, static fn (array $left, array $right): int =>
                [$left['in'], strtolower($left['name']), $left['name']]
                <=> [$right['in'], strtolower($right['name']), $right['name']]);
            if ($exportedParameters !== []) {
                $export['parameters'] = $exportedParameters;
            }

            $body = $operation['request_body'] ?? null;
            if ($body !== null) {
                if (!is_array($body) || !self::nonempty($body['content_type'] ?? null)
                    || !self::schemaValue($body['schema'] ?? null)) {
                    throw new ContractException('Contract request body is invalid.');
                }
                $requestBody = [
                    'required' => $body['required'] ?? false,
                    'content' => [$body['content_type'] => ['schema' => self::sortMap($body['schema'])]],
                ];
                if (self::nonempty($body['description'] ?? null)) {
                    $requestBody['description'] = $body['description'];
                }
                $export['requestBody'] = $requestBody;
            }

            $responses = $operation['responses'] ?? null;
            if (!is_array($responses) || $responses === []) {
                throw new ContractException('Contract operation requires at least one response.');
            }
            uksort($responses, static fn (string|int $left, string|int $right): int =>
                strcmp((string) $left, (string) $right));
            foreach ($responses as $status => $response) {
                $status = (string) $status;
                if (!preg_match('/\A(?:[1-5][0-9]{2}|[1-5]XX|default)\z/D', $status)
                    || !is_array($response) || !is_string($response['description'] ?? null)) {
                    throw new ContractException('Contract response status or description is invalid.');
                }
                $entry = ['description' => $response['description']];
                $contentType = $response['content_type'] ?? null;
                $schema = $response['schema'] ?? null;
                if ($contentType !== null || $schema !== null) {
                    if (!self::nonempty($contentType) || !self::schemaValue($schema)) {
                        throw new ContractException('Contract response content is invalid.');
                    }
                    $entry['content'] = [$contentType => ['schema' => self::sortMap($schema)]];
                }
                $headers = $response['headers'] ?? [];
                if (!is_array($headers)) {
                    throw new ContractException('Contract response headers are invalid.');
                }
                $seenHeaders = [];
                foreach ($headers as $name => $header) {
                    if (!self::headerName($name) || !is_array($header)
                        || !is_string($header['description'] ?? null)
                        || !self::schemaValue($header['schema'] ?? null)) {
                        throw new ContractException('Contract response header is invalid.');
                    }
                    $lower = strtolower($name);
                    if (isset($seenHeaders[$lower])) {
                        throw new ContractException('Duplicate contract response header.');
                    }
                    $seenHeaders[$lower] = true;
                    if ($lower !== 'content-type') {
                        $entry['headers'][$name] = [
                            'description' => $header['description'],
                            'schema' => self::sortMap($header['schema']),
                        ];
                    }
                }
                if (isset($entry['headers'])) {
                    uksort($entry['headers'], 'strcasecmp');
                }
                $export['responses'][$status] = $entry;
            }

            $security = $operation['security'] ?? ['type' => 'none'];
            if (!is_array($security)) {
                throw new ContractException('Contract security is invalid.');
            }
            $type = $security['type'] ?? 'none';
            if ($type === 'pat') {
                $securitySchemes['SqueHubPat'] = [
                    'type' => 'http', 'scheme' => 'bearer',
                    'description' => 'SqueHub personal access token.',
                ];
                $export['security'] = [['SqueHubPat' => []]];
            } elseif ($type === 'session') {
                $cookie = $security['cookie_name'] ?? null;
                if (!self::headerName($cookie)) {
                    throw new ContractException('Session security requires a valid cookie name.');
                }
                if ($sessionCookie !== null && $sessionCookie !== $cookie) {
                    throw new ContractException('Conflicting session cookie names in one API contract.');
                }
                $sessionCookie = $cookie;
                $securitySchemes['SqueHubSession'] = [
                    'type' => 'apiKey', 'in' => 'cookie', 'name' => $cookie,
                ];
                $export['security'] = [['SqueHubSession' => []]];
            } elseif ($type !== 'none') {
                throw new ContractException('Contract security type is not supported.');
            }
            if ($type !== 'pat' && ($security['token_abilities'] ?? []) !== []) {
                throw new ContractException('Token abilities require PAT security.');
            }
            foreach (['token_abilities' => 'x-squehub-token-abilities',
                'authorization_abilities' => 'x-squehub-authorization-abilities'] as $field => $extension) {
                $abilities = $security[$field] ?? [];
                if (!is_array($abilities) || !array_is_list($abilities)) {
                    throw new ContractException('Contract security abilities must be lists.');
                }
                if ($abilities !== []) {
                    foreach ($abilities as $ability) {
                        if (!self::nonempty($ability)) {
                            throw new ContractException('Contract ability name is invalid.');
                        }
                    }
                    $abilities = array_keys(array_fill_keys($abilities, true));
                    sort($abilities, SORT_STRING);
                    $export[$extension] = $abilities;
                }
            }
            $paths[$path][strtolower($method)] = $export;
        }

        if ($tagNames !== []) {
            ksort($tagNames, SORT_STRING);
            foreach ($tagNames as $name => $description) {
                $tag = ['name' => $name];
                if ($description !== null) $tag['description'] = $description;
                $document['tags'][] = $tag;
            }
        }
        if ($paths !== []) {
            ksort($paths, SORT_STRING);
            foreach ($paths as &$pathItem) {
                uksort($pathItem, static fn (string $left, string $right): int =>
                    array_search($left, self::METHOD_ORDER, true)
                    <=> array_search($right, self::METHOD_ORDER, true));
            }
            unset($pathItem);
        }
        // JSON has distinct object/list types; an application with no public
        // routes still has an empty Paths Object, not an empty operations list.
        $document['paths'] = $paths === [] ? (object) [] : $paths;

        $webhooks = $contract['webhooks'] ?? [];
        if (!is_array($webhooks) || !array_is_list($webhooks)) {
            throw new ContractException('Contract webhooks must be a list.');
        }
        $hookPaths = [];
        foreach ($webhooks as $webhook) {
            if (!is_array($webhook) || !self::nonempty($webhook['type'] ?? null)
                || !self::schemaValue($webhook['data_schema'] ?? null)) {
                throw new ContractException('Contract webhook is invalid.');
            }
            $type = $webhook['type'];
            if (strlen($type) > 128
                || preg_match('/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/D', $type) !== 1) {
                throw new ContractException('Outgoing webhook event type is invalid.');
            }
            if (isset($hookPaths[$type])) {
                throw new ContractException('Duplicate outgoing webhook contract.');
            }
            if (isset($operationIds['webhook.' . $type])) {
                throw new ContractException('Duplicate public contract operation ID.');
            }
            $hookPaths[$type] = ['post' => self::webhookOperation($webhook)];
        }
        if ($hookPaths !== []) {
            ksort($hookPaths, SORT_STRING);
            $document['webhooks'] = $hookPaths;
        }

        if ($securitySchemes !== []) {
            ksort($securitySchemes, SORT_STRING);
            $components['securitySchemes'] = $securitySchemes;
        }
        if ($components !== []) {
            $document['components'] = $components;
        }
        return $document;
    }

    private const METHOD_ORDER = ['get', 'head', 'post', 'put', 'patch', 'delete', 'options'];

    /** @param mixed $parameter @return array<string, mixed> */
    private static function parameter(mixed $parameter): array
    {
        if (!is_array($parameter) || !self::nonempty($parameter['name'] ?? null)
            || !in_array($parameter['in'] ?? null, ['path', 'query', 'header', 'cookie'], true)
            || !is_bool($parameter['required'] ?? null)
            || !self::schemaValue($parameter['schema'] ?? null)) {
            throw new ContractException('Contract parameter is invalid.');
        }
        if ($parameter['in'] === 'path' && $parameter['required'] !== true) {
            throw new ContractException('OpenAPI path parameters are required.');
        }
        if ($parameter['in'] === 'header' && !self::headerName($parameter['name'])) {
            throw new ContractException('Contract header parameter name is invalid.');
        }
        if ($parameter['in'] === 'header' && in_array(strtolower($parameter['name']),
            ['accept', 'content-type', 'authorization'], true)) {
            // OpenAPI ignores these header parameters. Media types and bearer
            // credentials must instead use content and security declarations.
            throw new ContractException('Use media type or security declarations for this header.');
        }
        $entry = [
            'name' => $parameter['name'], 'in' => $parameter['in'],
            'required' => $parameter['required'], 'schema' => self::sortMap($parameter['schema']),
        ];
        if (self::nonempty($parameter['description'] ?? null)) {
            $entry['description'] = $parameter['description'];
        }
        $examples = $parameter['examples'] ?? null;
        if ($examples !== null) {
            if (!is_array($examples)) {
                throw new ContractException('Contract parameter examples are invalid.');
            }
            if ($parameter['in'] === 'header' && in_array(strtolower($parameter['name']),
                ['authorization', 'cookie', 'set-cookie', 'squehub-webhook-signature'], true)) {
                throw new ContractException('Sensitive header examples cannot be exported.');
            }
            ksort($examples, SORT_STRING);
            foreach ($examples as $name => $value) {
                if (!self::componentName($name)) {
                    throw new ContractException('Contract example name is invalid.');
                }
                $entry['examples'][$name] = ['value' => self::sortMap($value)];
            }
        }
        return $entry;
    }

    /** @param array<string, mixed> $webhook @return array<string, mixed> */
    private static function webhookOperation(array $webhook): array
    {
        $type = $webhook['type'];
        $envelope = [
            'type' => 'object',
            'properties' => [
                'version' => ['type' => 'integer', 'const' => 1],
                'id' => ['type' => 'string', 'pattern' => '^evt_[A-Za-z0-9_-]{32}$'],
                'type' => ['type' => 'string', 'const' => $type],
                'created_at' => ['type' => 'string', 'format' => 'date-time'],
                'data' => self::sortMap($webhook['data_schema']),
            ],
            'required' => ['version', 'id', 'type', 'created_at', 'data'],
            'additionalProperties' => false,
        ];
        $headers = [
            'SqueHub-Webhook-Id' => ['type' => 'string', 'pattern' => '^evt_[A-Za-z0-9_-]{32}$'],
            'SqueHub-Webhook-Delivery-Id' => ['type' => 'string', 'pattern' => '^whd_[A-Za-z0-9_-]{32}$'],
            'SqueHub-Webhook-Timestamp' => ['type' => 'string', 'pattern' => '^[0-9]+$'],
            'SqueHub-Webhook-Signature' => ['type' => 'string', 'pattern' => '^v1=[a-f0-9]{64}$'],
        ];
        $parameters = [];
        foreach ($headers as $name => $schema) {
            $parameters[] = ['name' => $name, 'in' => 'header', 'required' => true, 'schema' => $schema];
        }
        $operation = [
            'operationId' => 'webhook.' . $type,
            'parameters' => $parameters,
            'requestBody' => [
                'required' => true,
                'content' => ['application/json' => ['schema' => $envelope]],
            ],
            'responses' => ['2XX' => ['description' => 'Receiver accepted the delivery.']],
            'x-squehub-webhook-signature' => [
                'version' => 'v1', 'algorithm' => 'HMAC-SHA256',
                'signed_bytes' => 'v1\\n<timestamp>\\n<event-id>\\n<delivery-id>\\n<raw-body>',
            ],
        ];
        if (self::nonempty($webhook['description'] ?? null)) {
            $operation['description'] = $webhook['description'];
        }
        return $operation;
    }

    private static function nonempty(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private static function schemaValue(mixed $value): bool
    {
        return is_bool($value) || (is_array($value) && $value !== [] && !array_is_list($value));
    }

    /** Servers are declarations, never URLs captured from a live HTTP request. */
    private static function safeServerUrl(mixed $url): bool
    {
        if ($url === '/') return true;
        if (!self::nonempty($url) || strlen($url) > 2048
            || preg_match('/[\x00-\x20\x7f]/', $url)) return false;
        $parts = parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? null) === 'https'
            && isset($parts['host']) && $parts['host'] !== ''
            && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['query']) && !isset($parts['fragment']);
    }

    private static function componentName(mixed $name): bool
    {
        return is_string($name) && strlen($name) <= 128
            && preg_match('/\A[A-Za-z0-9_.-]+\z/D', $name) === 1;
    }

    private static function headerName(mixed $name): bool
    {
        return is_string($name) && strlen($name) <= 128
            && preg_match("/\A[!#$%&'*+.^_`|~0-9A-Za-z-]+\z/D", $name) === 1;
    }

    /** Sort object/map keys but retain list order, which can carry meaning. */
    private static function sortMap(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = self::sortMap($item);
        return $value;
    }
}
