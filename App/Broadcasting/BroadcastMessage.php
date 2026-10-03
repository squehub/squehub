<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Support\SensitiveKey;
use JsonException;
use Throwable;

/**
 * A bounded, immutable publication snapshot. No Request, Model, Session, or
 * arbitrary object graph can cross the adapter or Queue boundary.
 */
final readonly class BroadcastMessage
{
    /** @param list<Channel> $channels
     *  @param array<string|int,mixed> $payload
     */
    private function __construct(
        private string $name,
        private array $channels,
        private array $payload,
        int $maxBytes
    ) {
        if (strlen($name) > 128
            || preg_match('/\A[A-Za-z][A-Za-z0-9_.-]*\z/D', $name) !== 1
            || str_contains($name, '..')) {
            throw new BroadcastException('Broadcast event name is invalid.');
        }
        if ($channels === [] || !array_is_list($channels) || count($channels) > 16) {
            throw new BroadcastException('Broadcast channel count is invalid.');
        }
        $seen = [];
        foreach ($channels as $channel) {
            if (!$channel instanceof Channel) {
                throw new BroadcastException('Broadcast channel is invalid.');
            }
            $key = $channel->type() . ':' . $channel->name();
            if (isset($seen[$key])) throw new BroadcastException('Broadcast channel is repeated.');
            $seen[$key] = true;
        }
        self::validateValue($payload, 0);
        try {
            $json = json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $failure) {
            throw new BroadcastException('Broadcast payload is not valid JSON data.');
        }
        if (strlen($json) > $maxBytes) {
            throw new BroadcastException('Broadcast payload exceeds the supported size.');
        }
    }

    public static function capture(BroadcastEvent $event, int $maxBytes): self
    {
        try {
            $name = $event->broadcastName();
            $channels = $event->broadcastChannels();
            $payload = $event->broadcastPayload();
        } catch (Throwable) {
            // Application code may include private data in its exception text.
            throw new BroadcastException('Broadcast event could not be captured.');
        }
        return new self($name, $channels, $payload, $maxBytes);
    }

    /** @param array<string|int,mixed> $data */
    public static function fromArray(array $data, int $maxBytes): self
    {
        if (array_keys($data) !== ['name', 'channels', 'payload']
            || !is_string($data['name']) || !is_array($data['channels'])
            || !array_is_list($data['channels']) || !is_array($data['payload'])) {
            throw new BroadcastException('Queued broadcast message is invalid.');
        }
        $channels = [];
        foreach ($data['channels'] as $entry) {
            if (!is_array($entry) || array_keys($entry) !== ['type', 'name']
                || !is_string($entry['name'] ?? null)) {
                throw new BroadcastException('Queued broadcast channel is invalid.');
            }
            $channels[] = match ($entry['type']) {
                'public' => Channel::public($entry['name']),
                'private' => Channel::private($entry['name']),
                default => throw new BroadcastException('Queued broadcast channel type is invalid.'),
            };
        }
        return new self($data['name'], $channels, $data['payload'], $maxBytes);
    }

    public function name(): string { return $this->name; }
    /** @return list<Channel> */
    public function channels(): array { return $this->channels; }
    /** @return array<string|int,mixed> */
    public function payload(): array { return $this->payload; }

    /** @return array{name:string,channels:list<array{type:string,name:string}>,payload:array<string|int,mixed>} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'channels' => array_map(static fn (Channel $channel): array =>
                ['type' => $channel->type(), 'name' => $channel->name()], $this->channels),
            'payload' => $this->payload,
        ];
    }

    /** Reject obvious credential fields without claiming to recognize secret values. */
    private static function validateValue(mixed $value, int $depth): void
    {
        if ($depth > 16) throw new BroadcastException('Broadcast payload nesting is too deep.');
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_string($key) && ($key === '' || strlen($key) > 128
                    || preg_match('//u', $key) !== 1
                    || preg_match('/[\x00-\x1F\x7F]/', $key)
                    || SensitiveKey::matches($key))) {
                    throw new BroadcastException('Broadcast payload field is invalid or sensitive.');
                }
                self::validateValue($item, $depth + 1);
            }
            return;
        }
        if ($value === null || is_bool($value) || is_int($value)
            || (is_float($value) && is_finite($value))) return;
        if (is_string($value) && preg_match('//u', $value) === 1) return;
        throw new BroadcastException('Broadcast payload contains an unsupported value.');
    }
}
