<?php

declare(strict_types=1);

namespace App\Api\Sdk;

use App\Api\Contract\ContractManager;
use App\Api\Sdk\Emitters\JavaScriptEmitter;
use App\Api\Sdk\Emitters\PhpEmitter;
use App\Api\Sdk\Emitters\TypeScriptEmitter;
use JsonException;

/**
 * Renders client source from the native application contract. The normalizer
 * owns support checks; emitters receive only that detached, validated model.
 */
final class SdkGenerator
{
    public function __construct(
        private ContractManager $contracts,
    ) {
    }

    /**
     * @return array{language:string,api_version:?string,fingerprint:string,operations:int,schemas:int,files:int,current:bool}
     */
    public function generate(string $language, string $output, string $basePath,
        ?string $apiVersion = null, bool $check = false): array
    {
        $model = SdkNormalizer::normalize($this->contracts, $apiVersion);
        $emitter = match ($language) {
            'typescript' => new TypeScriptEmitter(),
            'javascript' => new JavaScriptEmitter(),
            'php' => new PhpEmitter(),
            default => throw new SdkException('SDK language must be typescript, javascript, or php.'),
        };

        $files = $emitter->emit($model);
        if ($files === []) {
            throw new SdkException('SDK emitter produced no files.');
        }
        ksort($files, SORT_STRING);
        $symbols = [];
        foreach ($model->operations as $operation) {
            $symbols[$operation['operation_id']] = [
                'symbol' => $operation['symbol'],
                'segments' => $operation['segments'],
            ];
        }
        ksort($symbols, SORT_STRING);
        try {
            $manifest = json_encode([
                'squehub_sdk' => '1',
                'language' => $language,
                'contract_fingerprint' => $model->fingerprint,
                'api_version' => $model->apiVersion,
                'operations' => $symbols,
                'schemas' => $model->schemaSymbols,
                'files' => array_keys($files),
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE) . "\n";
        } catch (JsonException $exception) {
            throw new SdkException('SDK manifest could not be encoded.', 0, $exception);
        }
        $files['squehub-sdk.json'] = $manifest;
        $current = (new SdkOutput($basePath))->sync($output, $files, $language, $check);

        return [
            'language' => $language,
            'api_version' => $model->apiVersion,
            'fingerprint' => $model->fingerprint,
            'operations' => count($model->operations),
            'schemas' => count($model->schemas),
            'files' => count($files),
            'current' => $current,
        ];
    }
}
