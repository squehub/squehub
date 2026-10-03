<?php

declare(strict_types=1);

namespace App\View;

use App\View\Assets\AssetException;

/**
 * Immutable HTML and finalized asset stacks from one complete View render.
 * This carries render data only; HTTP status and headers belong to Response.
 */
final class ViewRenderResult
{
    /** @param array<string, string> $stacks */
    public function __construct(
        private readonly string $html,
        private readonly array $stacks,
    ) {
        foreach ($stacks as $name => $content) {
            self::assertStackName($name);
            if (!is_string($content)) {
                throw new AssetException('A finalized asset stack must contain HTML text.');
            }
        }
    }

    public function html(): string
    {
        return $this->html;
    }

    public function hasStack(string $name): bool
    {
        self::assertStackName($name);
        return array_key_exists($name, $this->stacks);
    }

    /** An undeclared but valid stack has no output. */
    public function stack(string $name): string
    {
        self::assertStackName($name);
        return $this->stacks[$name] ?? '';
    }

    /** @return array<string, string> */
    public function stacks(): array
    {
        return $this->stacks;
    }

    private static function assertStackName(string $name): void
    {
        if (preg_match('/\A[A-Za-z][A-Za-z0-9._-]*\z/D', $name) !== 1) {
            throw new AssetException('Asset stack name is invalid.');
        }
    }
}
