<?php

declare(strict_types=1);

namespace App\Mail\Transports;

use App\HttpClient\HttpResponse;
use App\Mail\MailConfigurationException;
use App\Mail\MailMessage;
use App\Mail\MailProviderException;

/** Postmark's single-email API, including its JSON-level ErrorCode contract. */
final class PostmarkMailTransport extends ApiMailTransport
{
    protected function driver(): string { return 'postmark'; }
    protected function endpoint(): string { return 'https://api.postmarkapp.com/email'; }

    protected function authenticationHeaders(#[\SensitiveParameter] string $token): array
    {
        return ['X-Postmark-Server-Token' => $token];
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
            'From' => self::addresses([$message->sender()])[0],
            'To' => implode(', ', $to),
            'Subject' => $message->subjectLine(),
        ];
        if ($cc !== []) $payload['Cc'] = implode(', ', $cc);
        if ($bcc !== []) $payload['Bcc'] = implode(', ', $bcc);
        if ($message->replyAddresses() !== []) {
            $payload['ReplyTo'] = implode(', ', self::addresses($message->replyAddresses()));
        }
        if ($message->textBody() !== null) $payload['TextBody'] = $message->textBody();
        if ($message->htmlBody() !== null) $payload['HtmlBody'] = $message->htmlBody();
        if ($message->attachments() !== []) {
            $payload['Attachments'] = [];
            foreach ($message->attachments() as $attachment) {
                $payload['Attachments'][] = [
                    'Name' => $attachment->filename(),
                    'Content' => base64_encode($attachment->contents()),
                    'ContentType' => $attachment->mime(),
                ];
            }
        }
        return $payload;
    }

    protected function assertAccepted(HttpResponse $response, mixed $data): void
    {
        if (!is_array($data) || !is_int($data['ErrorCode'] ?? null)) {
            throw new MailProviderException('malformed');
        }
        if ($data['ErrorCode'] !== 0) {
            // A 200 response can still carry a provider-level rejection.
            throw new MailProviderException('rejected');
        }
        if ($response->status() !== 200 || !self::acceptedId($data['MessageID'] ?? null)) {
            throw new MailProviderException('malformed');
        }
    }
}
