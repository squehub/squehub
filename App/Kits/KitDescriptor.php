<?php

declare(strict_types=1);

namespace App\Kits;

/** Static, application-owned Kit state and manifest; no entry PHP is loaded. */
final readonly class KitDescriptor
{
    /** @param list<string> $errors @param array<string,mixed>|null $record */
    public function __construct(
        private string $name,
        private string $path,
        private ?KitManifest $manifest,
        private string $status,
        private array $errors = [],
        private ?array $record = null,
    ) {
    }

    public function name(): string { return $this->name; }
    public function path(): string { return $this->path; }
    public function version(): ?string { return $this->manifest?->version; }
    public function manifest(): ?KitManifest { return $this->manifest; }
    public function status(): string { return $this->status; }
    public function sourceKind(): string { return (string) ($this->record['source_kind'] ?? 'manual'); }
    /** @return list<string> */
    public function requires(): array { return $this->manifest?->requires ?? []; }
    /** @return list<string> */
    public function hooks(): array { return $this->manifest?->hooks ?? []; }
    /** @return list<string> */
    public function errors(): array { return $this->errors; }
    /** @return array<string,mixed>|null */
    public function record(): ?array { return $this->record; }

    /** @param list<string> $errors */
    public function withStatus(string $status, array $errors): self
    {
        return new self($this->name, $this->path, $this->manifest, $status, $errors, $this->record);
    }
}
