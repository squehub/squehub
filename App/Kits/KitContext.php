<?php

declare(strict_types=1);

namespace App\Kits;

use InvalidArgumentException;

/** Only public lifecycle metadata reaches trusted Kit hooks. */
readonly class KitContext
{
    public function __construct(
        public string $operation,
        public string $name,
        public string $version,
    ) {
        if (!in_array($operation, ['install', 'enable', 'disable', 'upgrade', 'remove'], true)
            || !KitName::valid($name)) {
            throw new InvalidArgumentException('Kit lifecycle context is invalid.');
        }
    }
}
