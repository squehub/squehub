<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Broadcasting\Adapters\ArrayBroadcastAdapter;
use App\Broadcasting\BroadcastAdapter;
use App\Broadcasting\BroadcastEvent;
use App\Broadcasting\BroadcastException;
use App\Broadcasting\BroadcastManager;
use App\Broadcasting\BroadcastMessage;
use App\Broadcasting\Channel;
use App\Broadcasting\Queue\DeliverBroadcast;
use App\Container\Container;
use App\Queue\QueueCodec;
use App\Queue\QueueManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Bounded broadcast messages and adapters without HTTP or network services. */
final class BroadcastingTest extends TestCase
{
    private function manager(array $settings = []): BroadcastManager
    {
        return new BroadcastManager(new Container(), $settings +
            ['enabled' => true, 'driver' => 'array']);
    }

    private function outbox(BroadcastManager $manager): ArrayBroadcastAdapter
    {
        $adapter = $manager->adapter();
        if (!$adapter instanceof ArrayBroadcastAdapter) {
            throw new RuntimeException('Expected the Array Broadcast adapter.');
        }
        return $adapter;
    }

    public function testDisabledByDefaultAndPluginContractsAreExact(): void
    {
        self::assertTrue(interface_exists(\App\Plugins\BroadcastEvent::class));
        self::assertTrue(interface_exists(\App\Plugins\BroadcastAdapter::class));
        self::assertTrue(interface_exists(\App\Plugins\ChannelAuthorizer::class));
        self::assertTrue(class_exists(\App\Plugins\BroadcastException::class));
        self::assertTrue(is_a(BroadcastException::class, \App\Plugins\BroadcastException::class, true));
        self::assertTrue(class_exists(\App\Plugins\BroadcastMessage::class));
        self::assertInstanceOf(Channel::class, \App\Plugins\Channel::public('orders'));
        self::assertSame('public', \App\Plugins\Channel::public('orders')->type());
        $disabled = new BroadcastManager(new Container(), []);
        self::assertFalse($disabled->enabled());
        $this->expectException(BroadcastException::class);
        $disabled->send(new BroadcastFixture('order.updated', [Channel::public('orders')], ['id' => 1]));
    }

    public function testPublicArrayAdapterAndPrivateRule(): void
    {
        $manager = $this->manager();
        $manager->privateChannel('orders.{order}', static fn (): bool => true);
        $manager->send(new BroadcastFixture('order.updated',
            [Channel::public('orders'), Channel::private('orders.42')], ['order_id' => 42]));
        $adapter = $manager->adapter();
        self::assertInstanceOf(ArrayBroadcastAdapter::class, $adapter);
        self::assertCount(1, $adapter->messages());
        $message = $adapter->messages()[0];
        self::assertInstanceOf(\App\Plugins\BroadcastMessage::class, $message);
        self::assertSame('order.updated', $message->name());
        self::assertSame(['order_id' => 42], $message->payload());
        self::assertSame('private', $message->channels()[1]->type());
        self::assertSame('orders.42', $message->channels()[1]->name());
        self::assertSame($adapter, $manager->adapter());
        $adapter->clear();
        self::assertSame([], $adapter->messages());

        $null = $this->manager(['driver' => 'null']);
        $null->send(new BroadcastFixture('quiet', [Channel::public('orders')], []));
        self::assertInstanceOf(\App\Broadcasting\Adapters\NullBroadcastAdapter::class, $null->adapter());
    }

    public function testPrivateChannelWithoutRuleCannotBePublishedOrSubscribed(): void
    {
        $manager = $this->manager();
        self::assertFalse($manager->authorizePrivate('orders.42'));
        $this->expectException(BroadcastException::class);
        $manager->send(new BroadcastFixture('order.updated', [Channel::private('orders.42')], []));
    }

    public function testInvalidChannelNameAndPatternAreRejected(): void
    {
        foreach (['', 'orders..42', 'orders/42', "orders\r\n42", str_repeat('a', 129)] as $name) {
            try {
                Channel::public($name);
                self::fail('Invalid channel accepted.');
            } catch (BroadcastException) {
                self::assertTrue(true);
            }
        }
        foreach (['{all}', 'orders.{id}.{id}', 'orders.*', 'orders..{id}'] as $pattern) {
            try {
                $this->manager()->privateChannel($pattern, static fn (): bool => true);
                self::fail('Invalid private-channel pattern accepted.');
            } catch (BroadcastException) {
                self::assertTrue(true);
            }
        }
    }

    public function testOverlappingPrivateRulesFailClosed(): void
    {
        $manager = $this->manager();
        $manager->privateChannel('orders.{order}', static fn (): bool => true);
        $manager->privateChannel('orders.42', static fn (): bool => true);
        $this->expectException(BroadcastException::class);
        $manager->authorizePrivate('orders.42');
    }

    public function testMalformedAndSensitivePayloadsFailBeforeAdapterUse(): void
    {
        $values = [
            ['password' => 'secret'],
            ['nested' => ['api_key' => 'secret']],
            ['value' => new \stdClass()],
            ['value' => INF],
            ['value' => "\xFF"],
        ];
        foreach ($values as $payload) {
            $manager = $this->manager();
            try {
                $manager->send(new BroadcastFixture('safe', [Channel::public('orders')], $payload));
                self::fail('Invalid broadcast payload accepted.');
            } catch (BroadcastException $failure) {
                self::assertStringNotContainsString('secret', $failure->getMessage());
                self::assertSame([], $this->outbox($manager)->messages());
            }
        }
        $manager = $this->manager();
        try {
            $manager->send(new BroadcastFixture('safe', [Channel::public('orders')],
                ['body' => str_repeat('x', 40000)]));
            self::fail('Oversized broadcast accepted.');
        } catch (BroadcastException) {
            self::assertSame([], $this->outbox($manager)->messages());
        }
    }

    public function testNameChannelCountAndDuplicateChannelAreRejected(): void
    {
        foreach ([
            new BroadcastFixture('bad name', [Channel::public('orders')], []),
            new BroadcastFixture('safe', [], []),
            new BroadcastFixture('safe', [Channel::public('orders'), Channel::public('orders')], []),
        ] as $event) {
            $manager = $this->manager();
            try {
                $manager->send($event);
                self::fail('Invalid broadcast accepted.');
            } catch (BroadcastException) {
                self::assertSame([], $this->outbox($manager)->messages());
            }
        }
    }

    public function testAdapterFailureIsSafeAndNotSilentlyDropped(): void
    {
        $manager = $this->manager(['driver' => 'external']);
        $manager->registerAdapter('external', new class implements BroadcastAdapter {
            public function publish(BroadcastMessage $message): void
            {
                throw new RuntimeException('SQUEHUB_BROADCAST_SECRET_DO_NOT_LEAK');
            }
        });
        try {
            $manager->send(new BroadcastFixture('safe', [Channel::public('orders')], []));
            self::fail('Adapter failure was swallowed.');
        } catch (BroadcastException $failure) {
            self::assertSame('Broadcast delivery failed.', $failure->getMessage());
            self::assertStringNotContainsString('SQUEHUB_BROADCAST_SECRET_DO_NOT_LEAK',
                $failure->getMessage());
        }
    }

    public function testVersionedQueuePayloadAndSyncQueueUseTheSameValidation(): void
    {
        $container = new Container();
        $container->instance(QueueManager::class, new QueueManager([
            'default' => 'sync', 'connections' => ['sync' => ['driver' => 'sync']],
        ]));
        $manager = new BroadcastManager($container, ['enabled' => true, 'driver' => 'array']);
        $manager->queue(new BroadcastFixture('safe', [Channel::public('orders')], ['id' => 5]));
        self::assertCount(1, $this->outbox($manager)->messages());
        $job = DeliverBroadcast::capture($this->outbox($manager)->messages()[0], $manager);
        self::assertSame(1, $job->toQueuePayload()['version']);
        self::assertInstanceOf(DeliverBroadcast::class, QueueCodec::decode(QueueCodec::encode($job)));
        $this->expectException(BroadcastException::class);
        DeliverBroadcast::fromQueuePayload(['version' => 2, 'message' => $job->toQueuePayload()['message']]);
    }
}

/** The test event exposes only deliberate public data. */
final class BroadcastFixture implements BroadcastEvent
{
    /** @param list<Channel> $channels
     *  @param array<string|int,mixed> $payload
     */
    public function __construct(private string $name, private array $channels, private array $payload)
    {
    }
    public function broadcastName(): string { return $this->name; }
    public function broadcastChannels(): array { return $this->channels; }
    public function broadcastPayload(): array { return $this->payload; }
}
