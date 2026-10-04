<?php

declare(strict_types=1);

namespace App\Diagnostics;

use App\Config\Repository;
use App\Http\Request;
use App\Http\Response;
use App\Observability\ObservabilityManager;
use App\Observability\CorrelationContext;
use Throwable;

/**
 * One Application's current HTTP measurements, reset for every Kernel call.
 *
 * Only operational metadata is retained. No Request object, parameter value,
 * header, session, SQL, binding, event name, or event payload is stored here.
 */
final class Diagnostics
{
    private ?ObservabilityManager $observability = null;
    private CorrelationContext $correlation;
    private ?string $requestId = null;
    private ?string $method = null;
    /** @var array{name:?string,pattern:string}|null */
    private ?array $route = null;
    private ?int $status = null;
    private ?int $startedAt = null;
    private bool $active = false;
    private ?float $finishedMs = null;
    private int $memoryBytes = 0;
    private int $peakMemoryBytes = 0;
    private int $queryCount = 0;
    private int $slowQueryCount = 0;
    private float $queryTimeMs = 0.0;
    private ?float $slowQueryThresholdMs = null;
    /** @var array<string, array{queries:int,slow_queries:int,time_ms:float}> */
    private array $connections = [];
    /** @var array{reads:int,hits:int,misses:int,writes:int,removals:int,time_ms:float} */
    private array $cache = ['reads' => 0, 'hits' => 0, 'misses' => 0,
        'writes' => 0, 'removals' => 0, 'time_ms' => 0.0];
    /** @var array{emitted:int,listener_invocations:int,stopped:int,failures:int,time_ms:float} */
    private array $events = ['emitted' => 0, 'listener_invocations' => 0,
        'stopped' => 0, 'failures' => 0, 'time_ms' => 0.0];
    /** Aggregate attempts only; file paths, drive names, and bytes are never retained. */
    private array $storage = ['operations' => 0, 'reads' => 0, 'writes' => 0,
        'removals' => 0, 'copies' => 0, 'moves' => 0, 'lists' => 0,
        'checks' => 0, 'failures' => 0, 'bytes_read' => 0, 'bytes_written' => 0, 'time_ms' => 0.0];
    /** Authentication retains counts only: no identity, credential, or timing data. */
    private array $auth = ['attempts' => 0, 'successes' => 0, 'failures' => 0,
        'logins' => 0, 'logouts' => 0, 'errors' => 0];
    /** Personal access token outcomes contain no token, identity, ability, or name metadata. */
    private array $tokenAuth = ['attempts' => 0, 'successes' => 0, 'failures' => 0,
        'issued' => 0, 'revoked' => 0, 'rotated' => 0, 'pruned' => 0, 'errors' => 0];
    /** Authorization retains outcomes only, never ability or subject metadata. */
    private array $authorization = ['checks' => 0, 'allowed' => 0, 'denied' => 0, 'errors' => 0];
    /** Account-security outcomes contain no identifier, guard, token, address, or secret. */
    private array $accountSecurity = ['password_changes' => 0, 'password_change_failures' => 0,
        'reset_tokens_issued' => 0, 'password_resets' => 0,
        'verification_tokens_issued' => 0, 'email_verifications' => 0, 'errors' => 0];
    /** Rate-limit measurements are aggregate only: no names, identities, or fingerprints. */
    private array $rateLimit = ['checks' => 0, 'allowed' => 0, 'denied' => 0,
        'clears' => 0, 'errors' => 0, 'time_ms' => 0.0];
    /** Mail measurements exclude addresses, subjects, bodies, and transport names. */
    private array $mail = ['attempts' => 0, 'sent' => 0, 'failures' => 0, 'time_ms' => 0.0];
    /** Dispatch totals carry no notification class, route, or payload. */
    private array $notifications = ['attempts' => 0, 'queued' => 0, 'sent' => 0, 'failures' => 0,
        'channel_deliveries' => 0, 'time_ms' => 0.0];
    /** Queue counters retain no job class, queue name, payload, or error text. */
    private array $queue = ['dispatched' => 0, 'processed' => 0, 'retried' => 0,
        'failed' => 0, 'errors' => 0, 'worker_starts' => 0, 'worker_stops' => 0,
        'timeouts' => 0, 'memory_exits' => 0, 'restart_exits' => 0, 'time_ms' => 0.0];
    /** Scheduler metrics retain no task name, job, occurrence, or exception. */
    private array $scheduler = ['evaluated' => 0, 'due' => 0, 'executed' => 0,
        'queued' => 0, 'skipped' => 0, 'failed' => 0, 'time_ms' => 0.0];
    /** Redis totals never retain keys, values, endpoints, connection names, or credentials. */
    private array $redis = ['operations' => 0, 'reads' => 0, 'writes' => 0,
        'failures' => 0, 'time_ms' => 0.0];
    /** Outbound HTTP retains counts and timing only, never URL, headers, or bytes. */
    private array $httpClient = ['requests' => 0, 'attempts' => 0, 'successful' => 0,
        'failed' => 0, 'retried' => 0, 'time_ms' => 0.0];
    /** Crypt records operations only, never key IDs, purposes, inputs, or envelopes. */
    private array $crypt = ['encryptions' => 0, 'decryptions' => 0, 'signatures' => 0,
        'verifications' => 0, 'failures' => 0, 'time_ms' => 0.0];
    /** Webhook counters contain no peer, URL, event ID, signature, or payload. */
    private array $webhooks = ['outgoing_events' => 0, 'delivery_attempts' => 0,
        'delivery_successes' => 0, 'delivery_failures' => 0, 'delivery_retries' => 0,
        'incoming_verified' => 0, 'incoming_rejected' => 0, 'incoming_duplicates' => 0];

    public function __construct(private Repository $config, ?CorrelationContext $correlation = null)
    {
        $this->correlation = $correlation ?? new CorrelationContext();
    }

    public function correlation(): CorrelationContext { return $this->correlation; }

    /** Connect the optional structured recorder without changing aggregate contracts. */
    public function setObservability(ObservabilityManager $observability): void
    {
        $this->observability = $observability;
    }

    public function observability(): ?ObservabilityManager { return $this->observability; }

    /** @internal Observe a framework boundary without retaining its inputs. */
    public function observe(string $operation, float $milliseconds, bool $failed = false,
        array $attributes = []): void
    {
        try { $this->observability?->record($operation, $milliseconds, $failed, $attributes); }
        catch (Throwable) { /* Telemetry must not alter an application result. */ }
    }

    /** @internal Preserve a callback result while recording an ordered child scope. */
    public function span(string $operation, callable $callback, array $attributes = []): mixed
    {
        return $this->observability === null
            ? $callback() : $this->observability->span($operation, $callback, $attributes);
    }

    /** Begin a fresh request even when one Application handles many requests. */
    public function begin(Request $request): void
    {
        $request->renewRequestId();
        $this->requestId = $request->requestId();
        // External X-Request-ID and X-Correlation-ID headers never become
        // authority for this Application's operation identity.
        $this->correlation->begin($this->requestId);
        $this->method = $request->method();
        $this->route = null;
        $this->status = null;
        $this->startedAt = hrtime(true);
        $this->active = true;
        $this->finishedMs = null;
        $this->memoryBytes = memory_get_usage(true);
        $this->peakMemoryBytes = memory_get_peak_usage(true);
        $this->queryCount = 0;
        $this->slowQueryCount = 0;
        $this->queryTimeMs = 0.0;
        $threshold = $this->config->get('diagnostics.slow_query_ms');
        $this->slowQueryThresholdMs = (is_int($threshold) || is_float($threshold))
            && is_finite((float) $threshold) && $threshold >= 0
            ? (float) $threshold : null;
        $this->connections = [];
        $this->cache = ['reads' => 0, 'hits' => 0, 'misses' => 0,
            'writes' => 0, 'removals' => 0, 'time_ms' => 0.0];
        $this->events = ['emitted' => 0, 'listener_invocations' => 0,
            'stopped' => 0, 'failures' => 0, 'time_ms' => 0.0];
        $this->storage = ['operations' => 0, 'reads' => 0, 'writes' => 0,
            'removals' => 0, 'copies' => 0, 'moves' => 0, 'lists' => 0,
            'checks' => 0, 'failures' => 0, 'bytes_read' => 0, 'bytes_written' => 0, 'time_ms' => 0.0];
        $this->auth = ['attempts' => 0, 'successes' => 0, 'failures' => 0,
            'logins' => 0, 'logouts' => 0, 'errors' => 0];
        $this->tokenAuth = ['attempts' => 0, 'successes' => 0, 'failures' => 0,
            'issued' => 0, 'revoked' => 0, 'rotated' => 0, 'pruned' => 0, 'errors' => 0];
        $this->authorization = ['checks' => 0, 'allowed' => 0, 'denied' => 0, 'errors' => 0];
        $this->accountSecurity = ['password_changes' => 0, 'password_change_failures' => 0,
            'reset_tokens_issued' => 0, 'password_resets' => 0,
            'verification_tokens_issued' => 0, 'email_verifications' => 0, 'errors' => 0];
        $this->rateLimit = ['checks' => 0, 'allowed' => 0, 'denied' => 0,
            'clears' => 0, 'errors' => 0, 'time_ms' => 0.0];
        $this->mail = ['attempts' => 0, 'sent' => 0, 'failures' => 0, 'time_ms' => 0.0];
        $this->notifications = ['attempts' => 0, 'queued' => 0, 'sent' => 0, 'failures' => 0,
            'channel_deliveries' => 0, 'time_ms' => 0.0];
        $this->queue = ['dispatched' => 0, 'processed' => 0, 'retried' => 0,
            'failed' => 0, 'errors' => 0, 'worker_starts' => 0, 'worker_stops' => 0,
            'timeouts' => 0, 'memory_exits' => 0, 'restart_exits' => 0, 'time_ms' => 0.0];
        $this->scheduler = ['evaluated' => 0, 'due' => 0, 'executed' => 0,
            'queued' => 0, 'skipped' => 0, 'failed' => 0, 'time_ms' => 0.0];
        $this->redis = ['operations' => 0, 'reads' => 0, 'writes' => 0,
            'failures' => 0, 'time_ms' => 0.0];
        $this->httpClient = ['requests' => 0, 'attempts' => 0, 'successful' => 0,
            'failed' => 0, 'retried' => 0, 'time_ms' => 0.0];
        $this->crypt = ['encryptions' => 0, 'decryptions' => 0, 'signatures' => 0,
            'verifications' => 0, 'failures' => 0, 'time_ms' => 0.0];
        $this->webhooks = ['outgoing_events' => 0, 'delivery_attempts' => 0,
            'delivery_successes' => 0, 'delivery_failures' => 0, 'delivery_retries' => 0,
            'incoming_verified' => 0, 'incoming_rejected' => 0, 'incoming_duplicates' => 0];
    }

    /** @internal Record a safe aggregate event without retaining its inputs. */
    public function auth(string $counter): void
    {
        if ($this->active && array_key_exists($counter, $this->auth)) ++$this->auth[$counter];
    }

    /** @internal Count token outcomes only; raw and stored credentials never enter Diagnostics. */
    public function tokenAuth(string $counter, int $amount = 1): void
    {
        if ($this->active && $amount > 0 && array_key_exists($counter, $this->tokenAuth)) {
            $this->tokenAuth[$counter] += $amount;
        }
    }

    /** @internal Count outcomes without retaining identity, ability, or subject data. */
    public function authorization(string $counter): void
    {
        if ($this->active && array_key_exists($counter, $this->authorization)) {
            ++$this->authorization[$counter];
        }
    }

    /** @internal Count outcomes only; no account or token metadata is retained. */
    public function accountSecurity(string $counter): void
    {
        if ($this->active && array_key_exists($counter, $this->accountSecurity)) {
            ++$this->accountSecurity[$counter];
        }
    }

    /** @internal Record only a rate-limit outcome, never the rule or subject. */
    public function rateLimit(string $counter): void
    {
        if ($this->active && $counter !== 'time_ms' && array_key_exists($counter, $this->rateLimit)) {
            ++$this->rateLimit[$counter];
        }
    }

    /** @internal Backend time excludes resolver and controller execution. */
    public function rateLimitTime(float $milliseconds): void
    {
        if ($this->active) $this->rateLimit['time_ms'] += max(0.0, $milliseconds);
    }

    /** @internal Count send outcomes without retaining message metadata. */
    public function mail(string $counter): void
    {
        if ($counter === 'sent' || $counter === 'failures') {
            $this->observe('mail.' . $counter, 0.0, $counter === 'failures');
        }
        if ($this->active && $counter !== 'time_ms' && array_key_exists($counter, $this->mail)) {
            ++$this->mail[$counter];
        }
    }

    /** @internal Transport time excludes application message construction. */
    public function mailTime(float $milliseconds): void
    {
        $this->observe('mail.transport', $milliseconds);
        if ($this->active) $this->mail['time_ms'] += max(0.0, $milliseconds);
    }

    /** @internal Count dispatch outcomes only, never recipients or content. */
    public function notification(string $counter): void
    {
        if ($counter === 'sent' || $counter === 'failures' || $counter === 'queued') {
            $this->observe('notification.' . $counter, 0.0, $counter === 'failures');
        }
        if ($this->active && $counter !== 'time_ms' && array_key_exists($counter, $this->notifications)) {
            ++$this->notifications[$counter];
        }
    }

    /** @internal Measures synchronous channel selection and delivery. */
    public function notificationTime(float $milliseconds): void
    {
        $this->observe('notification.dispatch', $milliseconds);
        if ($this->active) $this->notifications['time_ms'] += max(0.0, $milliseconds);
    }

    /** @internal Queue outcomes are process-local and aggregate only. */
    public function queue(string $counter): void
    {
        if (in_array($counter, ['processed', 'retried', 'failed', 'errors'], true)) {
            $this->observe('queue.' . $counter, 0.0, $counter === 'failed' || $counter === 'errors');
        }
        if ($this->active && $counter !== 'time_ms' && array_key_exists($counter, $this->queue)) {
            ++$this->queue[$counter];
        }
    }

    /** @internal Measures dispatch duration; sync dispatch includes job execution. */
    public function queueTime(float $milliseconds, string $queue = '',
        string $connection = '', bool $failed = false): void
    {
        $this->observe('queue.dispatch', $milliseconds, $failed,
            ['queue' => $queue, 'connection' => $connection]);
        if ($this->active) $this->queue['time_ms'] += max(0.0, $milliseconds);
    }

    /** @internal Observe schedule outcomes; retain request aggregates only during HTTP. */
    public function scheduler(string $counter): void
    {
        if (in_array($counter, ['due', 'claimed', 'executed', 'queued', 'skipped', 'failed'], true)) {
            $this->observe('scheduler.' . $counter, 0.0, $counter === 'failed');
        }
        if ($this->active && $counter !== 'time_ms' && array_key_exists($counter, $this->scheduler)) {
            ++$this->scheduler[$counter];
        }
    }

    /** @internal Measure one scheduler tick, including synchronous calls. */
    public function schedulerTime(float $milliseconds): void
    {
        $this->observe('scheduler.run', $milliseconds);
        if ($this->active) $this->scheduler['time_ms'] += max(0.0, $milliseconds);
    }

    /** @internal Count actual command attempts, including failed connections. */
    public function redis(string $kind, float $milliseconds, bool $failed): void
    {
        $this->observe('redis.' . ($kind === 'read' ? 'read' : 'write'),
            $milliseconds, $failed);
        if (!$this->active) return;
        ++$this->redis['operations'];
        if ($kind === 'read') ++$this->redis['reads'];
        if ($kind === 'write') ++$this->redis['writes'];
        if ($failed) ++$this->redis['failures'];
        $this->redis['time_ms'] += max(0.0, $milliseconds);
    }

    /** @internal One request may have several attempts, including redirects and retries. */
    public function httpClient(string $counter): void
    {
        // Fixed operation names expose retry volume without URL or host labels.
        if ($counter === 'attempts' || $counter === 'retried') {
            $this->observe($counter === 'attempts' ? 'http_client.attempt' : 'http_client.retry', 0.0);
        }
        if ($this->active && $counter !== 'time_ms' && array_key_exists($counter, $this->httpClient)) {
            ++$this->httpClient[$counter];
        }
    }

    /** @internal Measure logical request duration, including deliberate retry waits. */
    public function httpClientTime(float $milliseconds, string $method = 'OTHER',
        ?int $status = null, bool $failed = false, string $result = 'success'): void
    {
        // Method and status class are bounded; URLs, hosts, redirects, and
        // headers can contain application-controlled identities or secrets.
        $method = in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true)
            ? $method : 'OTHER';
        // The result is a fixed category, never an exception class or message.
        $result = in_array($result, ['success', 'http_status', 'connection_error', 'client_error'], true)
            ? $result : 'client_error';
        $this->observe('http_client.request', $milliseconds, $failed,
            ['method' => $method, 'status_class' => $status === null ? 'none' : intdiv($status, 100) . 'xx',
                'result' => $result]);
        if ($this->active) $this->httpClient['time_ms'] += max(0.0, $milliseconds);
    }

    /** @internal Broadcast timing excludes channels, recipients, and payload. */
    public function broadcastTime(string $operation, float $milliseconds, bool $failed,
        string $transport = '', string $category = 'unknown', string $mode = 'unknown'): void
    {
        if (in_array($operation, ['enqueue', 'publish'], true)) {
            $this->observe('broadcast.' . $operation, $milliseconds, $failed,
                ['transport' => $transport, 'channel_category' => $category, 'mode' => $mode]);
        }
    }

    /** @internal Count successful operations and exceptional failures only. */
    public function crypt(string $counter): void
    {
        if ($this->active && $counter !== 'time_ms' && array_key_exists($counter, $this->crypt)) {
            ++$this->crypt[$counter];
        }
    }

    /** @internal Measure primitive and validation time without retaining inputs. */
    public function cryptTime(float $milliseconds): void
    {
        if ($this->active) $this->crypt['time_ms'] += max(0.0, $milliseconds);
    }

    /** @internal Record only a webhook outcome; never retain peer or delivery data. */
    public function webhook(string $counter): void
    {
        if ($this->active && array_key_exists($counter, $this->webhooks)) {
            ++$this->webhooks[$counter];
        }
    }

    /** @internal The router reports the registered pattern, never parameter values. */
    public function matched(?string $name, string $pattern): void
    {
        if ($this->active) $this->route = ['name' => $name, 'pattern' => $pattern];
        try { $this->observability?->annotateRoot(['route_pattern' => $pattern,
            'route_name' => $name]); }
        catch (Throwable) { /* Route matching remains authoritative. */ }
    }

    /** @internal Count attempted SQL statements; transaction controls are separate. */
    public function query(string $connection, float $milliseconds, string $operation = 'other',
        string $driver = '', bool $failed = false): void
    {
        $this->observe('db.query', $milliseconds, $failed,
            ['connection' => $connection, 'driver' => $driver, 'operation' => $operation]);
        if (!$this->active || $this->config->get('diagnostics.database', true) !== true) return;
        ++$this->queryCount;
        $duration = max(0.0, $milliseconds);
        $this->queryTimeMs += $duration;
        $entry = $this->connections[$connection] ?? ['queries' => 0, 'slow_queries' => 0,
            'time_ms' => 0.0];
        ++$entry['queries'];
        if ($this->slowQueryThresholdMs !== null && $duration >= $this->slowQueryThresholdMs) {
            ++$this->slowQueryCount;
            ++$entry['slow_queries'];
        }
        $entry['time_ms'] += $duration;
        $this->connections[$connection] = $entry;
    }

    /** @internal Aggregate successful cache activity, never keys, values, or filenames. */
    public function cache(string $operation, float $milliseconds, ?bool $hit = null): void
    {
        $this->observe('cache.' . $operation, $milliseconds, false,
            ['result' => $hit === null ? 'unknown' : ($hit ? 'hit' : 'miss')]);
        if (!$this->active) return;
        $this->cache['time_ms'] += max(0.0, $milliseconds);
        if (in_array($operation, ['read', 'take', 'remember'], true)) {
            ++$this->cache['reads'];
            if ($hit === true) ++$this->cache['hits'];
            elseif ($hit === false) ++$this->cache['misses'];
        }
        if ($operation === 'write' || ($operation === 'remember' && $hit === false)) {
            ++$this->cache['writes'];
        }
        if (($operation === 'remove' || $operation === 'take') && $hit === true) {
            ++$this->cache['removals'];
        }
    }

    /** @internal Aggregates keep no type; optional spans may name the bounded PHP class. */
    public function event(int $invocations, bool $stopped, bool $failed, float $milliseconds,
        string $eventType = ''): void
    {
        $this->observe('event.emit', $milliseconds, $failed,
            ['result' => $stopped ? 'stopped' : 'completed', 'event_type' => $eventType]);
        if (!$this->active) return;
        ++$this->events['emitted'];
        $this->events['listener_invocations'] += $invocations;
        if ($stopped) ++$this->events['stopped'];
        if ($failed) ++$this->events['failures'];
        $this->events['time_ms'] += max(0.0, $milliseconds);
    }

    /** @internal Count driver attempts; successful byte totals exclude failed writes. */
    public function storage(string $operation, float $milliseconds, bool $failed, int $bytes = 0): void
    {
        $this->observe('storage.' . $operation, $milliseconds, $failed);
        if (!$this->active) return;
        ++$this->storage['operations'];
        $key = ['read' => 'reads', 'write' => 'writes', 'remove' => 'removals',
            'copy' => 'copies', 'move' => 'moves', 'list' => 'lists', 'check' => 'checks'][$operation] ?? null;
        if ($key !== null) ++$this->storage[$key];
        if ($failed) ++$this->storage['failures'];
        elseif ($operation === 'read') $this->storage['bytes_read'] += $bytes;
        elseif ($operation === 'write') $this->storage['bytes_written'] += $bytes;
        $this->storage['time_ms'] += max(0.0, $milliseconds);
    }

    /** Finish at response production; sending bytes to the client is outside timing. */
    public function finish(Response $response): Response
    {
        if ($this->startedAt === null) return $response;
        $this->status = $response->status();
        $this->finishedMs = (hrtime(true) - $this->startedAt) / 1_000_000;
        $this->active = false;
        $this->memoryBytes = memory_get_usage(true);
        $this->peakMemoryBytes = memory_get_peak_usage(true);
        if ($this->config->get('diagnostics.response_header', true) === true) {
            // The framework-generated value is authoritative even when a
            // controller supplied its own X-Request-ID response header.
            return $response->withHeader('X-Request-ID', (string) $this->requestId)
                ->withHeader('X-Correlation-ID', (string) $this->requestId);
        }
        return $response;
    }

    public function requestId(): ?string { return $this->requestId; }
    public function queryCount(): int { return $this->queryCount; }
    public function queryTimeMs(): float { return $this->queryTimeMs; }
    public function elapsedMs(): ?float
    {
        if ($this->startedAt === null) return null;
        return $this->finishedMs ?? (hrtime(true) - $this->startedAt) / 1_000_000;
    }

    /**
     * Safe immutable-by-value shape for logs, tests, and later diagnostics UI.
     *
     * @return array<string, mixed> Aggregate operational measurements only.
     */
    public function snapshot(): array
    {
        $background = !$this->active && $this->correlation->current() !== null;
        $connections = [];
        foreach ($this->connections as $name => $entry) {
            $connections[$name] = ['queries' => $entry['queries'],
                'slow_queries' => $entry['slow_queries'], 'time_ms' => $entry['time_ms']];
        }
        return [
            'request_id' => $background ? null : $this->requestId,
            'correlation_id' => $this->correlation->current() ?? $this->requestId,
            'method' => $background ? null : $this->method,
            'route' => $background ? null : $this->route,
            'status' => $background ? null : $this->status,
            'elapsed_ms' => $this->elapsedMs(),
            'memory_bytes' => $this->memoryBytes,
            'peak_memory_bytes' => $this->peakMemoryBytes,
            'database' => ['queries' => $this->queryCount, 'slow_queries' => $this->slowQueryCount,
                'slow_query_threshold_ms' => $this->slowQueryThresholdMs, 'time_ms' => $this->queryTimeMs,
                'connections' => $connections],
            'cache' => $this->cache,
            'events' => $this->events,
            'storage' => $this->storage,
            'auth' => $this->auth,
            'token_auth' => $this->tokenAuth,
            'authorization' => $this->authorization,
            'account_security' => $this->accountSecurity,
            'rate_limit' => $this->rateLimit,
            'mail' => $this->mail,
            'notifications' => $this->notifications,
            'queue' => $this->queue,
            'scheduler' => $this->scheduler,
            'redis' => $this->redis,
            'http_client' => $this->httpClient,
            'crypt' => $this->crypt,
            'webhooks' => $this->webhooks,
            'observability' => $this->observability?->status() ?? [
                'configured' => false, 'enabled' => false, 'exporter' => 'none',
                'last_failure_category' => null, 'export_failures' => 0,
                'consumer_failures' => 0, 'dropped_metrics' => 0,
            ],
        ];
    }

    /** Framework-owned log fields cannot be replaced by developer context. */
    public function logContext(): array
    {
        $correlation = $this->correlation->current();
        if ($this->active && $this->requestId !== null) {
            return ['request_id' => $this->requestId, 'correlation_id' => $correlation,
                'method' => $this->method, 'route' => $this->route];
        }
        // A finished HTTP snapshot stays inspectable, but a later CLI or
        // worker log must never inherit its stale request metadata.
        return $correlation === null ? [] : ['correlation_id' => $correlation];
    }
}
