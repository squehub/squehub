<?php

// app/core/verification.php

namespace App\Core;

use PDO;
use App\Core\Database;
use App\Core\Model;

class Verification
{
    /**
     * Connect to the database using the core Database class.
     */
    protected static function db(): PDO
    {
        return Database::connect();
    }


    // =========================================================================
    // 🔐 GENERATORS
    // =========================================================================


    /**
     * Generate a verification code (OTP).
     *
     * @param int $length The length of the code (default 6).
     * @param string $type Type of characters to include: 'numeric', 'alphabet', or 'alphanumeric'.
     * @return string
     */
    public static function generateCode(int $length = 6, string $type = 'numeric'): string
    {
        $length = max(4, (int) $length);

        $characters = [
            'numeric' => '0123456789',
            'alphabet' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz',
            'alphanumeric' => '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz',
        ];

        $charset = $characters[$type] ?? $characters['numeric'];
        $otp = '';
        $maxIndex = strlen($charset) - 1;

        for ($i = 0; $i < $length; $i++) {
            $otp .= $charset[random_int(0, $maxIndex)];
        }

        return $otp;
    }

    /**
     * Generate a cryptographically secure random token.
     *
     * @param int $length Length of the token.
     * @return string
     */
    public static function generateToken(int $length = 64): string
    {
        return bin2hex(random_bytes($length / 2));
    }


    // =========================================================================
    // 🧱 CREATION / UPDATE
    // =========================================================================


    /**
     * Create a new verification entry in the database.
     *
     * @param array $data Data to insert.
     * @return int Inserted record ID.
     */
    public static function create(array $data): int
    {
        $pdo = self::db();

        $stmt = $pdo->prepare("
            INSERT INTO verification_codes (user_id, email, phone, code, token, type, expires_at)
            VALUES (:user_id, :email, :phone, :code, :token, :type, :expires_at)
        ");

        $stmt->execute([
            'user_id' => $data['user_id'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'code' => $data['code'] ?? null,
            'token' => $data['token'] ?? null,
            'type' => $data['type'] ?? 'otp',
            'expires_at' => $data['expires_at'],
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Update a verification entry by ID.
     *
     * @param int $id Entry ID.
     * @param array $data Fields to update.
     * @return bool
     */
    public static function update(int $id, array $data): bool
    {
        $pdo = self::db();

        $stmt = $pdo->prepare("
            UPDATE verification_codes SET
                code = :code,
                token = :token,
                expires_at = :expires_at,
                is_used = :is_used,
                is_expired = :is_expired
            WHERE id = :id
        ");

        return $stmt->execute([
            'code' => $data['code'] ?? null,
            'token' => $data['token'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'is_used' => $data['is_used'] ?? 0,
            'is_expired' => $data['is_expired'] ?? 0,
            'id' => $id,
        ]);
    }


    // =========================================================================
    // 🔍 RETRIEVAL & VALIDATION
    // =========================================================================


    /**
     * Retrieve a valid (not expired/used) code by value and type.
     */
    public static function findValidCode(string $code, string $type): ?array
    {
        $pdo = self::db();

        $stmt = $pdo->prepare("
            SELECT * FROM verification_codes
            WHERE code = :code AND type = :type
              AND is_used = 0 AND is_expired = 0
              AND expires_at > NOW()
            LIMIT 1
        ");

        $stmt->execute(['code' => $code, 'type' => $type]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Retrieve a valid (not expired/used) token by value and type.
     */
    public static function findValidToken(string $token, string $type): ?array
    {
        $pdo = self::db();

        $stmt = $pdo->prepare("
            SELECT * FROM verification_codes
            WHERE token = :token AND type = :type
              AND is_used = 0 AND is_expired = 0
              AND expires_at > NOW()
            LIMIT 1
        ");

        $stmt->execute(['token' => $token, 'type' => $type]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }


    // =========================================================================
    // ✅ CHECKS
    // =========================================================================


    /**
     * Check if a token exists in the system.
     */
    public static function tokenExists(string $token, string $type): bool
    {
        $pdo = self::db();

        $stmt = $pdo->prepare("SELECT id FROM verification_codes WHERE token = :token AND type = :type LIMIT 1");
        $stmt->execute(['token' => $token, 'type' => $type]);

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Determine if a token has expired.
     */
    public static function tokenIsExpired(string $token, string $type): bool
    {
        $pdo = self::db();

        $stmt = $pdo->prepare("SELECT id, expires_at FROM verification_codes WHERE token = :token AND type = :type LIMIT 1");
        $stmt->execute(['token' => $token, 'type' => $type]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record)
            return false;

        $isExpired = strtotime($record['expires_at']) < time();

        if ($isExpired) {
            $update = $pdo->prepare("UPDATE verification_codes SET is_expired = 1, updated_at = NOW() WHERE id = :id");
            $update->execute(['id' => $record['id']]);
        }

        return $isExpired;
    }

    /**
     * Check if a token has been used already.
     */
    public static function tokenIsUsed(string $token, string $type): bool
    {
        $pdo = self::db();

        $stmt = $pdo->prepare("SELECT is_used FROM verification_codes WHERE token = :token AND type = :type LIMIT 1");
        $stmt->execute(['token' => $token, 'type' => $type]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record)
            return false;

        return (int) $record['is_used'] === 1;
    }

    /**
     * Check if a code exists in the system.
     */
    public static function codeExists(string $code, string $type): bool
    {
        $pdo = self::db();

        $stmt = $pdo->prepare("SELECT id FROM verification_codes WHERE code = :code AND type = :type LIMIT 1");
        $stmt->execute(['code' => $code, 'type' => $type]);

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Determine if a code has expired.
     */
    public static function codeIsExpired(string $code, string $type): bool
    {
        $pdo = self::db();

        $stmt = $pdo->prepare("SELECT id, expires_at FROM verification_codes WHERE code = :code AND type = :type LIMIT 1");
        $stmt->execute(['code' => $code, 'type' => $type]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record)
            return false;

        $isExpired = strtotime($record['expires_at']) < time();

        if ($isExpired) {
            $update = $pdo->prepare("UPDATE verification_codes SET is_expired = 1, updated_at = NOW() WHERE id = :id");
            $update->execute(['id' => $record['id']]);
        }

        return $isExpired;
    }

    /**
     * Check if a code has already been used.
     */
    public static function codeIsUsed(string $code, string $type): bool
    {
        $pdo = self::db();

        $stmt = $pdo->prepare("SELECT is_used FROM verification_codes WHERE code = :code AND type = :type LIMIT 1");
        $stmt->execute(['code' => $code, 'type' => $type]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record)
            return false;

        return (int) $record['is_used'] === 1;
    }



    // =========================================================================
    // 📌 MARKING
    // =========================================================================


    /**
     * Mark a code as used (by ID).
     */
    public static function markUsed(int $id): void
    {
        $pdo = self::db();

        $stmt = $pdo->prepare("
            UPDATE verification_codes
            SET is_used = 1, updated_at = NOW()
            WHERE id = :id
        ");

        $stmt->execute(['id' => $id]);
    }

    /**
     * Mark a token as used.
     */
    public static function markUsedByToken(string $token): void
    {
        $pdo = self::db();

        $stmt = $pdo->prepare("
            UPDATE verification_codes
            SET is_used = 1, updated_at = NOW()
            WHERE token = :token
        ");

        $stmt->execute(['token' => $token]);
    }

    /**
     * Expire all outdated verification codes.
     */
    public static function expireOldCodes(): void
    {
        $pdo = self::db();

        $pdo->exec("
            UPDATE verification_codes
            SET is_expired = 1, updated_at = NOW()
            WHERE expires_at < NOW() AND is_expired = 0
        ");
    }


    // =========================================================================
    // 🗑️ DELETION
    // =========================================================================


    /**
     * Delete a verification entry by code.
     */
    public static function deleteByCode(string $code): void
    {
        $pdo = self::db();

        $stmt = $pdo->prepare("DELETE FROM verification_codes WHERE code = :code");
        $stmt->execute(['code' => $code]);
    }

    /**
     * Delete a verification entry by token.
     */
    public static function deleteByToken(string $token): void
    {
        $pdo = self::db();

        $stmt = $pdo->prepare("DELETE FROM verification_codes WHERE token = :token");
        $stmt->execute(['token' => $token]);
    }

    /**
     * Generate a full URL for a token-based verification.
     *
     * @param string $token The token.
     * @param string $routePrefix URL prefix, e.g. "verify".
     * @return string Full URL to verification route.
     */
    public static function tokenUrl(string $token, string $routePrefix = 'verify'): string
    {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $baseUrl = $protocol . '://' . $host;

        return rtrim($baseUrl, '/') . '/' . trim($routePrefix, '/') . '/' . $token;
    }


    // =========================================================================
    // 📦 FETCH UTILITIES
    // =========================================================================


    /**
     * Get the latest valid verification for a user.
     *
     * @param int $userId
     * @param string $type
     * @return array|null
     */
    public static function getValidVerification($userId, $type)
    {
        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT * FROM verification_codes
            WHERE user_id = :user_id
              AND type = :type
              AND is_used = 0
              AND is_expired = 0
              AND expires_at > NOW()
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([
            'user_id' => $userId,
            'type' => $type,
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get the most recent expired or used verification for reuse.
     *
     * @param int $userId
     * @param string $type
     * @return array|null
     */
    public static function getLatestExpiredOrUsed($userId, $type)
    {
        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT * FROM verification_codes
            WHERE user_id = :user_id
              AND type = :type
              AND (is_used = 1 OR is_expired = 1 OR expires_at <= NOW())
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([
            'user_id' => $userId,
            'type' => $type,
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
