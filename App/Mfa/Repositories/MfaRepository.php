<?php

declare(strict_types=1);

namespace App\Mfa\Repositories;

/** The successful write is the one-winner boundary for TOTP and recovery. */
interface MfaRepository
{
    /**
     * @return array{enabled:bool,encrypted_secret:?string,pending_secret:?string,
     *   pending_expires_at:?int,last_counter:?int}|null
     */
    public function find(MfaIdentity $identity): ?array;

    /** Refuses to replace an enabled enrollment. Expiry is a Unix epoch second. */
    public function savePending(MfaIdentity $identity, string $encryptedSecret, int $expiresAt): void;

    /** Confirm once, using the matched TOTP counter as the first claimed counter. */
    public function activate(MfaIdentity $identity, string $expectedPendingCiphertext,
        int $confirmedCounter, array $recoveryHashes, int $now): bool;

    /** Advance the persisted counter only when it is strictly newer. */
    public function claimCounter(MfaIdentity $identity, int $counter, string $secretHash): bool;

    /** Delete one canonical SHA-256 hash; a second claimant must fail. */
    public function claimRecoveryCode(MfaIdentity $identity, string $hash, string $secretHash): bool;

    /** Atomically replace all remaining recovery hashes for an enabled enrollment. */
    public function replaceRecoveryCodes(MfaIdentity $identity, array $hashes,
        string $secretHash): bool;

    /** Revoke the enrollment and its recovery hashes. */
    public function disable(MfaIdentity $identity, string $secretHash): bool;
}
