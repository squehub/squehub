<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Foundation\Application;
use App\Mail\Mail;
use App\Mail\MailConfigurationException;
use App\Mail\Mailer;
use App\Mail\MailMessage;
use App\Mail\MailServiceProvider;
use App\Mail\MailException;
use App\Http\ExceptionHandler;
use App\Http\Request;
use App\HttpClient\HttpClient;
use App\HttpClient\HttpResponse;
use App\HttpClient\HttpServiceProvider;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** Mail provider works at CLI bootstrap without the other application pillars. */
final class MailProviderTest extends TestCase
{
    public function testBootAndHelperDoNotConnectUntilSend(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('config/Mail.php', '<?php return ["default" => "array", "from" => ["address" => "sender@example.test"], "transports" => ["array" => ["driver" => "array"]]];');
            $app = new Application($project->path());
            $app->register(MailServiceProvider::class);
            $app->bootstrap();
            self::assertSame($app->container()->make(Mailer::class), mailer());
            self::assertSame($app->container()->make(Mailer::class), mailer());
            mailer()->send((new MailMessage())->to('recipient@example.test')->subject('Hello')->text('World'));
            self::assertCount(1, mailer()->transport()->messages());
            self::assertDirectoryDoesNotExist($project->path('Storage'));
        } finally {
            Mail::setResolver(null);
            $project->remove();
        }
    }

    public function testApiTransportUsesTheApplicationsRegisteredHttpClient(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('config/Mail.php', '<?php return ["default" => "resend", "from" => ["address" => "sender@example.test"], "transports" => ["resend" => ["driver" => "resend", "api_key" => "test-token"]]];');
            $app = new Application($project->path());
            $app->register(MailServiceProvider::class);
            $app->register(HttpServiceProvider::class);
            $app->bootstrap();
            $http = $app->container()->make(HttpClient::class);
            $http->fake(['POST https://api.resend.com/emails' => new HttpResponse(200, '{"id":"accepted-1"}')]);
            mailer()->send((new MailMessage())->to('recipient@example.test')
                ->subject('Provider')->text('Body'));
            self::assertCount(1, $http->captured());
            self::assertSame('Bearer test-token', $http->captured()[0]->headers['authorization']);
        } finally {
            Mail::setResolver(null);
            $project->remove();
        }
    }

    public function testInvalidDefaultTransportFailsAtBoot(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('config/Mail.php', '<?php return ["default" => "unknown", "transports" => ["array" => ["driver" => "array"]]];');
            $app = new Application($project->path());
            $app->register(MailServiceProvider::class);
            $this->expectException(MailConfigurationException::class);
            $app->bootstrap();
        } finally {
            Mail::setResolver(null);
            $project->remove();
        }
    }

    public function testProductionBoundaryKeepsMailFailureGeneric(): void
    {
        $project = new TemporaryProject();
        try {
            $app = new Application($project->path());
            $app->config()->set('app.debug', false);
            $handler = new ExceptionHandler($app);
            $response = $handler->render(new MailException('SMTP send failed.'),
                new Request('GET', '/mail', headers: ['Accept' => 'application/json']));
            self::assertSame(500, $response->status());
            self::assertStringNotContainsString('SMTP', $response->content());
            self::assertStringNotContainsString('recipient', $response->content());
        } finally {
            $project->remove();
        }
    }

    public function testShippedConfigTreatsBlankOptionalSettingsAsUnavailable(): void
    {
        $environment = new class {
            public function get(string $key, mixed $default = null): mixed
            {
                return in_array($key, ['MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME', 'MAIL_HOST',
                    'MAIL_USERNAME', 'MAIL_PASSWORD'], true) ? '' : $default;
            }
            public function boolean(string $key, bool $default = false): bool { return $default; }
        };
        $config = require dirname(__DIR__, 2) . '/Config/Mail.php';
        self::assertNull($config['from']['address']);
        self::assertNull($config['transports']['smtp']['username']);
        self::assertInstanceOf(Mailer::class, new Mailer($config));
    }
}
