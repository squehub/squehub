<?php

declare(strict_types=1);

namespace App\Api\Contract;

use RuntimeException;

/** @internal Safe structural failure for a case that never reached the Kernel. */
final class RequestContractMismatch extends RuntimeException
{
    /** @param array{path:string,expected:string,actual:string} $failure */
    public function __construct(private array $failure)
    {
        parent::__construct('Verification request does not match its declared schema.');
    }

    public function path(): string { return $this->failure['path']; }
    public function expected(): string { return $this->failure['expected']; }
    public function actual(): string { return $this->failure['actual']; }
}
