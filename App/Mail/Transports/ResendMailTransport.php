<?php

declare(strict_types=1);

namespace App\Mail\Transports;

use App\HttpClient\HttpResponse;
use App\Mail\MailConfigurationException;
use App\Mail\MailMessage;
use App\Mail\MailProviderException;

/** Resend's documented single-email JSON API, with no provider SDK dependency. */
final class ResendMailTransport extends ApiMailTransport
{
    protected function driver(): string { return 'resend'; }
    protected function endpoint(): string { return 'https://api.resend.com/emails'; }

    protected function authenticationHeaders(#[\SensitiveParameter] string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    protected function payload(MailMessage $message): array
    {
        $to = self::addresses($message->recipients());
        $cc = self::addresses($message->carbonCopies());
        $bcc = self::addresses($message->blindCopies());
        if (count($to) + count($cc) + count($bcc) > 50) {
            throw new MailConfigurationException('Mail provider recipient limit was exceeded.');
        }
        $payload = [
            'from' => self::addresses([$message->sender()])[0],
            'to' => $to,
            'subject' => $message->subjectLine(),
        ];
        if ($cc !== []) $payload['cc'] = $cc;
        if ($bcc !== []) $payload['bcc'] = $bcc;
        if ($message->replyAddresses() !== []) $payload['reply_to'] = self::addresses($message->replyAddresses());
        if ($message->textBody() !== null) $payload['text'] = $message->textBody();
        if ($message->htmlBody() !== null) $payload['html'] = $message->htmlBody();
        if ($message->attachments() !== []) {
            $payload['attachments'] = [];
            foreach ($message->attachments() as $attachment) {
                // Resend's documented attachment input has no MIME field.
                // Reject an explicit custom MIME rather than losing it.
                if ($attachment->mime() !== 'application/octet-stream') {
                    throw new MailConfigurationException('Resend cannot preserve a custom attachment MIME type.');
                }
                $payload['attachments'][] = [
                    'filename' => $attachment->filename(),
                    'content' => base64_encode($attachment->contents()),
                ];
            }
        }
        return $payload;
    }

    protected function assertAccepted(HttpResponse $response, mixed $data): void
    {
        if (!is_array($data) || !self::acceptedId($data['id'] ?? null)) {
            throw new MailProviderException('malformed');
        }
        // The bounded provider ID confirms acceptance but does not change the
        // established void MailTransport/Mailer result contract.
    }
}
