# Circuit breaker

SqueHub provides an **opt-in, single-server file circuit breaker** for a named external dependency. It guards one logical application call. Ordinary SqueHub applications need no Redis, database table, or circuit configuration, and merely booting the provider does not create files. The Application owns one `App\Plugins\CircuitBreaker` manager; `App\Plugins\CircuitPolicy`, `CircuitOpenException`, and `CircuitException` are exact aliases of their `App\Reliability` counterparts.

```php
use App\HttpClient\HttpConnectionException;
use App\Plugins\{CircuitBreaker, CircuitOpenException, CircuitPolicy};

$breaker = $app->container()->make(CircuitBreaker::class);
$policy = new CircuitPolicy(
    failureThreshold: 3,
    cooldownSeconds: 30,
    probeLeaseSeconds: 10,
    failureExceptions: [HttpConnectionException::class],
    failureHttpStatuses: [502, 503, 504],
);

try {
    $response = $breaker->run(
        'billing-api',
        $policy,
        fn () => $http->pending()->get('https://billing.example.test/status'),
    );
} catch (CircuitOpenException $open) {
    // The HTTP operation was not invoked by this call.
    $retryAfter = $open->retryAfterSeconds();
}
```

Choose a fixed logical name for each dependency, not a request-supplied URL, account ID, or secret. Names start with a letter, contain only letters, digits, dots, underscores, colons, or hyphens, and are at most 128 bytes. Their SHA-256 fingerprints, not the names, reach state filenames. The default root is private `Storage/Circuits` beneath the Application. Separate Application roots have separate state; cooperating processes using one root share it.

## State and failure rules

The breaker counts **consecutive completed logical calls** classified as failures. A selected `App\HttpClient\HttpResponse` status or a selected exception class counts. A selected `HttpStatusException` status also counts. Other returned values reset the count. An unselected exception propagates without consuming a failure slot in the closed state; it cannot prove health when thrown by the half-open probe. No arbitrary application exception is counted automatically. The policy requires at least one selected exception class or HTTP status and bounds the threshold, cooldown, and probe lease.

After the threshold, the state opens for the configured cooldown. An open call throws `CircuitOpenException` with a bounded `retryAfterSeconds()` and does not invoke the operation. Once cooldown passes, one caller atomically claims a half-open probe. A successful probe closes the circuit and starts a fresh generation; a classified failure reopens it. An unselected probe exception also reopens it for cooldown without incrementing the failure counter, because health was not established. A crash leaves the probe claim until its bounded lease expires.

Use the same policy for a circuit name across cooperating processes. If its policy changes, an already-open cooldown or active probe lease keeps the deadline recorded by the earlier policy; newly admitted calls use their supplied policy for later transitions. Simultaneous calls with different thresholds or classifiers settle in completion order, so do not use a name for competing policies. To start fresh after a deliberate configuration change, drain in-flight calls and then call `clear(name)`.

The file store keeps a strict versioned JSON record and a separate per-key `flock` file. Read, claim, and completion transitions occur under that lock; a private temporary file is flushed and atomically renamed to replace state. Corrupt state and storage failures throw `CircuitException` before admitting a new operation. `$breaker->clear('billing-api')` explicitly removes even a corrupt record under the lock; use it only when intentionally discarding the circuit's history. State files persist until cleared, including closed records, so earlier in-flight generations cannot update a circuit that recovered. Use a finite set of trusted dependency names.

Protect `Storage/Circuits` from untrusted local writers. New directories and state files request owner-only POSIX permissions; Windows inherits its parent ACL, so restrict that ACL where multiple local users can access the Application.

## Retries and side effects

Call `run()` around the **whole logical operation**. If the callback uses HTTP Client's explicit retry policy, the breaker sees its final response or exception once; it does not count each transport attempt separately. Queue and Webhook delivery keep their own retry and acknowledgement rules. Do not layer several retry loops around the same call or assume a timeout means the remote side did nothing. A circuit limits pressure on a failing peer; it provides no request deduplication or provider-side idempotency.

## Guarantees and limits

- **Protected resource and owner:** one named dependency's admission and failure state, coordinated by private files and OS `flock` on one server. The callback runs outside the state lock.
- **State lifetime and expiry:** the closed failure count lasts until success or explicit clear. Open cooldown and half-open probe leases are bounded by the supplied policy. Files remain for stale-generation protection and can be cleared deliberately.
- **Crash and duplicate behavior:** a crashed half-open probe becomes claimable after its lease. One cooperating process can hold the current probe token at a time. Token checks stop a late probe from overwriting newer state, but an old process may still perform external work after lease expiry.
- **Backend outage and ambiguous completion:** state read/write or corruption fails closed with `CircuitException`. A settlement failure takes precedence over a callback exception because the circuit can no longer attest to its state. If a state write fails after the callback completed, its external side effect may already have occurred; do not retry it blindly.
- **Local versus distributed:** the shipped file backend covers cooperating processes on one server with a local filesystem. It does not coordinate multiple servers, network filesystems, Redis, or a database. No fencing token is consumed by the external dependency.

The file circuit remains scoped to cooperating processes on one local server. Review the [public status](Status.md) and verify lock behavior and dependency recovery under your deployment topology.
