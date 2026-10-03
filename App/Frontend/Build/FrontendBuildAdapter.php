<?php

declare(strict_types=1);

namespace App\Frontend\Build;

/**
 * Describes the few process and URL operations a selected frontend builder
 * supplies. Constructing an adapter never starts Node or contacts a server.
 */
interface FrontendBuildAdapter
{
    public function name(): string;

    /** @return non-empty-list<string> Direct process arguments; no shell text. */
    public function buildCommand(string $node, string $script): array;

    /** @return non-empty-list<string> Direct process arguments; no shell text. */
    public function developmentCommand(string $node, string $script, int $port): array;
}
