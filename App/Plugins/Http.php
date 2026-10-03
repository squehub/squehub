<?php

declare(strict_types=1);

namespace App\Plugins;

use App\HttpClient\Http as Gateway;
use App\HttpClient\HttpClient;
use App\HttpClient\HttpResponse;
use App\HttpClient\PendingRequest;
use App\Reliability\RetryPolicy;
use JsonException;

/** Fluent application gateway for outgoing HTTP; incoming App\Http remains separate. */
final class Http
{
    public static function client(): HttpClient { return Gateway::client(); }
    public static function pending(): PendingRequest { return self::client()->pending(); }
    public static function withHeaders(array $headers): PendingRequest { return self::pending()->withHeaders($headers); }
    public static function withToken(#[\SensitiveParameter] string $token): PendingRequest
    { return self::pending()->withToken($token); }
    public static function withBasicAuth(#[\SensitiveParameter] string $username,
        #[\SensitiveParameter] string $password): PendingRequest
    { return self::pending()->withBasicAuth($username, $password); }
    public static function withQuery(array $query): PendingRequest { return self::pending()->withQuery($query); }
    public static function acceptJson(): PendingRequest { return self::pending()->acceptJson(); }
    public static function asJson(): PendingRequest { return self::pending()->asJson(); }
    public static function asForm(): PendingRequest { return self::pending()->asForm(); }
    public static function multipart(): PendingRequest { return self::pending()->multipart(); }
    public static function withBody(#[\SensitiveParameter] string $body, string $contentType): PendingRequest
    { return self::pending()->withBody($body, $contentType); }
    public static function timeout(float $seconds): PendingRequest { return self::pending()->timeout($seconds); }
    public static function connectTimeout(float $seconds): PendingRequest
    { return self::pending()->connectTimeout($seconds); }
    public static function withoutVerifying(): PendingRequest { return self::pending()->withoutVerifying(); }
    public static function caBundle(string $path): PendingRequest { return self::pending()->caBundle($path); }
    public static function followRedirects(int $maximum = 5): PendingRequest
    { return self::pending()->followRedirects($maximum); }
    public static function maxResponseBytes(int $bytes): PendingRequest
    { return self::pending()->maxResponseBytes($bytes); }
    public static function maxRequestBytes(int $bytes): PendingRequest
    { return self::pending()->maxRequestBytes($bytes); }
    public static function retry(int $attempts = 3, int $delay = 500): PendingRequest
    { return self::pending()->retry($attempts, $delay); }
    public static function withRetryPolicy(RetryPolicy $policy, bool $allowUnsafe = false): PendingRequest
    { return self::pending()->withRetryPolicy($policy, $allowUnsafe); }
    public static function sink(string $path): PendingRequest { return self::pending()->sink($path); }
    public static function sinkStream(mixed $stream): PendingRequest { return self::pending()->sinkStream($stream); }
    public static function get(string $url, array $query = []): HttpResponse
    { return self::pending()->get($url, $query); }
    public static function head(string $url, array $query = []): HttpResponse
    { return self::pending()->head($url, $query); }
    public static function options(string $url, array $query = []): HttpResponse
    { return self::pending()->options($url, $query); }
    public static function post(string $url, array|string|null $data = null): HttpResponse
    { return self::pending()->post($url, $data); }
    public static function put(string $url, array|string|null $data = null): HttpResponse
    { return self::pending()->put($url, $data); }
    public static function patch(string $url, array|string|null $data = null): HttpResponse
    { return self::pending()->patch($url, $data); }
    public static function delete(string $url, array|string|null $data = null): HttpResponse
    { return self::pending()->delete($url, $data); }
    public static function request(string $method, string $url, array|string|null $data = null): HttpResponse
    { return self::pending()->request($method, $url, $data); }

    public static function fake(array $routes): void { self::client()->fake($routes); }
    public static function resetFake(): void { self::client()->resetFake(); }
    public static function captured(): array { return self::client()->captured(); }
    /** Arrays produce a JSON fake; strings preserve their bytes. */
    public static function response(array|string $body = '', int $status = 200,
        array $headers = []): HttpResponse
    {
        if (is_array($body)) {
            try { $body = json_encode($body, JSON_THROW_ON_ERROR); }
            catch (JsonException $exception) {
                throw new \App\HttpClient\HttpConfigurationException('Fake JSON body is invalid.', 0, $exception);
            }
            $headers['content-type'] ??= ['application/json'];
        }
        $normalized = [];
        foreach ($headers as $name => $values) {
            $normalized[$name] = is_string($values) ? [$values] : $values;
        }
        return new HttpResponse($status, $body, $normalized);
    }
}
