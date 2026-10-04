<?php

declare(strict_types=1);

namespace SqueHub\Tests\Fixtures;

use App\Auth\Contracts\Authenticatable;
use App\Database\Model;

/** Persisted identity shared by deterministic HTTP worker processes. */
final class IdempotencyWorkerUser extends Model implements Authenticatable
{
    protected string $table = 'idempotency_worker_users';
    protected array $fillable = ['email', 'password'];
    protected array $hidden = ['password'];

    public function authIdentifier(): int|string { return $this->getAttribute('id'); }
    public function authPasswordHash(): string { return $this->getAttribute('password'); }
}
