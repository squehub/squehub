<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Diagnostics\Diagnostics;
use App\Diagnostics\DiagnosticsServiceProvider;
use App\Foundation\Application;
use App\Http\Request;
use App\Mail\Mail;
use App\Mail\MailAddress;
use App\Mail\Mailer;
use App\Mail\MailMessage;
use App\Mail\MailServiceProvider;
use App\Notifications\Channels\ArrayNotificationChannel;
use App\Notifications\Notifiable;
use App\Notifications\Notification;
use App\Notifications\NotificationException;
use App\Notifications\NotificationManager;
use App\Notifications\Notifications;
use App\Notifications\NotificationServiceProvider;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** Real provider composition with Array Mail and no external delivery. */
final class NotificationIntegrationTest extends TestCase
{
    private TemporaryProject $project;
    private Application $app;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
        $this->project->write('Config/Mail.php', '<?php return ["default" => "array", "from" => ["address" => "sender@example.test"], "transports" => ["array" => ["driver" => "array"], "other" => ["driver" => "array"]]];');
        $this->app = new Application($this->project->path());
        foreach ([DiagnosticsServiceProvider::class, MailServiceProvider::class,
            NotificationServiceProvider::class] as $provider) $this->app->register($provider);
        $this->app->bootstrap();
    }

    protected function tearDown(): void
    {
        Notifications::setResolver(null);
        Mail::setResolver(null);
        $this->project->remove();
    }

    public function testPlainRecipientTraitAndAnonymousRouteUseMailer(): void
    {
        $mailer = $this->app->container()->make(Mailer::class);
        $manager = $this->app->container()->make(NotificationManager::class);
        self::assertSame($manager, notifications());
        $recipient = new NotificationEmailRecipient('plain@example.test');
        $recipient->notify(new NotificationMailFixture('secret-body'));
        $manager->route('mail', 'anonymous@example.test')->send(new NotificationMailFixture('other-body'));
        $manager->route('mail', new MailAddress('named@example.test', 'Named User'))
            ->send(new NotificationMailFixture('named-body'));
        $messages = $mailer->transport()->messages();
        self::assertCount(3, $messages);
        self::assertSame('plain@example.test', $messages[0]->recipients()[0]->address());
        self::assertSame('anonymous@example.test', $messages[1]->recipients()[0]->address());
        self::assertSame('Named User', $messages[2]->recipients()[0]->name());
        self::assertSame('sender@example.test', $messages[0]->sender()->address());
        self::assertSame('secret-body', $messages[0]->textBody());
        self::assertCount(0, $mailer->transport('other')->messages());
        $mailer->send((new MailMessage())->to('direct@example.test')->subject('Direct')->text('Direct body'), via: 'other');
        self::assertCount(1, $mailer->transport('other')->messages());
    }

    public function testInvalidMailRoutesAndRepresentationsFailWithoutDelivery(): void
    {
        $manager = $this->app->container()->make(NotificationManager::class);
        $outbox = $this->app->container()->make(Mailer::class)->transport();
        foreach ([
            [new NotificationEmailRecipient(null), new NotificationMailFixture('body')],
            [new NotificationEmailRecipient('invalid-address'), new NotificationMailFixture('body')],
            [new NotificationEmailRecipient('okay@example.test'), new NotificationInvalidMailFixture()],
            [new NotificationEmailRecipient('okay@example.test'), new NotificationMissingMailFixture()],
            [new NotificationEmailRecipient('okay@example.test'), new NotificationPreaddressedMailFixture()],
            [new NotificationEmailRecipient('okay@example.test'), new NotificationThrowingMailFixture()],
            [new \stdClass(), new NotificationMailFixture('body')],
        ] as [$recipient, $notification]) {
            try { $manager->send($recipient, $notification); self::fail('Expected invalid mail notification.'); }
            catch (NotificationException $failure) {
                self::assertStringNotContainsString('okay@example.test', $failure->getMessage());
            }
        }
        self::assertSame([], $outbox->messages());
    }

    public function testMailFailureIsChainedSafelyAndMetricsRemainSeparate(): void
    {
        $diagnostics = $this->app->container()->make(Diagnostics::class);
        $diagnostics->begin(new Request('GET', '/notify'));
        $manager = $this->app->container()->make(NotificationManager::class);
        $manager->send(new NotificationEmailRecipient('first@example.test'),
            new NotificationMultiFixture());
        $stats = $diagnostics->snapshot();
        self::assertSame(1, $stats['notifications']['attempts']);
        self::assertSame(1, $stats['notifications']['sent']);
        self::assertSame(2, $stats['notifications']['channel_deliveries']);
        self::assertSame(1, $stats['mail']['attempts']);
        self::assertSame(1, $stats['mail']['sent']);
        self::assertSame([NotificationMultiFixture::class], $manager->channel('array')->notifications());
        self::assertStringNotContainsString('first@example.test', json_encode($stats));
        self::assertStringNotContainsString('private-token', json_encode($stats));

        // An incomplete Mail representation reaches Mail's own validation,
        // then returns one safe chained failure through Notifications.
        try {
            $manager->send(new NotificationEmailRecipient('first@example.test'),
                new NotificationIncompleteMailFixture());
            self::fail('Expected Mail failure.');
        } catch (NotificationException $failure) {
            self::assertSame('Notification delivery failed.', $failure->getMessage());
            self::assertInstanceOf(\App\Mail\MailException::class, $failure->getPrevious());
        }
        $stats = $diagnostics->snapshot();
        self::assertSame(2, $stats['notifications']['attempts']);
        self::assertSame(1, $stats['notifications']['failures']);
        self::assertSame(2, $stats['mail']['attempts']);
        self::assertSame(1, $stats['mail']['failures']);
        $diagnostics->begin(new Request('GET', '/next'));
        self::assertSame(0, $diagnostics->snapshot()['notifications']['attempts']);
        self::assertSame(0, $diagnostics->snapshot()['mail']['attempts']);
    }

    public function testArrayNotificationsWorkWithoutMailAndStayApplicationScoped(): void
    {
        $other = new TemporaryProject();
        $firstManager = $this->app->container()->make(NotificationManager::class);
        $firstRoute = $firstManager->route('mail', 'original@example.test');
        try {
            $app = new Application($other->path());
            $app->register(NotificationServiceProvider::class);
            $app->bootstrap();
            $manager = $app->container()->make(NotificationManager::class);
            $manager->send(new \stdClass(), new NotificationArrayFixture());
            self::assertCount(1, $manager->channel('array')->notifications());
            self::assertSame([], $this->app->container()->make(NotificationManager::class)
                ->channel('array')->notifications());
            // Anonymous routes retain their owning Application when another
            // Application changes the convenience helper's active resolver.
            $firstRoute->send(new NotificationMailFixture('original-body'));
            $firstOutbox = $this->app->container()->make(Mailer::class)->transport()->messages();
            self::assertCount(1, $firstOutbox);
            self::assertSame('original@example.test', $firstOutbox[0]->recipients()[0]->address());
            try { $manager->send(new NotificationEmailRecipient('person@example.test'),
                new NotificationMailFixture('body')); self::fail('Expected missing Mail service.'); }
            catch (NotificationException $failure) {
                self::assertSame('Mail service is unavailable for notifications.', $failure->getMessage());
            }
        } finally {
            $other->remove();
            // Restore the first Application helper for tearDown and any later use.
            $container = $this->app->container();
            Notifications::setResolver(static fn (): NotificationManager => $container->make(NotificationManager::class));
        }
    }
}

/** Optional trait usage by an ordinary object with an explicit mail route. */
final class NotificationEmailRecipient
{
    use Notifiable;
    public function __construct(private ?string $email) {}
    public function routeNotificationForMail(): ?string { return $this->email; }
}

/** A content-only representation; recipient and sender come from their owners. */
final class NotificationMailFixture extends Notification
{
    public function __construct(private string $body) {}
    public function via(mixed $notifiable): array { return ['mail']; }
    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage())->subject('Notification')->text($this->body);
    }
}

/** Mail plus an independent Array dispatch record. */
final class NotificationMultiFixture extends Notification
{
    public function via(mixed $notifiable): array { return ['mail', 'array']; }
    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage())->subject('Private')->text('private-token');
    }
}

/** Array dispatch needs neither a mail route nor a Mail provider. */
final class NotificationArrayFixture extends Notification
{
    public function via(mixed $notifiable): array { return ['array']; }
}

/** Invalid representation must produce a focused Notification error. */
final class NotificationInvalidMailFixture extends Notification
{
    public function via(mixed $notifiable): array { return ['mail']; }
    public function toMail(mixed $notifiable): string { return 'not a MailMessage'; }
}

/** Missing channel representation is reported before Mail transport work. */
final class NotificationMissingMailFixture extends Notification
{
    public function via(mixed $notifiable): array { return ['mail']; }
}

/** Arbitrary application failures may contain secrets and must be sanitized. */
final class NotificationThrowingMailFixture extends Notification
{
    public function via(mixed $notifiable): array { return ['mail']; }
    public function toMail(mixed $notifiable): MailMessage
    {
        throw new \RuntimeException('private-token from application code');
    }
}

/** A content representation must not select another recipient. */
final class NotificationPreaddressedMailFixture extends Notification
{
    public function via(mixed $notifiable): array { return ['mail']; }
    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage())->to('wrong@example.test')->subject('Private')->text('secret');
    }
}

/** Mail validation still runs after the channel adds its recipient. */
final class NotificationIncompleteMailFixture extends Notification
{
    public function via(mixed $notifiable): array { return ['mail']; }
    public function toMail(mixed $notifiable): MailMessage { return new MailMessage(); }
}
