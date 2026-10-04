<?php

declare(strict_types=1);

namespace App\Storage\Providers;

use RuntimeException;

/** Contains only a fixed classification; provider exception text is never retained. */
final class S3ClientFailure extends RuntimeException
{
    public const NOT_FOUND = 'not_found';
    public const PERMISSION_DENIED = 'permission_denied';
    public const CONFIGURATION_FAILURE = 'configuration_failure';
    public const PROVIDER_FAILURE = 'provider_failure';

    public function __construct(private string $reason = self::PROVIDER_FAILURE)
    {
        if (!in_array($reason, [self::NOT_FOUND, self::PERMISSION_DENIED,
            self::CONFIGURATION_FAILURE, self::PROVIDER_FAILURE], true)) {
            $this->reason = self::PROVIDER_FAILURE;
        }
        parent::__construct('S3 ' . $this->reason . '.');
    }

    public function reason(): string { return $this->reason; }
}
