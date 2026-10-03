<?php

declare(strict_types=1);

namespace App\Idempotency;

use App\Api\ApiError;
use App\Auth\AuthManager;
use App\Database\ModelClock;
use App\Http\Request;
use App\Http\Response;
use App\Routing\RouteDefinition;
use Closure;
use JsonException;
use Throwable;

/** Application-owned HTTP policy around one atomic store and bounded lease. */
final class IdempotencyManager
{
    public function __construct(
        private IdempotencyStore $store,
        private AuthManager $auth,
        private ModelClock $clock,
        private string $namespace,
        private int $leaseSeconds,
        private int $retentionSeconds,
        private int $maxRequestBytes,
        private int $maxResponseBytes,
        private string $backend
    ) {
    }

    public function backend(): string { return $this->backend; }

    /** Optional bounded physical cleanup; correctness never depends on a daemon. */
    public function prune(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new IdempotencyException('Invalid idempotency prune limit.');
        }
        return $this->store->prune($this->clock->now()->getTimestamp(), $limit);
    }

    /** @param Closure(Request):Response $next */
    public function process(Request $request, RouteDefinition $route, Closure $next): Response
    {
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new IdempotencyException('Idempotency is only for mutation routes.');
        }
        if (!$this->auth->hasDefaultGuard() || !$this->auth->check()) {
            throw ApiError::make('unauthenticated', 'Authentication is required.', 401);
        }
        $id = $this->auth->id();
        if ($id === null) throw ApiError::make('unauthenticated', 'Authentication is required.', 401);
        $key = $this->key($request);
        $scope = $this->scope($request, $route, $key, $id);
        $fingerprint = $this->fingerprint($request);
        $owner = bin2hex(random_bytes(32));
        $now = $this->clock->now()->getTimestamp();
        $claim = $this->store->claim($scope, $fingerprint, $owner, $now,
            $this->leaseSeconds, $this->retentionSeconds);
        if ($claim->state === 'conflict') {
            throw ApiError::make('idempotency_conflict', 'This idempotency key was used for a different request.', 409);
        }
        if ($claim->state === 'in_progress') {
            throw ApiError::make('idempotency_in_progress', 'This request is still being processed.',
                409, headers: ['Retry-After' => '1']);
        }
        if ($claim->state === 'unreplayable') {
            throw ApiError::make('idempotency_unreplayable', 'The completed result cannot be replayed.', 409);
        }
        if ($claim->state === 'replay') {
            return $claim->snapshot->response()
                ->withHeader('Cache-Control', 'no-store')
                ->withHeader('Idempotency-Replayed', 'true');
        }
        try {
            $response = $next($request);
        } catch (Throwable $failure) {
            try { $this->store->abandon($scope, $owner); } catch (Throwable) {}
            throw $failure;
        }
        if ($response->status() >= 400) {
            $this->store->abandon($scope, $owner);
            return $response;
        }
        $snapshot = ResponseSnapshot::capture($response, $this->maxResponseBytes);
        if (!$this->store->complete($scope, $owner, $this->clock->now()->getTimestamp(), $snapshot)) {
            throw new IdempotencyException('Idempotency claim expired before completion.');
        }
        return $response->withHeader('Cache-Control', 'no-store')
            ->withHeader('Idempotency-Replayed', 'false');
    }

    private function key(Request $request): string
    {
        $value = $request->header('Idempotency-Key');
        if ($request->headerRepeated('Idempotency-Key') || $request->headerConflict('Idempotency-Key')
            || !is_string($value) || strlen($value) < 8 || strlen($value) > 128
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._~-]{7,127}\z/D', $value) !== 1) {
            throw ApiError::make('invalid_idempotency_key', 'A valid Idempotency-Key header is required.', 400);
        }
        return $value;
    }

    private function scope(Request $request, RouteDefinition $route, string $key, int|string $id): string
    {
        $token = $this->auth->token();
        $identity = $token === null
            ? ['session', $this->auth->defaultGuardName(), (string) $id]
            : ['token', $token->identifier(), (string) $id];
        return hash('sha256', self::json([
            $this->namespace, $route->uri(), $request->host(), $request->path(),
            $request->method(), $identity, $key,
        ]));
    }

    private function fingerprint(Request $request): string
    {
        if ($request->files() !== [] || $request->headerRepeated('Content-Type')) {
            throw ApiError::make('idempotency_request_unsupported',
                'This request cannot be fingerprinted for idempotency.', 400);
        }
        $raw = $request->rawBody();
        if (strlen($raw) > $this->maxRequestBytes) {
            throw ApiError::make('idempotency_request_too_large',
                'This request is too large for idempotency.', 413);
        }
        $form = $raw === '' ? self::canonical($request->all()) : null;
        $query = self::canonical($request->query());
        $material = self::json([
            $request->method(), $request->path(), $request->contentType(),
            parse_url($request->uri(), PHP_URL_QUERY), $query,
            $raw === '' ? $form : hash('sha256', $raw),
        ]);
        if (strlen($material) > $this->maxRequestBytes) {
            throw ApiError::make('idempotency_request_too_large',
                'This request is too large for idempotency.', 413);
        }
        return hash('sha256', $material);
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            if ($value === null || is_scalar($value)) return $value;
            throw ApiError::make('idempotency_request_unsupported',
                'This request cannot be fingerprinted for idempotency.', 400);
        }
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = self::canonical($item);
        return $value;
    }

    private static function json(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw ApiError::make('idempotency_request_unsupported',
                'This request cannot be fingerprinted for idempotency.', 400);
        }
    }
}
