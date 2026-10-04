<?php

declare(strict_types=1);

namespace App\Redis;

/** Internal binary-safe command transport; adapters do not own key policy. */
interface RedisClient
{
    /** @param non-empty-list<string> $arguments */
    public function execute(array $arguments): mixed;

    public function close(): void;
}
