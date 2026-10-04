# SqueHub v2 events and listeners

Optional [Observability](Observability.md) measures Event dispatch and listener execution using bounded metadata. The [development profiler](Profiler.md) records completed operation timings, while [Studio](Studio.md) can display subscriptions already registered in memory without dispatching an Event. Event objects and payloads are not copied into these inspection surfaces.

`events()` resolves the `App\Events\EventDispatcher` owned by the booted Application. `listen()` registers a typed subscription and remains **synchronous**. `listenQueued()` explicitly schedules one class listener through the existing Queue. Any PHP object may be emitted to ordinary listeners; queued delivery requires an explicit payload contract. Strings, arrays, and scalar event names are outside the API.

Internal Events do not automatically become [outgoing Webhooks](Webhooks.md) or [Broadcast messages](Broadcasting.md), and verified incoming Webhook type strings are not automatically emitted into the Events bus. Application code deliberately maps between these boundaries and selects data safe to publish.

```php
namespace Project\Events;

final class UserRegistered
{
    public function __construct(public readonly int $userId) {}
}
```

```php
namespace Project\Listeners;

use Project\Events\UserRegistered;

final class SendWelcomeEmail
{
    public function __construct(private WelcomeMailer $mailer) {}

    public function handle(UserRegistered $event): void
    {
        $this->mailer->sendToUser($event->userId);
    }
}
```

Register once during Application boot, for example in a package or application Service Provider:

```php
use App\Plugins\Event;

Event::listen(UserRegistered::class, SendWelcomeEmail::class);
```

Application code can use `events()->listen(UserRegistered::class, SendWelcomeEmail::class)` after bootstrap, then `events()->emit(new UserRegistered($user->id))`. The `Project/Events` and `Project/Listeners` directories are optional organization conventions under Composer's existing `Project\` namespace; SqueHub does not scan them. Register long-lived listeners during boot. Registrations made during an HTTP request persist if the same Application handles later requests.

Class-string listeners are checked for an instantiable class and a public `handle()` with one event parameter. Their constructors resolve through SqueHub's Container only when a matching event is emitted. Container bindings decide whether a listener instance is transient or shared. An explicitly passed closure, callable array, or invokable object is called directly; class-string listeners use `handle()`. Listener return values are ignored, including `false`. `emit()` returns `void` and delivers the **same event object** to each listener, so deliberate mutation is visible to later listeners.

## Configuration and order

`Config/Events.php` has one explicit listener map. A typical application configuration is:

```php
return [
    'listeners' => [
        UserRegistered::class => [
            ['listener' => SecurityAuditListener::class, 'priority' => 100],
            SendWelcomeEmail::class,
        ],
    ],
];
```

An entry may be a listener class string or `['listener' => Listener::class, 'priority' => 10]`; omitted priority is zero. A queued entry may add `'queued' => true`, `'after_commit' => true`, `'queue' => 'default'`, `'connection' => null`, `'delay' => 0`, and `'transaction_connection' => null`. Queue options on an ordinary listener are rejected. Invalid event types, listeners, priority values, and shapes fail during Application boot. Configuration listeners register when `EventServiceProvider` boots; providers booted later can add listeners programmatically. No listener is constructed merely by registration.

Higher integer priority runs first, including negative values. Equal-priority subscriptions run in registration order. Matching includes the event's exact class, parent classes, and implemented interfaces; type specificity does not change the order. Duplicate registrations and multiple matching subscriptions each invoke the listener once per registration. A stable matching snapshot is taken before delivery: a listener registered during an emission first applies to a later emission. Nested emissions are allowed; the dispatcher does not detect recursive business logic.

## Stopping and failures

An event may opt into stopping propagation by implementing `App\Plugins\StoppableEvent`. The supplied `App\Plugins\StopsEventPropagation` trait provides `stopPropagation()` and `propagationStopped()`:

```php
final class ReviewRequested implements \App\Plugins\StoppableEvent
{
    use \App\Plugins\StopsEventPropagation;
}
```

After a listener calls `$event->stopPropagation()`, that listener finishes and later listeners are skipped. If the event is already stopped before `emit()`, none run. Other event objects are not stoppable through listener return values.

A listener exception stops delivery and escapes unchanged. Failure to resolve a class listener through the Container raises `EventException` with the underlying cause retained. An uncaught HTTP failure reaches the existing exception handler and logger once; the event subsystem does not automatically log emissions or payloads. Event properties may contain private business data. Avoid retaining raw secrets without need; SqueHub diagnostics never serializes event or listener properties or names.

`diagnostics()->snapshot()['events']` reports request-scoped `emitted`, `listener_invocations`, `stopped`, `failures`, and `time_ms`. Event time includes synchronous listener work, unlike cache timing, which excludes the `remember()` callback. Counts reset for each Kernel request; listener registrations remain on the Application. CLI event emission works without a request, session, database, cache, or logger.

## Queued class listeners

Queued listeners opt in at registration. The event implements `App\Plugins\QueueableEvent` so it defines a JSON-safe snapshot; the listener remains a normal Container-resolved class with one public `handle()` method. `App\Plugins\ShouldQueue` belongs to Notifications and is not the event-listener contract.

```php
namespace Project\Events;

use App\Plugins\QueueableEvent;

final class InvoicePaid implements QueueableEvent
{
    public function __construct(public readonly int $invoiceId) {}

    public function toQueuePayload(): array
    {
        return ['invoice_id' => $this->invoiceId];
    }

    public static function fromQueuePayload(array $payload): static
    {
        return new static((int) $payload['invoice_id']);
    }
}
```

```php
use App\Plugins\Event;
use Project\Events\InvoicePaid;
use Project\Listeners\SendInvoiceReceipt;

Event::listenQueued(InvoicePaid::class, SendInvoiceReceipt::class,
    priority: 10, queue: 'mail', connection: 'database');
Event::emit(new InvoicePaid($invoiceId));
```

`events()->listenQueued(...)` is the equivalent manager API. The `Queue` provider must be registered. Queued closures, callable objects, and callable arrays are rejected because a later process cannot safely reconstruct them. The worker resolves the selected class through its own Application Container and calls only that class's `handle()`; it does not re-emit the event. Listener registration remains Application-scoped and is never stored in the Queue job. The job stores a version, concrete event class, listener class, and the event's explicit data. It uses the same bounded 60,000-byte Queue JSON codec as application jobs, never native PHP serialization. Store scalar Model identifiers and reload through your application code when needed; entire Models, Request objects, and object graphs are not automatically serialized. Queue records can contain sensitive application data and require the same protection as other Queue payloads.

Subscriptions still form one stable priority-ordered snapshot. When a queued subscription is reached, its event payload is captured **at that position**, after earlier synchronous mutations. Scheduling follows priority and registration order; asynchronous execution order across workers is not guaranteed. A synchronous listener that stops propagation before that position prevents scheduling. Once scheduled, a later synchronous stop cannot retract the job; the queued listener receives a reconstructed event and cannot stop the original emission. Queued work does not contribute to `events.listener_invocations`, which counts calls made during the original emission. Queue diagnostics count dispatch, processing, retry, and failure without recording payloads.

Queued registration defaults to `afterCommit: true`. Inside a SqueHub-managed database transaction, dispatch waits for the outermost commit; a rolled-back transaction or nested savepoint discards its pending job. Without a Database provider or an active managed transaction, dispatch happens immediately. `afterCommit: false` explicitly dispatches at the listener's position, even inside a transaction. The queue connection and Queue name are distinct from the optional `transactionConnection` whose commit is observed. Queue's `sync` connection executes the listener immediately (or after commit when deferred); a Sync delay does not wait. A post-commit Queue persistence failure is reported after business data has committed and cannot undo that commit. Raw PDO transactions outside SqueHub's Connection cannot register after-commit callbacks.

The same Queue worker handles queued listeners. It owns attempts, backoff, and failed-job recording. A persistent queued-listener failure happens after the original `emit()` has returned and cannot retroactively fail that request. Queue is **at least once**: a worker crash or retry can run a listener again, so external side effects should be idempotent. A Sync listener exception can still escape the original emission because Sync executes immediately. Queue failure metadata and diagnostics omit event payloads, though the persisted Queue record necessarily contains the explicit data.

Ordinary synchronous Events emitted inside a caller-owned database transaction still run **inside that transaction**. If one throws, the existing transaction wrapper decides rollback; the dispatcher never commits or rolls back itself.

An application can deliberately place an [explicit typed payload envelope](ApplicationData.md#explicit-typed-queue-payloads) inside a selected `QueueableEvent::toQueuePayload()` array and decode it through its registered `TypedPayloadRegistry` in worker-owned code. This opt-in composition does not alter `listenQueued()`, the existing QueueableEvent reconstruction contract, after-commit behavior, or the 60,000-byte Queue limit. Ordinary in-process Events still pass the original object directly and require no typed serialization. Type aliases and versions must be registered by trusted application boot on both sides of a persistent Queue boundary; Queue retries remain at least once.

Typed Event payloads use the same explicit alias, version, and reconstruction rules as other durable payloads. See [typed application data](ApplicationData.md) for the contract.

This foundation does not include event sourcing, automatic Model lifecycle event queueing, listener discovery, event/listener generators, wildcard string events, listener removal, one-time listeners, or an `event:list` command.

## Application-facing Plugins import

Application code may import `App\Plugins\Event`, `App\Plugins\QueueableEvent`, `App\Plugins\StoppableEvent`, and `App\Plugins\StopsEventPropagation`. These entries delegate to the current subsystem; canonical imports and global helpers remain supported. See [Plugins](Plugins.md) for the complete mapping and compatibility rules.
