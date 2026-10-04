<?php

declare(strict_types=1);

namespace App\Mail\Transports;

use App\Mail\MailConfigurationException;
use App\Mail\MailException;
use App\Mail\MailMessage;
use App\Mail\MailTransport;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use Throwable;

/**
 * PHPMailer adapter. Each send gets fresh recipient/body/attachment state and
 * opens its SMTP connection only when send() executes. SMTP transcripts stay
 * disabled; provider errors are translated without echoing message content.
 */
final class SmtpMailTransport implements MailTransport
{
    /** @param array<string,mixed> $settings */
    public function __construct(private array $settings)
    {
    }

    /** Do not expose SMTP credentials when a transport is inspected. */
    public function __debugInfo(): array { return ['driver' => 'smtp']; }

    public function send(MailMessage $message): void
    {
        $settings = $this->validatedSettings();
        $client = new PHPMailer(true);
        $client->isSMTP();
        $client->CharSet = PHPMailer::CHARSET_UTF8;
        $client->SMTPDebug = SMTP::DEBUG_OFF;
        $client->SMTPKeepAlive = false;
        $client->Host = $settings['host'];
        $client->Port = $settings['port'];
        $client->Timeout = $settings['timeout'];
        $client->SMTPAuth = $settings['username'] !== null;
        if ($client->SMTPAuth) {
            $client->Username = $settings['username'];
            $client->Password = $settings['password'];
        }
        // Explicit "none" disables PHPMailer's opportunistic STARTTLS. Secure
        // modes retain certificate verification unless deliberately configured.
        $client->SMTPSecure = match ($settings['encryption']) {
            'tls' => PHPMailer::ENCRYPTION_STARTTLS,
            'ssl' => PHPMailer::ENCRYPTION_SMTPS,
            'none' => '',
            default => throw new MailConfigurationException('SMTP encryption setting is invalid.'),
        };
        $client->SMTPAutoTLS = $settings['encryption'] !== 'none';
        $client->SMTPOptions = ['ssl' => [
            'verify_peer' => $settings['verify_peer'],
            'verify_peer_name' => $settings['verify_peer'],
            'allow_self_signed' => $settings['allow_self_signed'],
        ]];

        try {
            $from = $message->sender();
            $client->setFrom($from->address(), $from->name() ?? '');
            foreach ($message->recipients() as $address) $client->addAddress($address->address(), $address->name() ?? '');
            foreach ($message->carbonCopies() as $address) $client->addCC($address->address(), $address->name() ?? '');
            foreach ($message->blindCopies() as $address) $client->addBCC($address->address(), $address->name() ?? '');
            foreach ($message->replyAddresses() as $address) $client->addReplyTo($address->address(), $address->name() ?? '');
            $client->Subject = $message->subjectLine();
            if ($message->htmlBody() !== null && $message->htmlBody() !== '') {
                $client->isHTML(true);
                $client->Body = $message->htmlBody();
                if ($message->textBody() !== null) $client->AltBody = $message->textBody();
            } else {
                $client->isHTML(false);
                $client->Body = $message->textBody() ?? '';
            }
            foreach ($message->attachments() as $attachment) {
                $client->addStringAttachment($attachment->contents(), $attachment->filename(),
                    PHPMailer::ENCODING_BASE64, $attachment->mime());
            }
            if (!$client->send()) throw new MailException('SMTP send failed.');
        } catch (Throwable) {
            // PHPMailer diagnostics may quote recipients, message data, or
            // credentials. Do not preserve that exception in a public chain.
            throw new MailException('SMTP send failed.');
        }
    }

    /** @return array{host:string,port:int,timeout:int,encryption:string,username:?string,password:?string,verify_peer:bool,allow_self_signed:bool} */
    private function validatedSettings(): array
    {
        $host = $this->settings['host'] ?? null;
        $port = $this->settings['port'] ?? 587;
        $timeout = $this->settings['timeout'] ?? 10;
        $encryption = $this->settings['encryption'] ?? 'tls';
        $username = $this->settings['username'] ?? null;
        $password = $this->settings['password'] ?? null;
        $verify = $this->settings['verify_peer'] ?? true;
        $selfSigned = $this->settings['allow_self_signed'] ?? false;
        if (!is_string($host) || $host === '' || strlen($host) > 255
            || preg_match('/\A[A-Za-z0-9.\-:\[\]]+\z/D', $host) !== 1) {
            throw new MailConfigurationException('SMTP host is not configured or invalid.');
        }
        if (!is_int($port) || $port < 1 || $port > 65535
            || !is_int($timeout) || $timeout < 1 || $timeout > 120
            || !is_string($encryption) || !in_array($encryption, ['tls', 'ssl', 'none'], true)
            || !is_bool($verify) || !is_bool($selfSigned)) {
            throw new MailConfigurationException('Invalid SMTP transport settings.');
        }
        if (($username !== null && (!is_string($username) || $username === ''))
            || ($password !== null && (!is_string($password) || $password === ''))
            || (($username === null) !== ($password === null))) {
            throw new MailConfigurationException('SMTP credentials must be a complete pair.');
        }
        return ['host' => $host, 'port' => $port, 'timeout' => $timeout,
            'encryption' => $encryption, 'username' => $username, 'password' => $password,
            'verify_peer' => $verify, 'allow_self_signed' => $selfSigned];
    }
}
