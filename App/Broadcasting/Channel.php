<?php

declare(strict_types=1);

namespace App\Broadcasting;

/** A channel's visibility is explicit; its name is never a browser authority. */
final readonly class Channel
{
    private function __construct(private string $type, private string $name)
    {
        self::validateName($name);
    }

    public static function public(string $name): self { return new self('public', $name); }
    public static function private(string $name): self { return new self('private', $name); }

    public function type(): string { return $this->type; }
    public function name(): string { return $this->name; }

    public static function validName(string $name): bool
    {
        return strlen($name) <= 128
            && preg_match('/\A[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*\z/D', $name) === 1;
    }

    public static function validateName(string $name): void
    {
        if (!self::validName($name)) throw new BroadcastException('Broadcast channel name is invalid.');
    }
}
