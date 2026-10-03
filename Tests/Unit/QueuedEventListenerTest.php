<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Container\Container;
use App\Events\EventDispatcher;
use App\Events\EventException;
use App\Events\Queue\DeliverEventListener;
use App\Events\QueueableEvent;
use App\Events\StoppableEvent;
use App\Events\StopsEventPropagation;
use App\Queue\QueueException;
use App\Queue\QueueManager;
use PHPUnit\Framework\TestCase;

/** Exercises queued scheduling without a database or persistent worker. */
final class QueuedEventListenerTest extends TestCase
{
    private function dispatcher(?Container $container = null): EventDispatcher
    {
        $container ??= new Container();
        $queue = new QueueManager(['default' => 'sync',
            'connections' => ['sync' => ['driver' => 'sync']]]);
        return new EventDispatcher($container, null, static fn (): QueueManager => $queue);
    }

    public function testClassListenerRunsThroughSyncQueueAndUsesPayloadSnapshot(): void
    {
        QueuedEventTrace::$values = [];
        $events = $this->dispatcher();
        $events->listen(QueuedEventSignal::class, static function (QueuedEventSignal $event): void {
            $event->value = 'before';
        }, 20);
        $events->listenQueued(QueuedEventSignal::class, QueuedEventRecorder::class, 10);
        $events->listen(QueuedEventSignal::class, static function (QueuedEventSignal $event): void {
            $event->value = 'after';
        });
        $event = new QueuedEventSignal('initial');
        $events->emit($event);
        self::assertSame(['before'], QueuedEventTrace::$values);
        self::assertSame('after', $event->value);
    }

    public function testStopBeforeQueuedSubscriptionPreventsScheduling(): void
    {
        QueuedEventTrace::$values = [];
        $events = $this->dispatcher();
        $events->listen(QueuedEventSignal::class, static function (QueuedEventSignal $event): void {
            $event->stopPropagation();
        }, 10);
        $events->listenQueued(QueuedEventSignal::class, QueuedEventRecorder::class);
        $events->emit(new QueuedEventSignal('stopped'));
        self::assertSame([], QueuedEventTrace::$values);
    }

    public function testQueuedListenerCannotStopOriginalPropagation(): void
    {
        QueuedEventTrace::$values = [];
        $events = $this->dispatcher();
        $events->listenQueued(QueuedEventSignal::class, QueuedEventStopper::class, 10);
        $events->listen(QueuedEventSignal::class, static function (): void {
            QueuedEventTrace::$values[] = 'later';
        });
        $event = new QueuedEventSignal('queued');
        $events->emit($event);
        self::assertSame(['queued', 'later'], QueuedEventTrace::$values);
        self::assertFalse($event->propagationStopped());
    }

    public function testEarlierQueuedSubscriptionStaysScheduledWhenLaterSyncListenerStops(): void
    {
        QueuedEventTrace::$values = [];
        $events = $this->dispatcher();
        $events->listenQueued(QueuedEventSignal::class, QueuedEventRecorder::class, 20);
        $events->listen(QueuedEventSignal::class, static function (QueuedEventSignal $event): void {
            $event->stopPropagation();
        }, 10);
        $events->listenQueued(QueuedEventSignal::class, QueuedEventRecorder::class, 0);
        $events->emit(new QueuedEventSignal('first-only'));
        self::assertSame(['first-only'], QueuedEventTrace::$values);
    }

    public function testQueuedRegistrationRejectsClosuresAndRequiresQueueService(): void
    {
        $events = $this->dispatcher();
        try {
            $events->listenQueued(QueuedEventSignal::class, static function (): void {});
            self::fail('Closure was accepted for queued delivery.');
        } catch (EventException $exception) {
            self::assertStringContainsString('class name', $exception->getMessage());
        }
        $withoutQueue = new EventDispatcher(new Container());
        $this->expectException(EventException::class);
        $withoutQueue->listenQueued(QueuedEventSignal::class, QueuedEventRecorder::class);
    }

    public function testQueuedEventRequiresExplicitPayloadAndRespectsCodecLimit(): void
    {
        $events = $this->dispatcher();
        $events->listenQueued(PlainQueuedSignal::class, PlainQueuedListener::class);
        $this->expectException(EventException::class);
        $events->emit(new PlainQueuedSignal());
    }

    public function testOversizedPayloadIsRejectedEvenForSyncQueue(): void
    {
        QueuedEventTrace::$values = [];
        $events = $this->dispatcher();
        $events->listenQueued(QueuedEventSignal::class, QueuedEventRecorder::class);
        $this->expectException(QueueException::class);
        $events->emit(new QueuedEventSignal(str_repeat('x', 60000)));
    }

    public function testUnsupportedPayloadValueIsRejectedBeforeSyncListenerRuns(): void
    {
        $events = $this->dispatcher();
        $events->listenQueued(InvalidQueuedPayload::class, InvalidPayloadListener::class);
        $this->expectException(QueueException::class);
        $events->emit(new InvalidQueuedPayload());
    }

    public function testPayloadCaptureFailureDoesNotExposeApplicationSecret(): void
    {
        $events = $this->dispatcher();
        $events->listenQueued(BrokenQueuedSignal::class, InvalidPayloadListener::class);
        try {
            $events->emit(new BrokenQueuedSignal());
            self::fail('Application capture failure was accepted.');
        } catch (EventException $exception) {
            self::assertStringNotContainsString('private-token', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function testUnsupportedVersionAndReconstructionErrorAreContentSafe(): void
    {
        $job = DeliverEventListener::fromQueuePayload(['version' => 2,
            'event' => QueuedEventSignal::class, 'listener' => QueuedEventRecorder::class,
            'data' => ['value' => 'private-token']]);
        try {
            $job->handle();
            self::fail('Unknown payload version was executed.');
        } catch (EventException $exception) {
            self::assertStringNotContainsString('private-token', $exception->getMessage());
        }
        $job = DeliverEventListener::fromQueuePayload(['version' => 1,
            'event' => BrokenQueuedSignal::class, 'listener' => QueuedEventRecorder::class,
            'data' => ['secret' => 'private-token']]);
        try {
            $job->handle();
            self::fail('Invalid reconstruction was executed.');
        } catch (EventException $exception) {
            self::assertStringNotContainsString('private-token', $exception->getMessage());
        }
    }

    public function testSeparateApplicationsDoNotShareSyncListenerDependencies(): void
    {
        $firstSink = new QueuedEventSink();
        $secondSink = new QueuedEventSink();
        $firstContainer = new Container();
        $firstContainer->instance(QueuedEventSink::class, $firstSink);
        $secondContainer = new Container();
        $secondContainer->instance(QueuedEventSink::class, $secondSink);
        $first = $this->dispatcher($firstContainer);
        $second = $this->dispatcher($secondContainer);
        $first->listenQueued(QueuedEventSignal::class, QueuedEventInjectedListener::class);
        $second->listenQueued(QueuedEventSignal::class, QueuedEventInjectedListener::class);
        $first->emit(new QueuedEventSignal('first'));
        $second->emit(new QueuedEventSignal('second'));
        self::assertSame(['first'], $firstSink->values);
        self::assertSame(['second'], $secondSink->values);
    }

    public function testPluginsQueueableEventAliasNamesTheSameContract(): void
    {
        self::assertTrue(interface_exists(\App\Plugins\QueueableEvent::class));
        self::assertSame(QueueableEvent::class,
            (new \ReflectionClass(\App\Plugins\QueueableEvent::class))->getName());
    }
}

/** A stoppable, explicitly reconstructible event. */
final class QueuedEventSignal implements QueueableEvent, StoppableEvent
{
    use StopsEventPropagation;

    public function __construct(public string $value) {}
    public function toQueuePayload(): array { return ['value' => $this->value]; }
    public static function fromQueuePayload(array $payload): static
    {
        return new static((string) ($payload['value'] ?? ''));
    }
}

/** Application event without the queue payload contract. */
final class PlainQueuedSignal {}

/** Class listener for the non-queueable event rejection test. */
final class PlainQueuedListener
{
    public function handle(PlainQueuedSignal $event): void {}
}

/** Deliberately throws payload text to verify the safe reconstruction boundary. */
final class BrokenQueuedSignal implements QueueableEvent
{
    public function toQueuePayload(): array { throw new \RuntimeException('private-token'); }
    public static function fromQueuePayload(array $payload): static
    {
        throw new \RuntimeException((string) ($payload['secret'] ?? 'missing'));
    }
}

/** Reject an object instead of letting Sync Queue bypass JSON checks. */
final class InvalidQueuedPayload implements QueueableEvent
{
    public function toQueuePayload(): array { return ['object' => new \stdClass()]; }
    public static function fromQueuePayload(array $payload): static { return new static(); }
}

/** Listener is never reached for an invalid Queue payload. */
final class InvalidPayloadListener
{
    public function handle(QueueableEvent $event): void {}
}

/** Shared observation for Sync Queue semantics. */
final class QueuedEventTrace
{
    public static array $values = [];
}

/** Queued class listener receives the reconstructed snapshot. */
final class QueuedEventRecorder
{
    public function handle(QueuedEventSignal $event): void
    {
        QueuedEventTrace::$values[] = $event->value;
    }
}

/** Stopping a reconstructed copy cannot alter the original emission. */
final class QueuedEventStopper
{
    public function handle(QueuedEventSignal $event): void
    {
        QueuedEventTrace::$values[] = $event->value;
        $event->stopPropagation();
    }
}

/** Application-owned sink proves no shared runtime dispatcher escapes. */
final class QueuedEventSink
{
    public array $values = [];
}

/** Container resolution occurs in the active Application. */
final class QueuedEventInjectedListener
{
    public function __construct(private QueuedEventSink $sink) {}
    public function handle(QueuedEventSignal $event): void
    {
        $this->sink->values[] = $event->value;
    }
}
