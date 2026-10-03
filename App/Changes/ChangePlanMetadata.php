<?php

declare(strict_types=1);

namespace App\Changes;

use InvalidArgumentException;

/**
 * Bounded review context for a subsystem-owned change. These codes describe
 * what needs review or verification; they do not claim that an apply occurred.
 * Source bytes, credentials, local paths, and arbitrary application text stay
 * with the subsystem that owns execution.
 */
final readonly class ChangePlanMetadata
{
    private const SOURCES = [
        'application', 'generator', 'feature', 'package', 'kit', 'bundle',
        'upgrade', 'configuration', 'migration', 'recovery', 'agent',
    ];

    private const SECURITY = [
        'untrusted_source', 'external_hooks', 'protected_path',
        'sensitive_persistence', 'secrets_excluded',
    ];

    private const COMPATIBILITY = [
        'framework_version', 'php_runtime', 'extensions', 'composer_dependencies',
        'database_driver', 'filesystem_case',
    ];

    private const VERIFICATION = [
        'file_checksum', 'path_presence', 'ownership', 'activation_state',
        'bundle_checksum', 'migration_pending', 'cache_state', 'manual_review',
    ];

    /**
     * @param list<string> $security
     * @param list<string> $compatibility
     * @param list<string> $verification
     */
    public function __construct(
        public string $source,
        public ?string $sourceFingerprint = null,
        public array $security = [],
        public array $compatibility = [],
        public array $verification = [],
    ) {
        if (!in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException('Change plan source is invalid.');
        }
        if ($sourceFingerprint !== null && preg_match('/\A[a-f0-9]{64}\z/D', $sourceFingerprint) !== 1) {
            throw new InvalidArgumentException('Change plan source fingerprint is invalid.');
        }
        self::validateCodes($security, self::SECURITY);
        self::validateCodes($compatibility, self::COMPATIBILITY);
        self::validateCodes($verification, self::VERIFICATION);
    }

    /** @return array{source:string,source_sha256:?string,security:list<string>,compatibility:list<string>,verification:list<string>} */
    public function toArray(): array
    {
        $security = $this->security;
        $compatibility = $this->compatibility;
        $verification = $this->verification;
        sort($security, SORT_STRING);
        sort($compatibility, SORT_STRING);
        sort($verification, SORT_STRING);
        return [
            'source' => $this->source,
            'source_sha256' => $this->sourceFingerprint,
            'security' => $security,
            'compatibility' => $compatibility,
            'verification' => $verification,
        ];
    }

    /** @param list<string> $codes @param list<string> $allowed */
    private static function validateCodes(array $codes, array $allowed): void
    {
        if (count($codes) > count($allowed) || array_values($codes) !== $codes) {
            throw new InvalidArgumentException('Change plan review codes are invalid.');
        }
        foreach ($codes as $code) {
            if (!is_string($code) || !in_array($code, $allowed, true)) {
                throw new InvalidArgumentException('Change plan review code is invalid.');
            }
        }
        if (count(array_unique($codes)) !== count($codes)) {
            throw new InvalidArgumentException('Change plan review codes are duplicated.');
        }
    }
}
