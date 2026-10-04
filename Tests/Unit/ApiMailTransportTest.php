<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Config\Repository;
use App\Diagnostics\Diagnostics;
use App\Http\Request;
use App\HttpClient\HttpClient;
use App\HttpClient\HttpConnectionException;
use App\HttpClient\HttpResponse;
use App\Mail\MailConfigurationException;
use App\Mail\Mailer;
use App\Mail\MailMessage;
use App\Mail\MailProviderException;
use App\Mail\Transports\PostmarkMailTransport;
use App\Mail\Transports\ResendMailTransport;
use App\Queue\QueueManager;
use App\Support\SecretRedactor;
use PHPUnit\Framework\TestCase;

/** Contract tests for Mail's optional HTTPS providers without network access. */
final class ApiMailTransportTest extends TestCase
{
    private const RESEND_URL = 'https://api.resend.com/emails';
    private const POSTMARK_URL = 'https://api.postmarkapp.com/email';
    private const SECRET = 'provider-secret-do-not-print';

    /** @return array<string,mixed> */
    private function config(string $default = 'resend'): array
    {
        return [
            'default' => $default,
            'from' => ['address' => 'sender@example.test', 'name' => 'Sender'],
            'transports' => [
                'resend' => ['driver' => 'resend', 'api_key' => self::SECRET, 'timeout' => 3],
                'postmark' => ['driver' => 'postmark', 'api_key' => self::SECRET, 'timeout' => 3],
                'array' => ['driver' => 'array'],
            ],
        ];
    }

    private function message(): MailMessage
    {
        return (new MailMessage())->to('to@example.test', 'Recipient, Jr.')
            ->cc('cc@example.test')->bcc('bcc@example.test')
            ->replyTo('reply@example.test')->subject('Provider test')
            ->text('Plain body')->html('<p>HTML body</p>');
    }

    public function testResendMapsDocumentedFieldsAndDefaultMimeAttachment(): void
    {
        $http = new HttpClient();
        $http->fake(['POST ' . self::RESEND_URL => new HttpResponse(200, '{"id":"resend-123"}')]);
        $mailer = new Mailer($this->config(), httpClient: $http);
        self::assertInstanceOf(ResendMailTransport::class, $mailer->transport());
        $draft = $this->message()->attachBytes("\0\xFF", 'report.bin');
        $mailer->send($draft);
        self::assertNull($draft->sender());
        $requests = $http->captured();
        self::assertCount(1, $requests);
        self::assertSame(self::RESEND_URL, $requests[0]->url);
        self::assertSame('POST', $requests[0]->method);
        self::assertSame('Bearer ' . self::SECRET, $requests[0]->headers['authorization']);
        self::assertSame('application/json', $requests[0]->headers['content-type']);
        self::assertTrue($requests[0]->verifyPeer);
        self::assertSame(3.0, $requests[0]->timeout);
        $body = json_decode($requests[0]->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('"Sender" <sender@example.test>', $body['from']);
        self::assertSame(['"Recipient, Jr." <to@example.test>'], $body['to']);
        self::assertSame(['cc@example.test'], $body['cc']);
        self::assertSame(['bcc@example.test'], $body['bcc']);
        self::assertSame(['reply@example.test'], $body['reply_to']);
        self::assertSame('Provider test', $body['subject']);
        self::assertSame('Plain body', $body['text']);
        self::assertSame('<p>HTML body</p>', $body['html']);
        self::assertSame([['filename' => 'report.bin', 'content' => base64_encode("\0\xFF")]],
            $body['attachments']);
    }

    public function testPostmarkMapsFieldsAndExplicitAttachmentMime(): void
    {
        $http = new HttpClient();
        $http->fake(['POST ' . self::POSTMARK_URL => new HttpResponse(200,
            '{"ErrorCode":0,"MessageID":"01234567-abcd-42ef-8123-0123456789ab"}')]);
        $mailer = new Mailer($this->config('postmark'), httpClient: $http);
        self::assertInstanceOf(PostmarkMailTransport::class, $mailer->transport());
        $mailer->send($this->message()->attachBytes('report', 'report.txt', 'text/plain'));
        $request = $http->captured()[0];
        self::assertSame(self::POSTMARK_URL, $request->url);
        self::assertSame(self::SECRET, $request->headers['x-postmark-server-token']);
        $body = json_decode($request->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('"Sender" <sender@example.test>', $body['From']);
        self::assertSame('"Recipient, Jr." <to@example.test>', $body['To']);
        self::assertSame('cc@example.test', $body['Cc']);
        self::assertSame('bcc@example.test', $body['Bcc']);
        self::assertSame('reply@example.test', $body['ReplyTo']);
        self::assertSame('Provider test', $body['Subject']);
        self::assertSame('Plain body', $body['TextBody']);
        self::assertSame('<p>HTML body</p>', $body['HtmlBody']);
        self::assertSame([['Name' => 'report.txt', 'Content' => base64_encode('report'),
            'ContentType' => 'text/plain']], $body['Attachments']);
    }

    public function testProviderFailuresAreCategorizedWithoutResponseOrCredentialData(): void
    {
        $cases = [
            [401, 'authentication'], [403, 'rejected'], [422, 'validation'], [429, 'rate_limit'],
            [503, 'server'], [302, 'rejected'],
        ];
        foreach ($cases as [$status, $category]) {
            $http = new HttpClient();
            $http->fake(['POST ' . self::RESEND_URL => new HttpResponse($status,
                'recipient@example.test ' . self::SECRET)]);
            try {
                (new Mailer($this->config(), httpClient: $http))->send($this->message());
                self::fail('Expected provider rejection.');
            } catch (MailProviderException $failure) {
                self::assertSame($category, $failure->category());
                self::assertStringNotContainsString(self::SECRET, $failure->getMessage());
                self::assertStringNotContainsString('recipient@example.test', $failure->getMessage());
            }
        }
        $http = new HttpClient();
        $http->fake(['POST ' . self::RESEND_URL => new HttpConnectionException(
            'External HTTP request failed to connect.')]);
        try {
            (new Mailer($this->config(), httpClient: $http))->send($this->message());
            self::fail('Expected network failure.');
        } catch (MailProviderException $failure) {
            self::assertSame('network', $failure->category());
            self::assertNull($failure->getPrevious());
        }
    }

    public function testMalformedOrProviderLevelResponsesNeverCountAsSent(): void
    {
        foreach ([
            ['resend', self::RESEND_URL, new HttpResponse(200, '{"id":null}'), 'malformed'],
            ['resend', self::RESEND_URL, new HttpResponse(200, 'not json'), 'malformed'],
            ['postmark', self::POSTMARK_URL, new HttpResponse(200, '{"ErrorCode":406,"Message":"private"}'), 'rejected'],
            ['postmark', self::POSTMARK_URL, new HttpResponse(200, '{"ErrorCode":0}'), 'malformed'],
            ['postmark', self::POSTMARK_URL, new HttpResponse(202,
                '{"ErrorCode":0,"MessageID":"accepted-1"}'), 'malformed'],
        ] as [$driver, $url, $response, $category]) {
            $http = new HttpClient();
            $http->fake(['POST ' . $url => $response]);
            try {
                (new Mailer($this->config($driver), httpClient: $http))->send($this->message());
                self::fail('Expected malformed or rejected provider response.');
            } catch (MailProviderException $failure) {
                self::assertSame($category, $failure->category());
                self::assertStringNotContainsString('private', $failure->getMessage());
            }
        }
    }

    public function testUnsupportedFeaturesAndConfigurationFailBeforeHttp(): void
    {
        $http = new HttpClient();
        $http->fake([]);
        $mailer = new Mailer($this->config(), httpClient: $http);
        foreach ([
            (new MailMessage())->bcc('hidden@example.test')->subject('BCC')->text('Body'),
            $this->message()->attachBytes('bytes', 'report.txt', 'text/plain'),
            $this->message()->attachBytes(str_repeat('x', 8 * 1024 * 1024), 'large.bin'),
        ] as $draft) {
            try { $mailer->send($draft); self::fail('Expected unsupported provider payload.'); }
            catch (MailConfigurationException $failure) {
                self::assertStringNotContainsString('hidden@example.test', $failure->getMessage());
            }
        }
        self::assertSame([], $http->captured());
        $missing = $this->config();
        $missing['transports']['resend']['api_key'] = null;
        try { (new Mailer($missing, httpClient: $http))->send($this->message()); self::fail('Expected token failure.'); }
        catch (MailConfigurationException) { self::assertSame([], $http->captured()); }
        try { (new Mailer($this->config()))->send($this->message()); self::fail('Expected HTTP service failure.'); }
        catch (MailConfigurationException) { self::assertTrue(true); }
    }

    public function testOversizedDraftIsRejectedBeforeProviderPayloadEncoding(): void
    {
        $http = new HttpClient();
        $http->fake([]);
        $mailer = new Mailer($this->config(), httpClient: $http);
        $oversized = [
            // Resend normally rejects custom MIME in payload(). The size
            // failure proves that Base64 construction was never reached.
            $this->message()->attachBytes(str_repeat('x', 8 * 1024 * 1024),
                'large.txt', 'text/plain'),
            $this->message()->text(str_repeat('x', 10 * 1024 * 1024)),
        ];
        foreach ($oversized as $draft) {
            try {
                $mailer->send($draft);
                self::fail('Oversized provider draft must be rejected.');
            } catch (MailConfigurationException $failure) {
                self::assertSame('Mail provider payload exceeds the configured size limit.',
                    $failure->getMessage());
            }
        }
        self::assertSame([], $http->captured());
    }

    public function testNamedProviderUsesExistingSyncQueueAndApplicationsStayIsolated(): void
    {
        $firstHttp = new HttpClient();
        $firstHttp->fake(['POST ' . self::RESEND_URL => new HttpResponse(200, '{"id":"queued-1"}')]);
        $secondHttp = new HttpClient();
        $secondHttp->fake(['POST ' . self::RESEND_URL => new HttpResponse(200, '{"id":"other-1"}')]);
        $queue = new QueueManager(['default' => 'sync', 'connections' => ['sync' => ['driver' => 'sync']]]);
        $first = new Mailer($this->config(), queueManager: $queue, httpClient: $firstHttp);
        $second = new Mailer($this->config(), httpClient: $secondHttp);
        $first->queue($this->message(), via: 'resend');
        self::assertCount(1, $firstHttp->captured());
        self::assertSame([], $secondHttp->captured());
        $second->send($this->message());
        self::assertCount(1, $secondHttp->captured());
        self::assertCount(1, $firstHttp->captured());
    }

    public function testProviderTokensRemainPrivateInDebugAndRedaction(): void
    {
        $mailer = new Mailer($this->config(), httpClient: new HttpClient());
        ob_start();
        var_dump($mailer, $mailer->transport('resend'), $mailer->transport('postmark'));
        $debug = (string) ob_get_clean();
        self::assertStringNotContainsString(self::SECRET, $debug);
        $redactor = new SecretRedactor(new Repository(['mail' => $this->config()]));
        self::assertSame('[REDACTED]', $redactor->redact(self::SECRET));
    }

    public function testDiagnosticsContainOnlyAggregateOutcomes(): void
    {
        $diagnostics = new Diagnostics(new Repository());
        $diagnostics->begin(new Request('GET', '/mail'));
        $http = new HttpClient([], null, $diagnostics);
        $http->fake(['POST ' . self::RESEND_URL => new HttpResponse(401,
            self::SECRET . ' to@example.test private-body')]);
        $mailer = new Mailer($this->config(), $diagnostics, httpClient: $http);
        try { $mailer->send($this->message()); self::fail('Expected provider failure.'); }
        catch (MailProviderException $failure) { self::assertSame('authentication', $failure->category()); }
        $snapshot = $diagnostics->snapshot();
        self::assertSame(1, $snapshot['mail']['attempts']);
        self::assertSame(1, $snapshot['mail']['failures']);
        self::assertSame(0, $snapshot['mail']['sent']);
        $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
        foreach ([self::SECRET, 'to@example.test', 'private-body', self::RESEND_URL] as $private) {
            self::assertStringNotContainsString($private, $json);
        }
    }
}
