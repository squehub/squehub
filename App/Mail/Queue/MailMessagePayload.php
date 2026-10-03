<?php

declare(strict_types=1);

namespace App\Mail\Queue;

use App\Mail\MailAddress;
use App\Mail\MailConfigurationException;
use App\Mail\MailMessage;
use Throwable;

/**
 * Versioned, explicit Mail representation for the bounded Queue JSON codec.
 * Byte attachments are rejected rather than silently lost or persisted in an
 * oversized database row. Queue records contain sensitive message content.
 */
final class MailMessagePayload
{
    /** @return array<string, mixed> */
    public static function encode(MailMessage $message): array
    {
        if ($message->attachments() !== []) {
            throw new MailConfigurationException('Queued Mail does not support attachments.');
        }
        return [
            'version' => 1,
            'from' => self::address($message->sender()),
            'to' => array_map(self::address(...), $message->recipients()),
            'cc' => array_map(self::address(...), $message->carbonCopies()),
            'bcc' => array_map(self::address(...), $message->blindCopies()),
            'reply_to' => array_map(self::address(...), $message->replyAddresses()),
            'subject' => $message->subjectLine(),
            'text' => $message->textBody(),
            'html' => $message->htmlBody(),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function decode(array $data): MailMessage
    {
        try {
            $fields = ['version', 'from', 'to', 'cc', 'bcc', 'reply_to', 'subject', 'text', 'html'];
            $present = array_keys($data);
            sort($fields);
            sort($present);
            // Unknown fields cannot silently disappear during reconstruction;
            // an attachment key, for example, must never be dropped.
            if ($present !== $fields) throw new MailConfigurationException('Queued Mail payload is invalid.');
            if (($data['version'] ?? null) !== 1 || !array_key_exists('from', $data)
                || !is_array($data['to'] ?? null) || !is_array($data['cc'] ?? null)
                || !is_array($data['bcc'] ?? null) || !is_array($data['reply_to'] ?? null)
                || !is_string($data['subject'] ?? null)
                || (!is_string($data['text'] ?? null) && !is_string($data['html'] ?? null))) {
                throw new MailConfigurationException('Queued Mail payload is invalid.');
            }
            $message = new MailMessage();
            if ($data['from'] !== null) {
                [$address, $name] = self::restoreAddress($data['from']);
                $message->from($address, $name);
            }
            foreach (['to', 'cc', 'bcc', 'reply_to'] as $field) {
                if (!array_is_list($data[$field])) throw new MailConfigurationException('Queued Mail payload is invalid.');
                foreach ($data[$field] as $entry) {
                    [$address, $name] = self::restoreAddress($entry);
                    match ($field) {
                        'to' => $message->to($address, $name),
                        'cc' => $message->cc($address, $name),
                        'bcc' => $message->bcc($address, $name),
                        'reply_to' => $message->replyTo($address, $name),
                    };
                }
            }
            $message->subject($data['subject']);
            if (is_string($data['text'] ?? null)) $message->text($data['text']);
            if (is_string($data['html'] ?? null)) $message->html($data['html']);
            return $message;
        } catch (Throwable) {
            throw new MailConfigurationException('Queued Mail payload is invalid.');
        }
    }

    /** @return ?array{address:string,name:?string} */
    private static function address(?MailAddress $address): ?array
    {
        return $address === null ? null : ['address' => $address->address(), 'name' => $address->name()];
    }

    /** @return array{string, ?string} */
    private static function restoreAddress(mixed $value): array
    {
        if (!is_array($value) || !is_string($value['address'] ?? null)
            || !(is_string($value['name'] ?? null) || ($value['name'] ?? null) === null)) {
            throw new MailConfigurationException('Queued Mail address is invalid.');
        }
        $address = new MailAddress($value['address'], $value['name'] ?? null);
        return [$address->address(), $address->name()];
    }
}
