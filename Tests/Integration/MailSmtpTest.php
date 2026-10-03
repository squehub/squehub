<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Mail\Mailer;
use App\Mail\MailException;
use App\Mail\MailMessage;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Real loopback SMTP verifies envelope, headers, MIME mapping, and lazy I/O. */
final class MailSmtpTest extends TestCase
{
    public function testTlsModeRequestsStartTlsWithoutFallingBackToPlainDelivery(): void
    {
        $project = new TemporaryProject();
        $ready = $project->path('smtp-port.txt');
        $capture = $project->path('smtp-capture.json');
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Fixtures/LocalSmtpServer.php',
            $ready, $capture, 'starttls'], [0 => ['pipe', 'r'],
            1 => ['file', $project->path('smtp-out.txt'), 'w'],
            2 => ['file', $project->path('smtp-err.txt'), 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            $port = null;
            for ($i = 0; $i < 100; ++$i) {
                if (is_file($ready)) { $port = (int) file_get_contents($ready); break; }
                usleep(20_000);
            }
            self::assertNotNull($port);
            $mailer = new Mailer(['default' => 'smtp', 'transports' => ['smtp' => [
                'driver' => 'smtp', 'host' => '127.0.0.1', 'port' => $port,
                'encryption' => 'tls', 'timeout' => 5]]]);
            try {
                $mailer->send((new MailMessage())->from('sender@example.test')
                    ->to('recipient@example.test')->subject('TLS')->text('Body'));
                self::fail('Expected unavailable TLS handshake to abort send.');
            } catch (MailException $failure) {
                self::assertSame('SMTP send failed.', $failure->getMessage());
            }
            for ($i = 0; $i < 100 && !is_file($capture); ++$i) usleep(20_000);
            self::assertFileExists($capture);
            $record = json_decode((string) file_get_contents($capture), true, 512, JSON_THROW_ON_ERROR);
            self::assertContains('STARTTLS', $record['envelope']);
            self::assertStringNotContainsString('MAIL FROM:', implode("\n", $record['envelope']));
        } finally {
            if (!is_file($capture)) proc_terminate($process);
            proc_close($process);
            $project->remove();
        }
    }

    public function testLocalSmtpAuthenticationNegotiation(): void
    {
        $project = new TemporaryProject();
        $ready = $project->path('smtp-port.txt');
        $capture = $project->path('smtp-capture.json');
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Fixtures/LocalSmtpServer.php',
            $ready, $capture, 'auth'], [0 => ['pipe', 'r'],
            1 => ['file', $project->path('smtp-out.txt'), 'w'],
            2 => ['file', $project->path('smtp-err.txt'), 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            $port = null;
            for ($i = 0; $i < 100; ++$i) {
                if (is_file($ready)) { $port = (int) file_get_contents($ready); break; }
                usleep(20_000);
            }
            self::assertNotNull($port);
            $mailer = new Mailer(['default' => 'smtp', 'transports' => ['smtp' => [
                'driver' => 'smtp', 'host' => '127.0.0.1', 'port' => $port,
                'encryption' => 'none', 'timeout' => 5, 'username' => 'mail-user',
                'password' => 'mail-pass']]]);
            $mailer->send((new MailMessage())->from('sender@example.test')
                ->to('recipient@example.test')->subject('Authenticated')->text('Body'));
            for ($i = 0; $i < 100 && !is_file($capture); ++$i) usleep(20_000);
            self::assertFileExists($capture);
            $record = json_decode((string) file_get_contents($capture), true, 512, JSON_THROW_ON_ERROR);
            self::assertTrue($record['authenticated']);
            self::assertStringNotContainsString('mail-pass', $record['data']);
        } finally {
            if (!is_file($capture)) proc_terminate($process);
            proc_close($process);
            $project->remove();
        }
    }

    public function testUnavailableLocalPortRaisesSafeMailException(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        fclose($socket);
        $mailer = new Mailer(['default' => 'smtp', 'transports' => ['smtp' => [
            'driver' => 'smtp', 'host' => '127.0.0.1', 'port' => $port,
            'encryption' => 'none', 'timeout' => 1]]]);
        try {
            $mailer->send((new MailMessage())->from('sender@example.test')
                ->to('private@example.test')->subject('Private')->text('Sensitive body'));
            self::fail('Expected connection failure.');
        } catch (MailException $failure) {
            self::assertSame('SMTP send failed.', $failure->getMessage());
            self::assertStringNotContainsString('private@example.test', $failure->getMessage());
            self::assertNull($failure->getPrevious());
        }
    }

    public function testSmtpServerFailureDoesNotExposeProviderDiagnostic(): void
    {
        $project = new TemporaryProject();
        $ready = $project->path('smtp-port.txt');
        $capture = $project->path('smtp-capture.json');
        $command = [PHP_BINARY, dirname(__DIR__) . '/Fixtures/LocalSmtpServer.php',
            $ready, $capture, 'reject'];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $project->path('smtp-out.txt'), 'w'],
            2 => ['file', $project->path('smtp-err.txt'), 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            $port = null;
            for ($i = 0; $i < 100; ++$i) {
                if (is_file($ready)) { $port = (int) file_get_contents($ready); break; }
                usleep(20_000);
            }
            self::assertNotNull($port);
            $mailer = new Mailer(['default' => 'smtp', 'transports' => ['smtp' => [
                'driver' => 'smtp', 'host' => '127.0.0.1', 'port' => $port,
                'encryption' => 'none', 'timeout' => 5]]]);
            try {
                $mailer->send((new MailMessage())->from('sender@example.test')
                    ->to('secret-recipient@example.test')->subject('Secret subject')->text('Secret body'));
                self::fail('Expected SMTP failure.');
            } catch (MailException $failure) {
                self::assertSame('SMTP send failed.', $failure->getMessage());
                self::assertNull($failure->getPrevious());
                self::assertStringNotContainsString('secret-recipient@example.test', $failure->getMessage());
            }
        } finally {
            proc_close($process);
            $project->remove();
        }
    }

    public function testLocalSmtpEnvelopeAndMime(): void
    {
        $project = new TemporaryProject();
        $ready = $project->path('smtp-port.txt');
        $capture = $project->path('smtp-capture.json');
        $fixture = dirname(__DIR__) . '/Fixtures/LocalSmtpServer.php';
        $command = [PHP_BINARY, $fixture, $ready, $capture];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $project->path('smtp-out.txt'), 'w'],
            2 => ['file', $project->path('smtp-err.txt'), 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            $port = null;
            for ($i = 0; $i < 100; ++$i) {
                if (is_file($ready)) { $port = (int) file_get_contents($ready); break; }
                usleep(20_000);
            }
            self::assertNotNull($port, 'Loopback SMTP fixture did not start.');
            $mailer = new Mailer(['default' => 'smtp', 'from' => ['address' => 'sender@example.test'],
                'transports' => ['smtp' => ['driver' => 'smtp', 'host' => '127.0.0.1', 'port' => $port,
                    'encryption' => 'none', 'timeout' => 5]]]);
            self::assertFileExists($ready);
            $mailer->send((new MailMessage())->to('to@example.test')->cc('cc@example.test')
                ->bcc('bcc@example.test')->replyTo('reply@example.test')
                ->subject('Local SMTP')->text('Plain part')->html('<p>HTML part</p>')
                ->attachBytes('attachment bytes', 'report.txt', 'text/plain'));
            for ($i = 0; $i < 100 && !is_file($capture); ++$i) usleep(20_000);
            self::assertFileExists($capture);
            $record = json_decode((string) file_get_contents($capture), true, 512, JSON_THROW_ON_ERROR);
            $envelope = implode("\n", $record['envelope']);
            self::assertStringContainsString('MAIL FROM:<sender@example.test>', $envelope);
            foreach (['to@example.test', 'cc@example.test', 'bcc@example.test'] as $address) {
                self::assertStringContainsString('RCPT TO:<' . $address . '>', $envelope);
            }
            $headers = explode("\r\n\r\n", $record['data'], 2)[0];
            self::assertStringContainsString('Subject: Local SMTP', $headers);
            self::assertStringContainsString('To: to@example.test', $headers);
            self::assertStringContainsString('Cc: cc@example.test', $headers);
            self::assertStringContainsString('Reply-To: reply@example.test', $headers);
            self::assertStringNotContainsString('bcc@example.test', $headers);
            self::assertStringContainsString('report.txt', $record['data']);
            self::assertStringContainsString('Plain part', $record['data']);
            self::assertStringContainsString('HTML part', $record['data']);
            self::assertStringContainsString(base64_encode('attachment bytes'), $record['data']);
        } finally {
            if (!is_file($capture)) proc_terminate($process);
            proc_close($process);
            $project->remove();
        }
    }
}
