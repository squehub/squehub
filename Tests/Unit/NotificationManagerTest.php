<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Config\Repository;
use App\Diagnostics\Diagnostics;
use App\Http\Request;
use App\Notifications\Channels\ArrayNotificationChannel;
use App\Notifications\Notifiable;
use App\Notifications\Notification;
use App\Notifications\NotificationChannel;
use App\Notifications\NotificationException;
use App\Notifications\NotificationManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Channel order, no-op, failures, privacy, and standalone manager contracts. */
final class NotificationManagerTest extends TestCase
{
    private function manager(?Diagnostics $diagnostics = null): NotificationManager
    {
        $manager = new NotificationManager($diagnostics);
        $manager->registerChannel('array', static fn (): ArrayNotificationChannel => new ArrayNotificationChannel());
        return $manager;
    }

    public function testSingleMultipleDuplicateAndEmptyChannels(): void
    {
        $manager = $this->manager();
        $notifiable = new NotificationPlainRecipient();
        $manager->send($notifiable, new NotificationFixture(['array']));
        $manager->send($notifiable, new NotificationFixture(['array', 'array']));
        $manager->send($notifiable, new NotificationFixture([]));
        $records = $manager->channel('array')->notifications();
        self::assertSame([NotificationFixture::class, NotificationFixture::class], $records);
        $manager->channel('array')->clear();
        self::assertSame([], $manager->channel('array')->notifications());
    }

    public function testUnknownAndInvalidNamesFailBeforeAnyChannelRuns(): void
    {
        $manager = $this->manager();
        foreach ([['array', 'missing'], ['bad/name'], [4]] as $names) {
            try { $manager->send(new NotificationPlainRecipient(), new NotificationFixture($names)); self::fail(); }
            catch (NotificationException $failure) {
                self::assertStringNotContainsString('private-recipient', $failure->getMessage());
            }
        }
        self::assertSame([], $manager->channel('array')->notifications());
        try { $manager->registerChannel('array', new ArrayNotificationChannel()); self::fail(); }
        catch (NotificationException) { self::assertTrue(true); }
    }

    public function testInvalidViaReturnIsReportedAsSafeNotificationFailure(): void
    {
        $manager = $this->manager();
        try {
            $manager->send(new NotificationPlainRecipient(), new NotificationInvalidViaFixture());
            self::fail('Expected an invalid channel list.');
        } catch (NotificationException $failure) {
            self::assertSame('Notification dispatch failed.', $failure->getMessage());
            self::assertSame([], $manager->channel('array')->notifications());
        }
    }

    public function testApplicationNotificationExceptionsDoNotLeakTheirMessages(): void
    {
        $manager = $this->manager();
        $manager->registerChannel('unsafe', new NotificationUnsafeChannel());
        foreach ([new NotificationThrowingViaFixture(), new NotificationFixture(['unsafe'])] as $notification) {
            try {
                $manager->send(new NotificationPlainRecipient(), $notification);
                self::fail('Expected an application failure.');
            } catch (NotificationException $failure) {
                self::assertSame('Notification dispatch failed.', $failure->getMessage());
                self::assertNull($failure->getPrevious());
            }
        }
    }

    public function testFactoryIsLazyAndApplicationManagersAreIsolated(): void
    {
        $created = 0;
        $first = $this->manager();
        $second = $this->manager();
        $first->registerChannel('custom', static function () use (&$created): ArrayNotificationChannel {
            ++$created;
            return new ArrayNotificationChannel();
        });
        self::assertSame(0, $created);
        $first->send(new NotificationPlainRecipient(), new NotificationFixture(['custom']));
        self::assertSame(1, $created);
        $first->send(new NotificationPlainRecipient(), new NotificationFixture(['custom']));
        self::assertSame(1, $created);
        self::assertCount(2, $first->channel('custom')->notifications());
        self::assertSame([], $second->channel('array')->notifications());
    }

    public function testPartialFailureStopsLaterChannelsWithoutRollback(): void
    {
        $diagnostics = new Diagnostics(new Repository());
        $diagnostics->begin(new Request('GET', '/partial'));
        $manager = $this->manager($diagnostics);
        $later = 0;
        $manager->registerChannel('failing', new NotificationFailingChannel());
        $manager->registerChannel('later', new NotificationCountingChannel($later));
        try {
            $manager->send(new NotificationPlainRecipient(), new NotificationFixture(['array', 'failing', 'later']));
            self::fail('Expected channel failure.');
        } catch (NotificationException $failure) {
            self::assertSame('Notification dispatch failed.', $failure->getMessage());
            self::assertStringNotContainsString('secret-token', $failure->getMessage());
        }
        self::assertCount(1, $manager->channel('array')->notifications());
        self::assertSame(0, $later);
        self::assertSame(1, $diagnostics->snapshot()['notifications']['channel_deliveries']);
        self::assertSame(1, $diagnostics->snapshot()['notifications']['failures']);
        self::assertSame(0, $diagnostics->snapshot()['notifications']['sent']);
    }

    public function testDiagnosticsAreAggregateAndResetPerRequest(): void
    {
        $diagnostics = new Diagnostics(new Repository());
        $manager = $this->manager($diagnostics);
        $diagnostics->begin(new Request('GET', '/first'));
        $manager->send(new NotificationPlainRecipient(), new NotificationFixture(['array', 'array']));
        $manager->send(new NotificationPlainRecipient(), new NotificationFixture([]));
        try { $manager->send(new NotificationPlainRecipient(), new NotificationFixture(['missing'])); self::fail(); }
        catch (NotificationException) {}
        $snapshot = $diagnostics->snapshot()['notifications'];
        self::assertSame(3, $snapshot['attempts']);
        self::assertSame(2, $snapshot['sent']);
        self::assertSame(1, $snapshot['failures']);
        self::assertSame(1, $snapshot['channel_deliveries']);
        self::assertGreaterThanOrEqual(0.0, $snapshot['time_ms']);
        self::assertStringNotContainsString('private-recipient', json_encode($diagnostics->snapshot()));
        self::assertStringNotContainsString('secret-token', json_encode($diagnostics->snapshot()));
        $diagnostics->begin(new Request('GET', '/second'));
        self::assertSame(0, $diagnostics->snapshot()['notifications']['attempts']);
    }

    public function testAnonymousRouteValidatesWithoutStoringInManager(): void
    {
        $manager = $this->manager();
        $one = $manager->route('mail', 'one@example.test');
        $two = $manager->route('mail', 'two@example.test');
        self::assertNotSame($one, $two);
        self::assertSame('one@example.test', $one->routeNotificationForMail()->address());
        self::assertSame('two@example.test', $two->routeNotificationForMail()->address());
        ob_start(); var_dump($one); $debug = ob_get_clean();
        self::assertStringNotContainsString('one@example.test', $debug);
        foreach (['bad-address', "bad\r\nBcc: evil@example.test"] as $invalid) {
            try { $manager->route('mail', $invalid); self::fail(); }
            catch (NotificationException $failure) {
                self::assertStringNotContainsString($invalid, $failure->getMessage());
            }
        }
    }
}

/** Plain recipients need no Model, Session, or database state. */
final class NotificationPlainRecipient
{
    use Notifiable;
    public function routeNotificationForMail(): string { return 'private-recipient@example.test'; }
}

/** Keeps channel lists explicit while test payload stays private. */
final class NotificationFixture extends Notification
{
    public function __construct(private array $channels, private string $payload = 'secret-token') {}
    public function via(mixed $notifiable): array { return $this->channels; }
}

/** Failure text simulates an unsafe application channel exception. */
final class NotificationFailingChannel implements NotificationChannel
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        throw new RuntimeException('secret-token channel failed');
    }
}

/** Records whether later delivery ran after a prior channel failed. */
final class NotificationCountingChannel implements NotificationChannel
{
    public function __construct(private int &$count) {}
    public function send(mixed $notifiable, Notification $notification): void { ++$this->count; }
}

/** The declared array return contract rejects malformed application definitions. */
final class NotificationInvalidViaFixture extends Notification
{
    public function via(mixed $notifiable): array { return 'secret-token'; }
}

/** A notification may throw a same-type exception containing private state. */
final class NotificationThrowingViaFixture extends Notification
{
    public function via(mixed $notifiable): array
    {
        throw new NotificationException('secret-token from via');
    }
}

/** A custom channel's exception message is not a trusted framework message. */
final class NotificationUnsafeChannel implements NotificationChannel
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        throw new NotificationException('secret-token from channel');
    }
}
