<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\HttpClient\HttpClient;
use App\Mail\Mailer;
use App\Mail\MailMessage;
use PHPUnit\Framework\TestCase;

/** Explicit live provider qualification; ordinary test runs never send Mail. */
final class ApiMailLiveOptInTest extends TestCase
{
    public function testResendOnExplicitLiveOptIn(): void
    {
        $this->sendIfEnabled('RESEND', 'resend', 'SQUEHUB_TEST_RESEND_API_KEY');
    }

    public function testPostmarkOnExplicitLiveOptIn(): void
    {
        $this->sendIfEnabled('POSTMARK', 'postmark', 'SQUEHUB_TEST_POSTMARK_SERVER_TOKEN');
    }

    private function sendIfEnabled(string $provider, string $driver, string $tokenName): void
    {
        if (getenv('SQUEHUB_TEST_' . $provider . '_ENABLED') !== '1') {
            self::markTestSkipped('Live Mail provider testing requires explicit opt-in.');
        }
        $token = getenv($tokenName);
        $from = getenv('SQUEHUB_TEST_' . $provider . '_FROM');
        $to = getenv('SQUEHUB_TEST_' . $provider . '_TO');
        if (!is_string($token) || $token === '' || !is_string($from) || $from === ''
            || !is_string($to) || $to === '') {
            self::fail('Live Mail provider opt-in requires a token, sender, and recipient.');
        }

        $mailer = new Mailer([
            'default' => $driver,
            'from' => ['address' => $from],
            'transports' => [$driver => ['driver' => $driver, 'api_key' => $token, 'timeout' => 10]],
        ], httpClient: new HttpClient());
        $mailer->send((new MailMessage())->to($to)
            ->subject('SqueHub v2 explicit live Mail provider qualification')
            ->text('This message was sent by a deliberately opted-in SqueHub integration test.'));
        self::assertTrue(true);
    }
}
