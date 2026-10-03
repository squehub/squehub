<?php

declare(strict_types=1);

namespace App\Api\Contract;

use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ValidationError;
use stdClass;
use Throwable;

/**
 * Validates a decoded HTTP JSON response against a native SqueHub contract.
 *
 * Opis performs Draft 2020-12 validation. This adapter only places SqueHub's
 * registered component schemas in an in-memory document and translates their
 * local references. It never accepts an external schema URI or enables a
 * resolver that could read a file or contact a network service.
 */
final class ResponseSchemaValidator
{
    private const DRAFT = 'https://json-schema.org/draft/2020-12/schema';
    private const MAX_SCHEMA_NODES = 10000;
    private const MAX_DATA_NODES = 100000;
    private const MAX_DEPTH = 128;

    /**
     * @param array<string,mixed>|bool $schema A Phase 12G Schema::toArray() value.
     * @param array<string,array<string,mixed>|bool> $components Native named schemas.
     * @return array{path:string,expected:string,actual:string}|null
     */
    public function validate(mixed $decodedJson, array|bool $schema,
        array $components = []): ?array
    {
        $dataNodes = 0;
        self::checkJsonData($decodedJson, 0, $dataNodes);

        $schemaNodes = 0;
        $names = [];
        foreach ($components as $name => $component) {
            if (!is_string($name) || preg_match('/\A[A-Za-z_][A-Za-z0-9_.-]{0,127}\z/D', $name) !== 1
                || (!is_array($component) && !is_bool($component))) {
                throw new ContractException('A response contract component schema is invalid.');
            }
            $names[$name] = true;
        }

        $localized = self::localize($schema, $names, 0, $schemaNodes);
        $definitions = [];
        foreach ($components as $name => $component) {
            $definitions[$name] = self::localize($component, $names, 0, $schemaNodes);
        }
        $declaredProperties = [];
        self::collectProperties($localized, $declaredProperties);
        foreach ($definitions as $definition) {
            self::collectProperties($definition, $declaredProperties);
        }

        // The enclosing schema gives every component the same local reference
        // base, including recursive references. No OpenAPI document is parsed.
        $document = ['$schema' => self::DRAFT, 'allOf' => [$localized]];
        if ($definitions !== []) $document['$defs'] = $definitions;

        try {
            $encoded = json_encode($document, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            $opisDocument = json_decode($encoded, false, 512, JSON_THROW_ON_ERROR);
            $validator = new CompliantValidator();
            $validator->setResolver(null);
            $error = $validator->validate($decodedJson, $opisDocument)->error();
        } catch (Throwable) {
            // Library exceptions can contain schema examples or response values.
            // Do not attach them as causes to a finding or operator-facing error.
            throw new ContractException('Response contract schema validation could not complete.');
        }

        return $error === null ? null : self::finding($error, $declaredProperties);
    }

    /**
     * Only schema-bearing keywords are traversed. Example and enum values may
     * themselves contain a harmless "$ref" field and are not schema references.
     *
     * @param array<string,true> $names
     */
    private static function localize(array|bool $schema, array $names,
        int $depth, int &$nodes): array|bool
    {
        if (++$nodes > self::MAX_SCHEMA_NODES || $depth > self::MAX_DEPTH) {
            throw new ContractException('Response contract schema exceeds supported bounds.');
        }
        if (is_bool($schema)) return $schema;
        if ($schema === [] || array_is_list($schema)) {
            throw new ContractException('Response contract schema must be an object or boolean.');
        }

        $result = $schema;
        foreach ($schema as $keyword => $value) {
            if ($keyword === '$ref') {
                if (!is_string($value)
                    || preg_match('~\A#/components/schemas/([A-Za-z_][A-Za-z0-9_.-]{0,127})\z~D',
                        $value, $match) !== 1
                    || !isset($names[$match[1]])) {
                    throw new ContractException('Response contract schema reference is not a registered local component.');
                }
                $result[$keyword] = '#/$defs/' . $match[1];
            } elseif ($keyword === 'properties') {
                if (!is_array($value)) {
                    throw new ContractException('Response contract properties declaration is invalid.');
                }
                foreach ($value as $name => $property) {
                    if (!is_string($name) || (!is_array($property) && !is_bool($property))) {
                        throw new ContractException('Response contract property schema is invalid.');
                    }
                    $result[$keyword][$name] = self::localize($property, $names, $depth + 1, $nodes);
                }
            } elseif ($keyword === 'items' || $keyword === 'additionalProperties') {
                if (!is_array($value) && !is_bool($value)) {
                    throw new ContractException('Response contract nested schema is invalid.');
                }
                $result[$keyword] = self::localize($value, $names, $depth + 1, $nodes);
            } elseif (in_array($keyword, ['oneOf', 'anyOf', 'allOf'], true)) {
                if (!is_array($value) || !array_is_list($value) || $value === []) {
                    throw new ContractException('Response contract composition declaration is invalid.');
                }
                foreach ($value as $index => $branch) {
                    if (!is_array($branch) && !is_bool($branch)) {
                        throw new ContractException('Response contract composition branch is invalid.');
                    }
                    $result[$keyword][$index] = self::localize($branch, $names, $depth + 1, $nodes);
                }
            } elseif (is_string($keyword) && str_starts_with($keyword, '$')) {
                throw new ContractException('Response contract schema keyword is unsupported.');
            }
        }
        return $result;
    }

    /** A valid decoded body contains only JSON scalar, list and object types. */
    private static function checkJsonData(mixed $data, int $depth, int &$nodes): void
    {
        if (++$nodes > self::MAX_DATA_NODES || $depth > self::MAX_DEPTH) {
            throw new ContractException('Decoded response JSON exceeds verification bounds.');
        }
        if ($data instanceof stdClass) {
            foreach (get_object_vars($data) as $value) {
                self::checkJsonData($value, $depth + 1, $nodes);
            }
            return;
        }
        if (is_array($data) && array_is_list($data)) {
            foreach ($data as $value) self::checkJsonData($value, $depth + 1, $nodes);
            return;
        }
        if ($data === null || is_bool($data) || is_int($data) || is_string($data)
            || (is_float($data) && is_finite($data))) return;
        throw new ContractException('Response value must be decoded JSON data.');
    }

    /**
     * A response may use attacker-controlled object keys, especially under
     * additionalProperties. Only names published by the contract may enter a
     * finding path; array indexes are inherently structural.
     *
     * @param array<string,true> $names
     */
    private static function collectProperties(array|bool $schema, array &$names): void
    {
        if (is_bool($schema)) return;
        foreach ($schema['properties'] ?? [] as $name => $property) {
            $names[$name] = true;
            self::collectProperties($property, $names);
        }
        foreach (['items', 'additionalProperties'] as $keyword) {
            if (isset($schema[$keyword])) self::collectProperties($schema[$keyword], $names);
        }
        foreach (['oneOf', 'anyOf', 'allOf'] as $keyword) {
            foreach ($schema[$keyword] ?? [] as $branch) {
                self::collectProperties($branch, $names);
            }
        }
    }

    /**
     * Preserve only a bounded structural path and type/category labels. Opis
     * error messages and arguments are not copied: they may quote body values.
     *
     * @return array{path:string,expected:string,actual:string}
     */
    private static function finding(ValidationError $error, array $declaredProperties): array
    {
        // Wrappers contain a more useful failure for a declared property,
        // array item or local reference. Composition failures remain atomic.
        while (in_array($error->keyword(), ['allOf', 'properties', 'items', '$ref'], true)
            && $error->subErrors() !== []) {
            $error = $error->subErrors()[0];
        }

        $keyword = $error->keyword();
        $path = self::safePath($error->data()->fullPath(), $declaredProperties);
        $actual = $error->data()->type();
        $actual = in_array($actual, ['null', 'boolean', 'integer', 'number',
            'string', 'object', 'array'], true) ? $actual : 'unknown';

        if ($keyword === 'required') {
            // Opis derives this name from the declared `required` list. Never
            // use additionalProperties arguments, which name response fields.
            $missing = $error->args()['missing'][0] ?? null;
            if (is_string($missing)) $path = self::safePath(
                [...$error->data()->fullPath(), $missing], $declaredProperties);
            return ['path' => $path, 'expected' => 'required property', 'actual' => 'missing'];
        }
        if ($keyword === 'additionalProperties') {
            return ['path' => $path, 'expected' => 'declared properties',
                'actual' => 'additional property'];
        }
        if ($keyword === 'type') {
            $declared = $error->schema()->info()->data();
            $type = is_object($declared) ? ($declared->type ?? null) : null;
            if (is_string($type)) $expected = self::safeType($type);
            elseif (is_array($type)) $expected = implode('|', array_map(self::safeType(...), $type));
            else $expected = 'declared type';
        } else {
            $expected = in_array($keyword, ['enum', 'oneOf', 'anyOf', 'allOf',
                'minLength', 'maxLength', 'pattern', 'format', 'minimum', 'maximum',
                'exclusiveMinimum', 'exclusiveMaximum', 'minItems', 'maxItems',
                'uniqueItems', 'false', 'const'], true) ? $keyword : 'declared schema';
        }
        return ['path' => $path, 'expected' => $expected, 'actual' => $actual];
    }

    private static function safeType(string $type): string
    {
        return in_array($type, ['null', 'boolean', 'integer', 'number', 'string',
            'object', 'array'], true) ? $type : 'declared type';
    }

    /** @param list<string|int> $segments @param array<string,true> $declaredProperties */
    private static function safePath(array $segments, array $declaredProperties): string
    {
        $path = '$';
        foreach (array_slice($segments, 0, 32) as $segment) {
            if (is_int($segment) && $segment >= 0 && $segment <= 1000000) {
                $suffix = '[' . $segment . ']';
            } elseif (is_string($segment) && isset($declaredProperties[$segment])
                && strlen($segment) <= 64
                && preg_match('/\A[A-Za-z_][A-Za-z0-9_.-]*\z/D', $segment) === 1) {
                $suffix = '.' . $segment;
            } else {
                break;
            }
            if (strlen($path) + strlen($suffix) > 256) break;
            $path .= $suffix;
        }
        return $path;
    }
}
