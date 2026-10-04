<?php

declare(strict_types=1);

namespace App\Mfa\Repositories;

use InvalidArgumentException;
use LogicException;

/** Deterministic, Application-local MFA storage for tests and ephemeral use. */
final class ArrayMfaRepository implements MfaRepository
{
    /** @var array<string,array{identity:MfaIdentity,enabled:bool,encrypted_secret:?string,
     *   pending_secret:?string,pending_expires_at:?int,last_counter:?int}> */
    private array $records = [];
    /** @var array<string,list<string>> */
    private array $codes = [];

    public function find(MfaIdentity $identity): ?array
    {
        $this->assertIdentity($identity);
        $record = $this->records[$identity->digest] ?? null;
        if ($record === null) return null;
        unset($record['identity']);
        return $record;
    }

    public function savePending(MfaIdentity $identity, string $encryptedSecret, int $expiresAt): void
    {
        self::assertCiphertext($encryptedSecret);
        if ($expiresAt <= 0) throw new InvalidArgumentException('MFA enrollment expiry is invalid.');
        $this->assertIdentity($identity);
        if (($this->records[$identity->digest]['enabled'] ?? false) === true) {
            throw new LogicException('Enabled MFA cannot be replaced by pending enrollment.');
        }
        $this->records[$identity->digest] = [
            'identity' => $identity, 'enabled' => false, 'encrypted_secret' => null,
            'pending_secret' => $encryptedSecret, 'pending_expires_at' => $expiresAt,
            'last_counter' => null,
        ];
        unset($this->codes[$identity->digest]);
    }

    public function activate(MfaIdentity $identity, string $expectedPendingCiphertext,
        int $confirmedCounter, array $recoveryHashes, int $now): bool
    {
        self::assertCiphertext($expectedPendingCiphertext);
        if ($confirmedCounter < 0 || $now < 0) {
            throw new InvalidArgumentException('MFA confirmation is invalid.');
        }
        self::assertHashes($recoveryHashes);
        $this->assertIdentity($identity);
        $record = $this->records[$identity->digest] ?? null;
        if ($record === null || $record['enabled']
            || $record['pending_secret'] === null
            || !hash_equals($record['pending_secret'], $expectedPendingCiphertext)
            || $record['pending_expires_at'] === null
            || $record['pending_expires_at'] <= $now) return false;
        $record['enabled'] = true;
        $record['encrypted_secret'] = $expectedPendingCiphertext;
        $record['pending_secret'] = null;
        $record['pending_expires_at'] = null;
        $record['last_counter'] = $confirmedCounter;
        $this->records[$identity->digest] = $record;
        $this->codes[$identity->digest] = array_values($recoveryHashes);
        return true;
    }

    public function claimCounter(MfaIdentity $identity, int $counter, string $secretHash): bool
    {
        if ($counter < 0) throw new InvalidArgumentException('MFA counter is invalid.');
        self::assertHash($secretHash);
        $this->assertIdentity($identity);
        $record = $this->records[$identity->digest] ?? null;
        if ($record === null || !$record['enabled'] || $record['last_counter'] === null
            || $record['encrypted_secret'] === null
            || !hash_equals(hash('sha256', $record['encrypted_secret']), $secretHash)
            || $counter <= $record['last_counter']) return false;
        $this->records[$identity->digest]['last_counter'] = $counter;
        return true;
    }

    public function claimRecoveryCode(MfaIdentity $identity, string $hash, string $secretHash): bool
    {
        self::assertHash($hash);
        self::assertHash($secretHash);
        $this->assertIdentity($identity);
        $record = $this->records[$identity->digest] ?? null;
        if ($record === null || !$record['enabled'] || $record['encrypted_secret'] === null
            || !hash_equals(hash('sha256', $record['encrypted_secret']), $secretHash)) return false;
        foreach ($this->codes[$identity->digest] ?? [] as $index => $stored) {
            if (hash_equals($stored, $hash)) {
                unset($this->codes[$identity->digest][$index]);
                $this->codes[$identity->digest] = array_values($this->codes[$identity->digest]);
                return true;
            }
        }
        return false;
    }

    public function replaceRecoveryCodes(MfaIdentity $identity, array $hashes,
        string $secretHash): bool
    {
        self::assertHashes($hashes);
        self::assertHash($secretHash);
        $this->assertIdentity($identity);
        $record = $this->records[$identity->digest] ?? null;
        if ($record === null || !$record['enabled'] || $record['encrypted_secret'] === null
            || !hash_equals(hash('sha256', $record['encrypted_secret']), $secretHash)) return false;
        $this->codes[$identity->digest] = array_values($hashes);
        return true;
    }

    public function disable(MfaIdentity $identity, string $secretHash): bool
    {
        self::assertHash($secretHash);
        $this->assertIdentity($identity);
        $record = $this->records[$identity->digest] ?? null;
        if ($record === null || !$record['enabled'] || $record['encrypted_secret'] === null
            || !hash_equals(hash('sha256', $record['encrypted_secret']), $secretHash)) return false;
        unset($this->records[$identity->digest], $this->codes[$identity->digest]);
        return true;
    }

    private function assertIdentity(MfaIdentity $identity): void
    {
        $stored = $this->records[$identity->digest]['identity'] ?? null;
        if ($stored !== null && !$stored->sameAs($identity)) {
            throw new LogicException('Stored MFA identity digest is inconsistent.');
        }
    }

    private static function assertCiphertext(string $ciphertext): void
    {
        if ($ciphertext === '' || strlen($ciphertext) > 4096) {
            throw new InvalidArgumentException('MFA encrypted secret is invalid.');
        }
    }

    private static function assertHash(string $hash): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1) {
            throw new InvalidArgumentException('MFA recovery hash is invalid.');
        }
    }

    private static function assertHashes(array $hashes): void
    {
        if (count($hashes) > 64 || !array_is_list($hashes)) {
            throw new InvalidArgumentException('MFA recovery hashes are invalid.');
        }
        $seen = [];
        foreach ($hashes as $hash) {
            if (!is_string($hash)) throw new InvalidArgumentException('MFA recovery hash is invalid.');
            self::assertHash($hash);
            if (isset($seen[$hash])) throw new InvalidArgumentException('MFA recovery hashes repeat.');
            $seen[$hash] = true;
        }
    }
}
