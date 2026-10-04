<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Config\Repository;
use App\Diagnostics\Diagnostics;
use App\Http\Request;
use App\Mail\MailConfigurationException;
use App\Mail\Mailer;
use App\Mail\MailMessage;
use App\Mail\Transports\ArrayMailTransport;
use App\Mail\Transports\SmtpMailTransport;
use App\Support\SecretRedactor;
use PHPUnit\Framework\TestCase;

/** Validates draft preparation, outbox isolation, transport selection, and privacy. */
final class MailFoundationTest extends TestCase
{
    /** @return array<string,mixed> */
    private function config(): array
    {
        return ['default' => 'array', 'from' => ['address' => 'sender@example.test', 'name' => 'SqueHub'],
            'transports' => ['array' => ['driver' => 'array'], 'other' => ['driver' => 'array']]];
    }

    private function message(): MailMessage
    {
        return (new MailMessage())->to('to@example.test', 'Recipient')->cc('cc@example.test')
            ->bcc('bcc@example.test')->replyTo('reply@example.test')->subject('Subject')
            ->text('Plain body')->html('<p>HTML body</p>')
            ->attachBytes('bytes', 'report.txt', 'text/plain');
    }

    public function testDefaultSenderAndNamedOutboxesAreIsolatedSnapshots(): void
    {
        $mailer = new Mailer($this->config());
        $draft = $this->message();
        $mailer->send($draft);
        self::assertNull($draft->sender());
        $outbox = $mailer->transport();
        self::assertInstanceOf(ArrayMailTransport::class, $outbox);
        self::assertSame('sender@example.test', $outbox->messages()[0]->sender()->address());
        self::assertSame('bytes', $outbox->messages()[0]->attachments()[0]->contents());
        $outbox->messages()[0]->subject('Altered');
        self::assertSame('Subject', $outbox->messages()[0]->subjectLine());
        $draft->subject('Second');
        $mailer->send($draft, via: 'other');
        self::assertCount(1, $outbox->messages());
        self::assertCount(1, $mailer->transport('other')->messages());
        $outbox->clear();
        self::assertSame([], $outbox->messages());
        self::assertCount(1, $mailer->transport('other')->messages());
    }

    public function testExplicitSenderAndBccOnlyMessageWork(): void
    {
        $mailer = new Mailer($this->config());
        $mailer->send((new MailMessage())->from('explicit@example.test')->bcc('hidden@example.test')
            ->subject('Notice')->text('Private body'));
        self::assertSame('explicit@example.test', $mailer->transport()->messages()[0]->sender()->address());
        self::assertSame([], $mailer->transport()->messages()[0]->recipients());
    }

    public function testMessageValidationAndHeaderInjectionAreRejected(): void
    {
        $mailer = new Mailer($this->config());
        $invalid = [
            new MailMessage(),
            (new MailMessage())->to('person@example.test'),
            (new MailMessage())->to('person@example.test')->subject('Hello'),
        ];
        foreach ($invalid as $message) {
            try { $mailer->send($message); self::fail('Expected incomplete message.'); }
            catch (MailConfigurationException) { self::assertTrue(true); }
        }
        foreach ([
            static fn () => (new MailMessage())->to("bad\r\nBcc:evil@example.test"),
            static fn () => (new MailMessage())->to('ok@example.test', "Name\r\nBcc: evil@example.test"),
            static fn () => (new MailMessage())->subject("Hi\r\nBcc: evil@example.test"),
            static fn () => (new MailMessage())->attachBytes('x', '../secret.txt'),
            static fn () => (new MailMessage())->attachBytes('x', 'file.txt', "text/plain\r\nX-Evil: 1"),
        ] as $invalidHeader) {
            try { $invalidHeader(); self::fail('Expected unsafe header metadata.'); }
            catch (MailConfigurationException $failure) {
                self::assertStringNotContainsString('evil@example.test', $failure->getMessage());
            }
        }
        self::assertSame([], $mailer->transport()->messages());
    }

    public function testConfigurationAndUnknownTransportFailuresAreSafe(): void
    {
        foreach ([
            ['default' => 'missing', 'transports' => ['array' => ['driver' => 'array']]],
            ['default' => 'array', 'transports' => ['array' => ['driver' => 'unknown']]],
            ['default' => 'bad/name', 'transports' => []],
        ] as $invalid) {
            try { new Mailer($invalid); self::fail('Expected invalid transport map.'); }
            catch (MailConfigurationException) { self::assertTrue(true); }
        }
        $mailer = new Mailer($this->config());
        try { $mailer->send($this->message(), via: 'unknown'); self::fail('Expected unknown transport.'); }
        catch (MailConfigurationException $failure) {
            self::assertStringNotContainsString('to@example.test', $failure->getMessage());
        }
    }

    public function testDiagnosticsCountOutcomesAndResetWithoutMessageData(): void
    {
        $diagnostics = new Diagnostics(new Repository());
        $diagnostics->begin(new Request('GET', '/mail'));
        $mailer = new Mailer($this->config(), $diagnostics);
        $mailer->send($this->message());
        try { $mailer->send(new MailMessage()); self::fail('Expected failure.'); }
        catch (MailConfigurationException) {}
        $stats = $diagnostics->snapshot()['mail'];
        self::assertSame(2, $stats['attempts']);
        self::assertSame(1, $stats['sent']);
        self::assertSame(1, $stats['failures']);
        self::assertGreaterThanOrEqual(0.0, $stats['time_ms']);
        self::assertStringNotContainsString('to@example.test', json_encode($diagnostics->snapshot()));
        $diagnostics->begin(new Request('GET', '/next'));
        self::assertSame(0, $diagnostics->snapshot()['mail']['attempts']);
    }

    public function testSmtpConfigurationFailsBeforeOpeningNetworkAndKeepsSecretsPrivate(): void
    {
        $message = $this->message()->from('sender@example.test');
        foreach ([
            ['host' => null],
            ['host' => '127.0.0.1', 'port' => 0],
            ['host' => '127.0.0.1', 'timeout' => 121],
            ['host' => '127.0.0.1', 'encryption' => 'bad'],
            ['host' => '127.0.0.1', 'username' => 'private-user'],
            ['host' => '127.0.0.1', 'verify_peer' => 'false'],
        ] as $invalid) {
            $transport = new SmtpMailTransport(['driver' => 'smtp'] + $invalid);
            try { $transport->send($message); self::fail('Expected invalid SMTP configuration.'); }
            catch (MailConfigurationException $failure) {
                self::assertStringNotContainsString('private-user', $failure->getMessage());
            }
        }
        $redactor = new SecretRedactor(new Repository(['mail' => ['transports' => [
            'smtp' => ['username' => 'private-user', 'password' => 'private-password']]]]));
        self::assertSame('[REDACTED] [REDACTED]', $redactor->redact('private-user private-password'));
        $configured = new Mailer(['default' => 'smtp', 'transports' => ['smtp' => [
            'driver' => 'smtp', 'username' => 'private-user', 'password' => 'private-password']]]);
        ob_start();
        var_dump($configured, $configured->transport());
        $debug = (string) ob_get_clean();
        self::assertStringNotContainsString('private-password', $debug);
        self::assertStringNotContainsString('private-user', $debug);
    }

    public function testAddressBodyAndAttachmentBoundaries(): void
    {
        $mailer = new Mailer($this->config());
        $mailer->send((new MailMessage())->to('person@example.test', 'Zoë')->subject('Résumé')
            ->text("binary\0text")
            ->attachBytes("\0\xFF", 'résumé.txt'));
        $mailer->send((new MailMessage())->to('person@example.test')->subject('HTML only')->html('<b>raw</b>')
            ->attachBytes('', 'empty.txt'));
        self::assertSame("binary\0text", $mailer->transport()->messages()[0]->textBody());
        self::assertSame("\0\xFF", $mailer->transport()->messages()[0]->attachments()[0]->contents());
        self::assertSame('application/octet-stream', $mailer->transport()->messages()[0]->attachments()[0]->mime());
        self::assertSame('<b>raw</b>', $mailer->transport()->messages()[1]->htmlBody());
        foreach (['', 'bad address', str_repeat('a', 250) . '@example.test'] as $address) {
            try { (new MailMessage())->to($address); self::fail('Expected invalid address.'); }
            catch (MailConfigurationException) { self::assertTrue(true); }
        }
        foreach (['', '   ', "bad\r\nheader", str_repeat('a', 999)] as $subject) {
            try { (new MailMessage())->subject($subject); self::fail('Expected invalid subject.'); }
            catch (MailConfigurationException) { self::assertTrue(true); }
        }
        foreach (['.', '..', 'dir/file.txt', 'dir\\file.txt', "bad\nfile.txt"] as $filename) {
            try { (new MailMessage())->attachBytes('x', $filename); self::fail('Expected invalid filename.'); }
            catch (MailConfigurationException) { self::assertTrue(true); }
        }
        $noSender = $this->config();
        $noSender['from'] = [];
        try { (new Mailer($noSender))->send($this->message()); self::fail('Expected missing sender.'); }
        catch (MailConfigurationException) { self::assertTrue(true); }
    }
}
