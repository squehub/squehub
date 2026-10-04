<?php

declare(strict_types=1);

namespace App\Recovery;

/**
 * Read-only recovery evidence. Each boundary has a separate status because
 * source, database records, persistent files, secrets, and Queue work cannot
 * be recovered from one artifact or assigned one consistency guarantee.
 */
final readonly class RecoveryPlan
{
    /** @param array<string, array<string, int|string|bool|null>> $boundaries */
    public function __construct(private array $boundaries)
    {
    }

    /** @return array{version:int,boundaries:array<string, array<string, int|string|bool|null>>} */
    public function toArray(): array
    {
        return ['version' => 1, 'boundaries' => $this->boundaries];
    }

    /** Human output uses the same fixed, credential-free categories as JSON. */
    public function render(): string
    {
        $lines = ['SqueHub Recovery Plan'];
        foreach ($this->boundaries as $name => $details) {
            $lines[] = str_pad($name, 16) . ' ' . ($details['status'] ?? 'unknown');
        }
        $lines[] = 'No source import, database restore, or upload recovery was performed.';
        return implode(PHP_EOL, $lines);
    }
}
