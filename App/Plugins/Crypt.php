<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Cryptography\Crypt as Gateway;
use App\Cryptography\CryptManager;

/** Stable application API for authenticated encryption, keyed MACs, and tokens. */
final class Crypt
{
    public static function manager(): CryptManager { return Gateway::manager(); }

    public static function encrypt(#[\SensitiveParameter] string $plaintext,
        #[\SensitiveParameter] string $purpose = ''): string
    { return self::manager()->encrypt($plaintext, $purpose); }

    public static function decrypt(#[\SensitiveParameter] string $payload,
        #[\SensitiveParameter] string $purpose = ''): string
    { return self::manager()->decrypt($payload, $purpose); }

    public static function sign(#[\SensitiveParameter] string $data,
        #[\SensitiveParameter] string $purpose = ''): string
    { return self::manager()->sign($data, $purpose); }

    public static function verify(#[\SensitiveParameter] string $data,
        #[\SensitiveParameter] string $signature, #[\SensitiveParameter] string $purpose = ''): bool
    { return self::manager()->verify($data, $signature, $purpose); }

    public static function randomToken(int $bytes = 32): string
    { return self::manager()->randomToken($bytes); }
}
