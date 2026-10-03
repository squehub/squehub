<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Config\Repository;
use App\Container\Container;
use App\Diagnostics\Diagnostics;
use App\Events\EventDispatcher;
use App\Events\EventException;
use App\Events\StoppableEvent;
use App\Events\StopsEventPropagation;
use App\Http\Request;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Covers typed subscriptions, deterministic order, failure, and nested emission. */
final class EventDispatcherTest extends TestCase
{
    public function testClassListenerIsLazyContainerResolvedAndCallableObjectRuns(): void
    {
        EventTrace::$constructed = 0;
        $container = new Container();
        $trace = new EventTrace();
        $container->instance(EventTrace::class, $trace);
        $events = new EventDispatcher($container);
        $events->listen(EventMessage::class, TraceListener::class);
        $events->listen(EventMessage::class, TraceListener::class);
        $events->listen(EventMessage::class, static function (EventMessage $event) use ($trace): void {
            $trace->lines[] = 'closure:' . $event->message;
        });
        $events->listen(EventMessage::class, new EventCallable($trace));
        self::assertSame(0, EventTrace::$constructed);
        $message = new EventMessage('first');
        $events->emit($message);
        self::assertSame(2, EventTrace::$constructed);
        self::assertSame(['class:first', 'class:first', 'closure:first', 'callable:first'], $trace->lines);
        $trace->lines = [];
        $events->emit(new EventMessage('second'));
        self::assertSame(4, EventTrace::$constructed); // Normal Container resolution is transient.
        self::assertSame(['class:second', 'class:second', 'closure:second', 'callable:second'], $trace->lines);
    }

    public function testContainerSingletonBindingControlsListenerReuse(): void
    {
        EventTrace::$constructed = 0;
        $container = new Container();
        $trace = new EventTrace();
        $container->instance(EventTrace::class, $trace);
        $container->singleton(TraceListener::class);
        $events = new EventDispatcher($container);
        $events->listen(EventMessage::class, TraceListener::class);
        $events->emit(new EventMessage('one'));
        $events->emit(new EventMessage('two'));
        self::assertSame(1, EventTrace::$constructed);
        self::assertSame(['class:one', 'class:two'], $trace->lines);
    }

    public function testPriorityAndRegistrationOrderOverrideTypeSpecificity(): void
    {
        $events = new EventDispatcher(new Container());
        $order = [];
        $add = static function (string $type, string $label, int $priority = 0) use ($events, &$order): void {
            $events->listen($type, static function () use ($label, &$order): void {
                $order[] = $label;
            }, $priority);
        };
        $add(EventCategory::class, 'interface', 20);
        $add(ParentEvent::class, 'parent', 20);
        $add(ChildEvent::class, 'exact', 20);
        $add(ChildEvent::class, 'highest', 100);
        $add(ChildEvent::class, 'zero');
        $add(ChildEvent::class, 'negative', -50);
        $events->emit(new ChildEvent());
        self::assertSame(['highest', 'interface', 'parent', 'exact', 'zero', 'negative'], $order);
        $shared = static function () use (&$order): void { $order[] = 'shared'; };
        $events->listen(EventCategory::class, $shared);
        $events->listen(ParentEvent::class, $shared);
        $order = [];
        $events->emit(new ChildEvent());
        self::assertSame(2, count(array_filter($order, static fn (string $part): bool => $part === 'shared')));
    }

    public function testStoppableEventAndAlreadyStoppedEvent(): void
    {
        $diagnostics = new Diagnostics(new Repository());
        $diagnostics->begin(new Request());
        $events = new EventDispatcher(new Container(), $diagnostics);
        $order = [];
        $events->listen(StoppableMessage::class, static function () use (&$order): void { $order[] = 'A'; });
        $events->listen(StoppableMessage::class, static function (StoppableMessage $event) use (&$order): void {
            $order[] = 'B';
            $event->stopPropagation();
        });
        $events->listen(StoppableMessage::class, static function () use (&$order): void { $order[] = 'C'; });
        $events->emit(new StoppableMessage());
        self::assertSame(['A', 'B'], $order);
        $alreadyStopped = new StoppableMessage();
        $alreadyStopped->stopPropagation();
        $events->emit($alreadyStopped);
        self::assertSame(['A', 'B'], $order);
        $metric = $diagnostics->snapshot()['events'];
        self::assertSame(2, $metric['emitted']);
        self::assertSame(2, $metric['listener_invocations']);
        self::assertSame(2, $metric['stopped']);
        self::assertSame(0, $metric['failures']);
    }

    public function testListenerMutationAndReturnValueHaveNoDispatchMeaning(): void
    {
        $events = new EventDispatcher(new Container());
        $observed = null;
        $events->listen(EventMessage::class, static function (EventMessage $event): bool {
            $event->message = 'changed';
            return false;
        });
        $events->listen(EventMessage::class, static function (EventMessage $event) use (&$observed): void {
            $observed = $event;
        });
        $message = new EventMessage('before');
        self::assertNull($events->emit($message));
        self::assertSame($message, $observed);
        self::assertSame('changed', $message->message);
    }

    public function testListenerFailureRethrowsOriginalAndStopsLaterListeners(): void
    {
        $diagnostics = new Diagnostics(new Repository());
        $diagnostics->begin(new Request());
        $events = new EventDispatcher(new Container(), $diagnostics);
        $order = [];
        $failure = new RuntimeException('private event payload');
        $events->listen(EventMessage::class, static function () use (&$order): void { $order[] = 'A'; });
        $events->listen(EventMessage::class, static function () use (&$order, $failure): never {
            $order[] = 'B';
            throw $failure;
        });
        $events->listen(EventMessage::class, static function () use (&$order): void { $order[] = 'C'; });
        try {
            $events->emit(new EventMessage('secret-user'));
            self::fail('The original failure must escape.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame(['A', 'B'], $order);
        self::assertSame(1, $diagnostics->snapshot()['events']['failures']);
        self::assertSame(2, $diagnostics->snapshot()['events']['listener_invocations']);
        self::assertStringNotContainsString('secret-user', json_encode($diagnostics->snapshot()));
    }

    public function testBadRegistrationsAndUnresolvableClassListenerFailClearly(): void
    {
        $diagnostics = new Diagnostics(new Repository());
        $diagnostics->begin(new Request());
        $events = new EventDispatcher(new Container(), $diagnostics);
        foreach ([['Unknown\\MissingEvent', TraceListener::class],
            [EventMessage::class, 'Unknown\\MissingListener'],
            [EventMessage::class, MissingHandleListener::class],
            [EventMessage::class, NoEventParameterListener::class],
            [EventMessage::class, TwoEventParametersListener::class],
            [EventMessage::class, ScalarParameterListener::class]] as [$type, $listener]) {
            try {
                $events->listen($type, $listener);
                self::fail('Invalid registration should fail.');
            } catch (EventException) {
            }
        }
        $events->listen(EventMessage::class, UnresolvableListener::class);
        try {
            $events->emit(new EventMessage('private'));
            self::fail('Container failure should escape.');
        } catch (EventException $exception) {
            self::assertNotNull($exception->getPrevious());
            self::assertStringContainsString(UnresolvableListener::class, $exception->getMessage());
            self::assertStringNotContainsString('private', $exception->getMessage());
        }
        self::assertSame(1, $diagnostics->snapshot()['events']['failures']);
        self::assertSame(0, $diagnostics->snapshot()['events']['listener_invocations']);
    }

    public function testNestedEmissionAndRegistrationDuringDispatchUseStableSnapshot(): void
    {
        $events = new EventDispatcher(new Container());
        $order = [];
        $events->listen(EventMessage::class, static function (EventMessage $event) use ($events, &$order): void {
            $order[] = 'outer';
            $events->listen(EventMessage::class, static function () use (&$order): void { $order[] = 'late'; });
            $events->emit(new ChildEvent());
        });
        $events->listen(ChildEvent::class, static function () use (&$order): void { $order[] = 'inner'; });
        $events->emit(new EventMessage('first'));
        self::assertSame(['outer', 'inner'], $order);
        $events->emit(new EventMessage('second'));
        self::assertSame(['outer', 'inner', 'outer', 'inner', 'late'], $order);
    }

    public function testNoListenerIsValidAndScalarsFailTypedBoundary(): void
    {
        $diagnostics = new Diagnostics(new Repository());
        $diagnostics->begin(new Request());
        $events = new EventDispatcher(new Container(), $diagnostics);
        $events->emit(new EventMessage('no listener'));
        self::assertSame(1, $diagnostics->snapshot()['events']['emitted']);
        self::assertSame(0, $diagnostics->snapshot()['events']['listener_invocations']);
        $this->expectException(\TypeError::class);
        $events->emit('string-event');
    }
}

/** Mutable event used to verify same-instance delivery. */
final class EventMessage
{
    public function __construct(public string $message)
    {
    }
}

/** Shared trace dependency supplied by Container. */
final class EventTrace
{
    public static int $constructed = 0;
    /** @var list<string> */
    public array $lines = [];
}

/** Class listener proves constructor resolution happens at emission. */
final class TraceListener
{
    public function __construct(private EventTrace $trace)
    {
        ++EventTrace::$constructed;
    }

    public function handle(EventMessage $event): void
    {
        $this->trace->lines[] = 'class:' . $event->message;
    }
}

/** Explicitly passed invokable object follows callable semantics. */
final class EventCallable
{
    public function __construct(private EventTrace $trace)
    {
    }

    public function __invoke(EventMessage $event): void
    {
        $this->trace->lines[] = 'callable:' . $event->message;
    }
}

/** Interface grouping fixture. */
interface EventCategory
{
}

/** Parent type fixture. */
class ParentEvent
{
}

/** Child matching both parent class and interface subscriptions. */
final class ChildEvent extends ParentEvent implements EventCategory
{
}

/** Opt-in propagation fixture. */
final class StoppableMessage implements StoppableEvent
{
    use StopsEventPropagation;
}

/** An event-less handle method is invalid. */
final class NoEventParameterListener
{
    public function handle(): void
    {
    }
}

/** A class-string listener cannot rely on an implicit magic method. */
final class MissingHandleListener
{
}

/** Scalar handling is not an object-event listener contract. */
final class ScalarParameterListener
{
    public function handle(string $event): void
    {
    }
}

/** Method injection is deferred; constructor injection remains the contract. */
final class TwoEventParametersListener
{
    public function handle(EventMessage $event, EventTrace $trace): void
    {
    }
}

/** An unbound constructor interface must fail only when emitted. */
final class UnresolvableListener
{
    public function __construct(private EventMissingDependency $dependency)
    {
    }

    public function handle(EventMessage $event): void
    {
    }
}

/** Intentionally unbound dependency. */
interface EventMissingDependency
{
}
