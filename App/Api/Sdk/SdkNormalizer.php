<?php

declare(strict_types=1);

namespace App\Api\Sdk;

use App\Api\ApiVersionPolicy;
use App\Api\Contract\ContractManager;
use InvalidArgumentException;
use JsonException;

/** Validate the native Application Contract once before any language renders it. */
final class SdkNormalizer
{
    private const SCHEMA_KEYWORDS = [
        '$ref', 'type', 'properties', 'required', 'items', 'additionalProperties',
        'enum', 'const', 'oneOf', 'anyOf', 'allOf', 'description', 'format',
        'examples', 'default', 'deprecated', 'readOnly', 'writeOnly', 'minimum',
        'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'minLength',
        'maxLength', 'pattern', 'minItems', 'maxItems', 'uniqueItems',
    ];
    private const TYPES = ['null', 'boolean', 'integer', 'number', 'string', 'object', 'array'];
    private const RESERVED_SEGMENTS = [
        'abstract', 'and', 'array', 'as', 'async', 'await', 'break', 'call', 'case',
        'catch', 'class', 'clone', 'const', 'constructor', 'continue', 'declare',
        'default', 'delete', 'do', 'echo', 'else', 'elseif', 'enum', 'eval', 'export',
        'extends', 'false', 'final', 'finally', 'fn', 'for', 'foreach', 'from',
        'function', 'global', 'goto', 'if', 'implements', 'import', 'in',
        'instanceof', 'interface', 'isset', 'let', 'match', 'namespace', 'new',
        'null', 'or', 'parent', 'private', 'protected', 'public', 'readonly',
        'require', 'return', 'self', 'static', 'super', 'switch', 'this', 'throw',
        'then', 'toString', 'trait', 'true', 'try', 'typeof', 'unset', 'use', 'var', 'void',
        'while', 'with', 'yield',
    ];
    private const RESERVED_SYMBOLS = [
        'ApiException', 'Client', 'Page', 'ProtocolException', 'Record', 'SqueHubClient',
        'SqueHubApiError', 'SqueHubPage', 'SqueHubProtocolError',
        'TransportException',
    ];
    private const RESERVED_JS_OBJECT_SEGMENTS = [
        'then', 'tojson', 'tostring', 'tolocalestring', 'valueof', 'constructor',
        'prototype', '__proto__', 'hasownproperty', 'isprototypeof',
        'propertyisenumerable', '__definegetter__', '__definesetter__',
        '__lookupgetter__', '__lookupsetter__',
    ];

    public static function normalize(ContractManager $manager, ?string $apiVersion = null): SdkModel
    {
        return self::fromArtifact($manager->application($apiVersion), $apiVersion);
    }

    /** Useful for testing detached contract snapshots without booting an Application. */
    public static function fromArtifact(array $artifact, ?string $apiVersion = null): SdkModel
    {
        if ($apiVersion !== null) {
            try {
                $apiVersion = ApiVersionPolicy::normalizeIdentifier($apiVersion);
            } catch (InvalidArgumentException $exception) {
                throw new SdkException('SDK API version is invalid.', 0, $exception);
            }
        }
        if (($artifact['squehub_contract'] ?? null) !== '1') {
            throw new SdkException('SDK generation requires SqueHub Application Contract version 1.');
        }
        $schemas = $artifact['schemas'] ?? null;
        $operations = $artifact['operations'] ?? null;
        $webhooks = $artifact['webhooks'] ?? null;
        if (!is_array($schemas) || !is_array($operations) || !array_is_list($operations)
            || !is_array($webhooks) || !array_is_list($webhooks)) {
            throw new SdkException('Application Contract collections are invalid.');
        }
        $schemaSymbols = self::schemaSymbols($schemas);
        $schemaNodes = 0;
        foreach ($schemas as $name => $schema) {
            self::schema($schema, $schemas, 'schema ' . $name, 0, $schemaNodes);
        }
        foreach ($schemas as $name => $schema) $schemas[$name] = self::stripSchema($schema);
        if ($operations === []) {
            throw new SdkException('No public contracted API operations are available for SDK generation.');
        }

        $normalized = [];
        $nativeOperations = [];
        $seenIds = [];
        $seenSymbols = [];
        $seenPaths = [];
        $versions = [];
        foreach ($operations as $operation) {
            if (!is_array($operation) || !is_string($operation['operation_id'] ?? null)) {
                throw new SdkException('A public contract operation has no operation ID.');
            }
            $id = $operation['operation_id'];
            if (preg_match('/\A[A-Za-z][A-Za-z0-9._-]{0,127}\z/D', $id) !== 1) {
                throw new SdkException('Operation ID is not a bounded public identifier.');
            }
            if (isset($seenIds[$id])) self::unsupported($id, 'duplicate operation ID');
            $seenIds[$id] = true;
            $native = self::operation($operation, $schemas, $id, $schemaNodes);
            $nativeOperations[] = $native;
            $version = $native['api_version'];
            if ($version !== null) $versions[$version] = true;
            if ($apiVersion !== null && $version !== $apiVersion) {
                self::unsupported($id, 'operation does not match selected API version');
            }
            $segments = self::operationSegments($id);
            $symbol = implode('', array_map('ucfirst', $segments));
            $folded = strtolower($symbol);
            if (isset($seenSymbols[$folded])) {
                self::unsupported($id, 'generated symbol collides with ' . $seenSymbols[$folded]);
            }
            $seenSymbols[$folded] = $id;
            $pathKey = strtolower(implode('.', $segments));
            if (isset($seenPaths[$pathKey])) {
                self::unsupported($id, 'generated method path collides with ' . $seenPaths[$pathKey]);
            }
            $seenPaths[$pathKey] = $id;
            $normalized[] = $native + ['symbol' => $symbol, 'segments' => $segments];
        }
        if ($apiVersion === null && count($versions) > 1) {
            throw new SdkException('Multiple API versions are present; select --api-version for SDK generation.');
        }
        foreach ($seenPaths as $path => $id) {
            $parts = explode('.', $path);
            while (count($parts) > 1) {
                array_pop($parts);
                if (isset($seenPaths[implode('.', $parts)])) {
                    self::unsupported($id, 'generated method path is also an operation group');
                }
            }
        }
        $occupied = [];
        foreach ($schemaSymbols as $name => $symbol) {
            foreach ([$symbol, $symbol . 'Input'] as $generated) {
                if (isset($occupied[strtolower($generated)])) {
                    throw new SdkException('Schema ' . $name . ' generated type collides with '
                        . $occupied[strtolower($generated)] . '.');
                }
                $occupied[strtolower($generated)] = 'schema ' . $name;
            }
        }
        foreach ($normalized as $operation) {
            $id = $operation['operation_id'];
            foreach ([$operation['symbol'] . 'Args', $operation['symbol'] . 'Response'] as $generated) {
                if (isset($occupied[strtolower($generated)])) {
                    self::unsupported($id, 'generated type collides with ' . $occupied[strtolower($generated)]);
                }
                $occupied[strtolower($generated)] = 'operation ' . $id;
            }
        }
        $normalizedWebhooks = [];
        foreach ($webhooks as $webhook) {
            if (!is_array($webhook) || !is_string($webhook['type'] ?? null)
                || !array_key_exists('data_schema', $webhook)) {
                throw new SdkException('A webhook payload contract is invalid.');
            }
            self::schema($webhook['data_schema'], $schemas, 'webhook ' . $webhook['type'],
                0, $schemaNodes);
            $normalizedWebhooks[] = [
                'type' => $webhook['type'],
                'data_schema' => self::stripSchema($webhook['data_schema']),
            ];
        }
        ksort($schemas, SORT_STRING);
        ksort($schemaSymbols, SORT_STRING);
        usort($nativeOperations, static fn (array $a, array $b): int =>
            [$a['path'], $a['method'], $a['operation_id']]
            <=> [$b['path'], $b['method'], $b['operation_id']]);
        usort($normalized, static fn (array $a, array $b): int =>
            [$a['path'], $a['method'], $a['operation_id']]
            <=> [$b['path'], $b['method'], $b['operation_id']]);
        usort($normalizedWebhooks, static fn (array $a, array $b): int =>
            strcmp($a['type'], $b['type']));
        $fingerprintSource = self::canonicalize([
            'squehub_contract' => '1', 'api_version' => $apiVersion,
            'schemas' => $schemas, 'operations' => $nativeOperations,
            'webhooks' => $normalizedWebhooks,
        ]);
        try {
            $bytes = json_encode($fingerprintSource, JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $exception) {
            throw new SdkException('SDK contract fingerprint could not be calculated.', 0, $exception);
        }
        return new SdkModel($apiVersion, $schemas, $schemaSymbols, $normalized,
            $normalizedWebhooks, hash('sha256', $bytes));
    }

    private static function operation(array $source, array $schemas, string $id,
        int &$schemaNodes): array
    {
        $method = $source['method'] ?? null;
        $path = $source['path'] ?? null;
        $version = $source['api_version'] ?? null;
        if (!is_string($method) || !in_array($method,
            ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)
            || !is_string($path) || !str_starts_with($path, '/')
            || ($version !== null && (!is_string($version)
                || preg_match('/\A[a-z0-9][a-z0-9_-]{0,31}\z/D', $version) !== 1))) {
            self::unsupported($id, 'HTTP method or path');
        }
        $parameters = $source['parameters'] ?? null;
        $responses = $source['responses'] ?? null;
        $security = $source['security'] ?? null;
        if (!is_array($parameters) || !array_is_list($parameters)
            || !is_array($responses) || !is_array($security)) {
            self::unsupported($id, 'parameter, response, or security declaration');
        }
        $pathNames = [];
        $seenParameters = [];
        $normalizedParameters = [];
        foreach ($parameters as $parameter) {
            if (!is_array($parameter) || !is_string($parameter['name'] ?? null)
                || !is_string($parameter['in'] ?? null)
                || !is_bool($parameter['required'] ?? null)
                || !array_key_exists('schema', $parameter)) {
                self::unsupported($id, 'parameter declaration');
            }
            $location = $parameter['in'];
            $name = $parameter['name'];
            if ($location === 'cookie') self::unsupported($id, 'cookie parameter serialization');
            if (!in_array($location, ['path', 'query', 'header'], true)) {
                self::unsupported($id, 'parameter location ' . $location);
            }
            $identity = $location . ':' . ($location === 'header' ? strtolower($name) : $name);
            if (isset($seenParameters[$identity])) self::unsupported($id, 'duplicate parameter ' . $name);
            $seenParameters[$identity] = true;
            if ($location === 'path') {
                if ($parameter['required'] !== true) self::unsupported($id, 'optional path parameter');
                $pathNames[] = $name;
            }
            if ($location === 'header' && in_array(strtolower($name),
                ['accept', 'authorization', 'content-type', 'cookie', 'host'], true)) {
                self::unsupported($id, 'reserved request header ' . $name);
            }
            self::scalarSchema($parameter['schema'], $schemas, $id,
                $location . ' parameter ' . $name);
            self::schema($parameter['schema'], $schemas, 'operation ' . $id, 0, $schemaNodes);
            $normalizedParameters[] = [
                'name' => $name, 'in' => $location,
                'required' => $parameter['required'],
                'schema' => self::stripSchema($parameter['schema']),
            ];
        }
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $path, $matches);
        sort($pathNames, SORT_STRING);
        $expected = $matches[1];
        sort($expected, SORT_STRING);
        if ($pathNames !== $expected) self::unsupported($id, 'path parameter mismatch');
        $locations = ['path' => 0, 'query' => 1, 'header' => 2];
        usort($normalizedParameters, static fn (array $a, array $b): int =>
            [$locations[$a['in']], strtolower($a['name'])]
            <=> [$locations[$b['in']], strtolower($b['name'])]);

        $body = $source['request_body'] ?? null;
        if ($body !== null) {
            if (!is_array($body) || !is_bool($body['required'] ?? null)
                || !is_string($body['content_type'] ?? null)
                || !array_key_exists('schema', $body)) {
                self::unsupported($id, 'request body declaration');
            }
            if (!self::jsonMediaType($body['content_type'])) {
                self::unsupported($id, 'request media type ' . $body['content_type']);
            }
            if (self::booleanRoot($body['schema'], $schemas)) {
                self::unsupported($id, 'untyped request body schema');
            }
            self::schema($body['schema'], $schemas, 'operation ' . $id, 0, $schemaNodes);
            $body = [
                'required' => $body['required'],
                'content_type' => $body['content_type'],
                'schema' => self::stripSchema($body['schema']),
            ];
        }
        $success = 0;
        $normalizedResponses = [];
        foreach ($responses as $status => $response) {
            $statusKey = (string) $status;
            if ($statusKey !== 'default' && preg_match('/\A[1-5][0-9]{2}\z/D', $statusKey) !== 1) {
                self::unsupported($id, 'response status ' . $statusKey);
            }
            if (!is_array($response) || !array_key_exists('schema', $response)
                || !array_key_exists('content_type', $response)
                || !is_array($response['headers'] ?? null)) {
                self::unsupported($id, 'response ' . $statusKey . ' declaration');
            }
            $isSuccess = $statusKey !== 'default' && (int) $statusKey >= 200
                && (int) $statusKey < 300;
            if ($isSuccess) ++$success;
            $schema = $response['schema'];
            $contentType = $response['content_type'];
            if (!$isSuccess && (!is_array($schema)
                || !in_array($schema['$ref'] ?? null, [
                    '#/components/schemas/SqueHubApiError',
                    '#/components/schemas/SqueHubValidationError',
                ], true))) {
                self::unsupported($id, 'non-2xx response ' . $statusKey
                    . ' is not a SqueHub API error envelope');
            }
            if ($schema === null) {
                if ($isSuccess && !in_array((int) $statusKey, [204, 205], true)
                    && $method !== 'HEAD') {
                    self::unsupported($id, 'success response ' . $statusKey . ' has no schema');
                }
                if ($contentType !== null) self::unsupported($id, 'bodyless response media type');
            } else {
                if ($isSuccess && $method === 'HEAD') {
                    self::unsupported($id, 'HEAD success response declares a body schema');
                }
                if ($isSuccess && in_array((int) $statusKey, [204, 205], true)) {
                    self::unsupported($id, 'body schema on bodyless response ' . $statusKey);
                }
                if (!is_string($contentType) || !self::jsonMediaType($contentType)) {
                    self::unsupported($id, 'response ' . $statusKey . ' media type');
                }
                if ($isSuccess && self::booleanRoot($schema, $schemas)) {
                    self::unsupported($id, 'untyped success response ' . $statusKey);
                }
                self::schema($schema, $schemas, 'operation ' . $id, 0, $schemaNodes);
            }
            $headers = [];
            foreach ($response['headers'] as $name => $header) {
                if (!is_string($name) || !is_array($header)
                    || !array_key_exists('schema', $header)) {
                    self::unsupported($id, 'response header declaration');
                }
                self::schema($header['schema'], $schemas, 'operation ' . $id, 0, $schemaNodes);
                $headers[$name] = ['schema' => self::stripSchema($header['schema'])];
            }
            ksort($headers, SORT_STRING);
            $normalizedResponses[$statusKey] = [
                'content_type' => $contentType,
                'schema' => $schema === null ? null : self::stripSchema($schema),
                'headers' => $headers,
            ];
        }
        if ($success === 0) self::unsupported($id, 'no declared 2xx success response');
        uksort($normalizedResponses, static function (string|int $a, string|int $b): int {
            if ($a === 'default') return 1;
            if ($b === 'default') return -1;
            return (int) $a <=> (int) $b;
        });
        $securityType = $security['type'] ?? null;
        if (!in_array($securityType, ['none', 'pat', 'session'], true)) {
            self::unsupported($id, 'security type');
        }
        if ($securityType === 'session' && (!is_string($security['cookie_name'] ?? null)
            || $security['cookie_name'] === '')) {
            self::unsupported($id, 'session cookie name');
        }
        foreach (['token_abilities', 'authorization_abilities'] as $key) {
            if (!is_array($security[$key] ?? null) || !array_is_list($security[$key])) {
                self::unsupported($id, 'security abilities');
            }
            foreach ($security[$key] as $ability) {
                if (!is_string($ability)) self::unsupported($id, 'security ability');
            }
        }
        return [
            'operation_id' => $id,
            'method' => $method,
            'path' => $path,
            'api_version' => $version,
            'parameters' => $normalizedParameters,
            'request_body' => $body,
            'responses' => $normalizedResponses,
            'security' => self::normalizedSecurity($security),
        ];
    }

    /** Parameter encodings have no declared style; only one scalar text value is safe. */
    private static function scalarSchema(mixed $schema, array $schemas, string $id,
        string $label, array $trail = []): void
    {
        if (!is_array($schema) || $schema === [] || array_is_list($schema)) {
            self::unsupported($id, $label . ' requires a scalar schema');
        }
        if (isset($schema['$ref'])) {
            $name = self::referenceName($schema['$ref']);
            if ($name === null || !array_key_exists($name, $schemas) || isset($trail[$name])) {
                self::unsupported($id, $label . ' has an unsupported reference');
            }
            $trail[$name] = true;
            self::scalarSchema($schemas[$name], $schemas, $id, $label, $trail);
            return;
        }
        if (isset($schema['oneOf']) || isset($schema['anyOf']) || isset($schema['allOf'])) {
            self::unsupported($id, $label . ' has composition without a serialization style');
        }
        $type = $schema['type'] ?? null;
        if ($type === null && isset($schema['enum'])) {
            $types = [];
            foreach ($schema['enum'] as $value) {
                if ($value === null || is_array($value)) {
                    self::unsupported($id, $label . ' has a nullable or structured enum');
                }
                $types[get_debug_type($value)] = true;
            }
            if (count($types) !== 1) self::unsupported($id, $label . ' has a mixed-type enum');
            return;
        }
        if (!in_array($type, ['string', 'integer', 'number', 'boolean'], true)) {
            self::unsupported($id, $label . ' requires one non-null scalar type');
        }
        if (isset($schema['enum'])) {
            foreach ($schema['enum'] as $value) {
                if ($value === null || is_array($value)) {
                    self::unsupported($id, $label . ' has a nullable or structured enum');
                }
            }
        }
    }

    private static function schema(mixed $schema, array $schemas, string $context,
        int $depth, int &$nodes): void
    {
        if (++$nodes > 100000 || $depth > 64) {
            throw new SdkException('SDK schema exceeds supported bounds in ' . $context . '.');
        }
        if (is_bool($schema)) return;
        if (!is_array($schema) || $schema === [] || array_is_list($schema)) {
            throw new SdkException('SDK schema must be an object or boolean in ' . $context . '.');
        }
        foreach ($schema as $keyword => $value) {
            if (!in_array($keyword, self::SCHEMA_KEYWORDS, true)) {
                throw new SdkException('Unsupported SDK schema keyword in ' . $context . '.');
            }
            if ($keyword === '$ref') {
                $name = self::referenceName($value);
                if ($name === null || !array_key_exists($name, $schemas)) {
                    throw new SdkException('Unresolved local SDK schema reference in ' . $context . '.');
                }
            } elseif ($keyword === 'type') {
                $types = is_array($value) && array_is_list($value) ? $value : [$value];
                if ($types === [] || count($types) !== count(array_unique($types, SORT_REGULAR))) {
                    throw new SdkException('Unsupported SDK schema type in ' . $context . '.');
                }
                foreach ($types as $type) {
                    if (!is_string($type) || !in_array($type, self::TYPES, true)) {
                        throw new SdkException('Unsupported SDK schema type in ' . $context . '.');
                    }
                }
            } elseif ($keyword === 'properties') {
                if (!is_array($value)) throw new SdkException('Invalid SDK properties in ' . $context . '.');
                if ($value !== [] && is_array($schema['additionalProperties'] ?? null)) {
                    throw new SdkException('Typed additional properties beside named properties '
                        . 'are unsupported for SDK generation in ' . $context . '.');
                }
                foreach ($value as $name => $property) {
                    if (!is_string($name)) throw new SdkException('Invalid SDK property name in ' . $context . '.');
                    self::schema($property, $schemas, $context, $depth + 1, $nodes);
                }
            } elseif ($keyword === 'items' || $keyword === 'additionalProperties') {
                self::schema($value, $schemas, $context, $depth + 1, $nodes);
            } elseif (in_array($keyword, ['oneOf', 'anyOf', 'allOf'], true)) {
                if (!is_array($value) || !array_is_list($value) || $value === []) {
                    throw new SdkException('Invalid SDK schema composition in ' . $context . '.');
                }
                foreach ($value as $branch) self::schema($branch, $schemas, $context,
                    $depth + 1, $nodes);
                if ($keyword === 'oneOf') self::disjointOneOf($value, $schemas, $context);
            } elseif ($keyword === 'required') {
                if (!is_array($value) || !array_is_list($value)) {
                    throw new SdkException('Invalid SDK required properties in ' . $context . '.');
                }
                foreach ($value as $name) {
                    if (!is_string($name) || !array_key_exists($name, $schema['properties'] ?? [])) {
                        throw new SdkException('Unresolved SDK required property in ' . $context . '.');
                    }
                }
            }
        }
    }

    /** A static union is faithful to oneOf only where branches cannot overlap. */
    private static function disjointOneOf(array $branches, array $schemas, string $context): void
    {
        $domains = [];
        foreach ($branches as $branch) {
            $domains[] = self::branchDomain($branch, $schemas);
        }
        for ($left = 0, $count = count($domains); $left < $count; ++$left) {
            for ($right = $left + 1; $right < $count; ++$right) {
                [$leftTypes, $leftValues] = $domains[$left];
                [$rightTypes, $rightValues] = $domains[$right];
                if ($leftValues !== null && $rightValues !== null
                    && array_intersect($leftValues, $rightValues) === []) continue;
                if ($leftTypes !== [] && $rightTypes !== []
                    && array_intersect($leftTypes, $rightTypes) === []) continue;
                throw new SdkException('Overlapping or unproven oneOf branches are unsupported '
                    . 'for SDK generation in ' . $context . '.');
            }
        }
    }

    /** @return array{list<string>,?list<string>} Type domain and finite value set. */
    private static function branchDomain(mixed $schema, array $schemas,
        array $trail = []): array
    {
        if ($schema === true) return [[], null];
        if ($schema === false) return [[], []];
        if (isset($schema['$ref'])) {
            $name = self::referenceName($schema['$ref']);
            if ($name === null || isset($trail[$name])) return [[], null];
            $trail[$name] = true;
            return self::branchDomain($schemas[$name], $schemas, $trail);
        }
        $finite = null;
        if (array_key_exists('const', $schema)) $finite = [$schema['const']];
        elseif (isset($schema['enum'])) $finite = $schema['enum'];
        if ($finite !== null) {
            $values = [];
            $types = [];
            foreach ($finite as $value) {
                // JSON Schema compares 1 and 1.0 as the same numeric value.
                // JSON Schema compares object members without regard to their
                // insertion order, including finite enum/const branches.
                $values[] = (string) json_encode(self::canonicalize($value));
                $types[] = self::valueType($value);
            }
            return [self::expandedTypes($types), $values];
        }
        $types = $schema['type'] ?? [];
        return [self::expandedTypes(is_array($types) ? $types : [$types]), null];
    }

    private static function valueType(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) && array_is_list($value) => 'array',
            default => 'object',
        };
    }

    /** @param list<string> $types @return list<string> */
    private static function expandedTypes(array $types): array
    {
        $expanded = $types;
        if (in_array('number', $expanded, true)) $expanded[] = 'integer';
        return array_values(array_unique($expanded));
    }

    /** @return array<string,string> */
    private static function schemaSymbols(array $schemas): array
    {
        $symbols = [];
        $seen = [];
        foreach ($schemas as $name => $schema) {
            if (!is_string($name)
                || preg_match('/\A[A-Za-z_][A-Za-z0-9._-]{0,127}\z/D', $name) !== 1) {
                throw new SdkException('Contract schema name is invalid for SDK generation.');
            }
            $symbol = $name === 'SqueHubApiError' ? 'SqueHubApiErrorBody'
                : self::pascal(self::identifierWords($name));
            if (in_array(strtolower($symbol), array_map('strtolower', self::RESERVED_SYMBOLS), true)) {
                throw new SdkException('Schema ' . $name . ' collides with a generated SDK runtime symbol.');
            }
            $folded = strtolower($symbol);
            if (isset($seen[$folded])) {
                throw new SdkException('Schemas ' . $name . ' and ' . $seen[$folded]
                    . ' normalize to the same SDK symbol.');
            }
            $seen[$folded] = $name;
            $symbols[$name] = $symbol;
        }
        return $symbols;
    }

    /** @return list<string> */
    private static function operationSegments(string $id): array
    {
        $parts = explode('.', $id);
        $segments = [];
        foreach ($parts as $part) {
            if ($part === '') self::unsupported($id, 'empty operation ID segment');
            if (in_array(strtolower($part), self::RESERVED_JS_OBJECT_SEGMENTS, true)) {
                self::unsupported($id, 'JavaScript object or promise method name is reserved');
            }
            $words = self::identifierWords($part);
            if ($words === '') self::unsupported($id, 'empty generated operation name');
            $segment = lcfirst(self::pascal($words));
            if (ctype_digit($segment[0])) $segment = 'n' . $segment;
            if (in_array(strtolower($segment), self::RESERVED_JS_OBJECT_SEGMENTS, true)) {
                self::unsupported($id, 'JavaScript object or promise method name is reserved');
            }
            if (in_array(strtolower($segment), array_map('strtolower', self::RESERVED_SEGMENTS), true)) {
                $segment .= '_';
            }
            $segments[] = $segment;
        }
        return $segments;
    }

    private static function identifierWords(string $name): string
    {
        $name = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $name);
        $name = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name);
        return trim((string) preg_replace('/[._-]+/', ' ', $name));
    }

    private static function pascal(string $words): string
    {
        $result = '';
        foreach (explode(' ', strtolower($words)) as $word) {
            if ($word !== '') $result .= ucfirst($word);
        }
        return $result;
    }

    private static function referenceName(mixed $reference): ?string
    {
        if (!is_string($reference)
            || preg_match('~\A#/components/schemas/([A-Za-z_][A-Za-z0-9._-]{0,127})\z~D',
                $reference, $matches) !== 1) return null;
        return $matches[1];
    }

    private static function jsonMediaType(string $type): bool
    {
        return $type === 'application/json'
            || preg_match('~\Aapplication/[a-z0-9!#$&^_.+-]+\+json\z~D', $type) === 1;
    }

    /** Follow only a root reference chain; nested boolean schemas remain valid. */
    private static function booleanRoot(mixed $schema, array $schemas, array $trail = []): bool
    {
        if (is_bool($schema)) return true;
        if (!is_array($schema) || !isset($schema['$ref'])) return false;
        $name = self::referenceName($schema['$ref']);
        if ($name === null || !array_key_exists($name, $schemas)) return false;
        if (isset($trail[$name])) return true;
        $trail[$name] = true;
        return self::booleanRoot($schemas[$name], $schemas, $trail);
    }

    /** Keep constraints and type annotations; omit author-supplied sample text. */
    private static function stripSchema(array|bool $schema): array|bool
    {
        if (is_bool($schema)) return $schema;
        $result = [];
        foreach ($schema as $keyword => $value) {
            if (in_array($keyword, ['description', 'examples', 'default', 'deprecated'], true)) {
                continue;
            }
            if ($keyword === 'properties') {
                $properties = [];
                foreach ($value as $name => $property) {
                    $properties[$name] = self::stripSchema($property);
                }
                ksort($properties, SORT_STRING);
                $result[$keyword] = $properties;
            } elseif ($keyword === 'items' || $keyword === 'additionalProperties') {
                $result[$keyword] = self::stripSchema($value);
            } elseif (in_array($keyword, ['oneOf', 'anyOf', 'allOf'], true)) {
                $result[$keyword] = array_map(self::stripSchema(...), $value);
            } elseif ($keyword === 'enum') {
                $result[$keyword] = $value;
                usort($result[$keyword], static fn (mixed $a, mixed $b): int =>
                    strcmp(json_encode($a, JSON_PRESERVE_ZERO_FRACTION),
                        json_encode($b, JSON_PRESERVE_ZERO_FRACTION)));
            } else {
                $result[$keyword] = $value;
            }
        }
        return $result;
    }

    private static function normalizedSecurity(array $security): array
    {
        $tokens = array_values(array_unique($security['token_abilities']));
        $authorization = array_values(array_unique($security['authorization_abilities']));
        sort($tokens, SORT_STRING);
        sort($authorization, SORT_STRING);
        $normalized = [
            'type' => $security['type'],
            'token_abilities' => $tokens,
            'authorization_abilities' => $authorization,
        ];
        if ($security['type'] === 'session') $normalized['cookie_name'] = $security['cookie_name'];
        return $normalized;
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $nested) $value[$key] = self::canonicalize($nested);
        return $value;
    }

    private static function unsupported(string $id, string $capability): never
    {
        throw new SdkException('Operation ' . $id . ' cannot be generated: ' . $capability . '.');
    }
}
