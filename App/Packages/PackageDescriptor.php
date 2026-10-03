<?php

declare(strict_types=1);

namespace App\Packages;

/**
 * A static view of one Package's identity, optional owner, dependencies, and activation state.
 *
 * Discovery never loads the entry class. The descriptor contains no source
 * URL, credentials, Package PHP, or application configuration values.
 */
final readonly class PackageDescriptor
{
    /**
     * @param list<string> $dependencies
     * @param list<string> $errors
     * @param array<string, mixed>|null $record
     */
    public function __construct(
        private string $name,
        private string $path,
        private ?string $entryClass,
        private ?string $version,
        private ?string $owner,
        private array $dependencies,
        private string $status,
        private array $errors = [],
        private ?array $record = null,
    ) {
    }

    public function name(): string { return $this->name; }
    public function path(): string { return $this->path; }
    public function entryClass(): ?string { return $this->entryClass; }
    public function version(): ?string { return $this->version; }
    public function owner(): ?string { return $this->owner; }
    /** @return list<string> */
    public function dependencies(): array { return $this->dependencies; }
    public function status(): string { return $this->status; }
    /** @return list<string> */
    public function errors(): array { return $this->errors; }
    /** @return array<string, mixed>|null */
    public function record(): ?array { return $this->record; }

    /** @param list<string> $errors */
    public function withStatus(string $status, array $errors = []): self
    {
        return new self($this->name, $this->path, $this->entryClass,
            $this->version, $this->owner, $this->dependencies, $status, $errors, $this->record);
    }
}
