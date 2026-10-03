<?php

declare(strict_types=1);

namespace App\Frontend\Build;

/** The optional Vite adapter delegates compilation and HMR to local Vite. */
final class ViteBuildAdapter implements FrontendBuildAdapter
{
    public function name(): string
    {
        return 'vite';
    }

    public function buildCommand(string $node, string $script): array
    {
        return [$node, $script, 'build'];
    }

    public function developmentCommand(string $node, string $script, int $port): array
    {
        return [$node, $script, '--host', '127.0.0.1', '--port', (string) $port, '--strictPort'];
    }
}
