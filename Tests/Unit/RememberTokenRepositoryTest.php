<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Auth\Contracts\Authenticatable;
use App\Auth\Contracts\IdentityProvider;
use App\Auth\Remember\RememberManager;
use App\Auth\Remember\RememberTokenRecord;
use App\Auth\Remember\Repositories\ArrayRememberTokenRepository;
use App\Config\Repository;
use App\Database\ModelClock;
use App\Http\Request;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class RememberTokenRepositoryTest extends TestCase
{
    public function testArrayRotationAndExpiryAreOneWinnerAndGuardScoped(): void
    {
        $clock = new RememberTestClock();
        $repository = new ArrayRememberTokenRepository();
        $settings = RememberManager::settings(['enabled' => true, 'driver' => 'array', 'ttl' => 1],
            new Repository(['session' => ['path' => '/', 'secure' => false]]), ['web', 'admin']);
        $identity = new RememberTestIdentity(7, hash('sha256', 'stored-password'));
        $provider = new RememberTestProvider($identity);
        $web = new RememberManager('web', $repository, $clock, $settings);
        $web->beginRequest(new Request('GET', '/'));
        $first = $web->issue($identity);
        self::assertNotNull($repository->find($first->selector));

        $admin = new RememberManager('admin', $repository, $clock, $settings);
        $admin->beginRequest(new Request('GET', '/', [], [],
            ['squehub_remember_admin' => $first->cookie->value()]));
        self::assertNull($admin->recall($provider));
        self::assertNotNull($repository->find($first->selector));

        $web->beginRequest(new Request('GET', '/', [], [],
            ['squehub_remember_web' => $first->cookie->value()]));
        $staleReader = $repository->find($first->selector);
        self::assertNotNull($staleReader);
        $recalled = $web->recall($provider);
        self::assertNotNull($recalled);
        self::assertSame($identity, $recalled->identity);
        self::assertNull($repository->find($first->selector));
        self::assertNotNull($repository->find($recalled->credential->selector));
        $losingReplacement = new RememberTokenRecord(str_repeat('C', 22), hash('sha256', 'other'),
            $staleReader->guard, $staleReader->identityKey, $staleReader->credentialFingerprint,
            $clock->now(), $clock->now()->modify('+1 second'));
        self::assertFalse($repository->rotate($staleReader->selector,
            $staleReader->validatorHash, $losingReplacement));
        self::assertNull($repository->find($losingReplacement->selector));
        self::assertSame('[]', json_encode($recalled, JSON_THROW_ON_ERROR));
        self::assertSame('[]', json_encode($recalled->credential, JSON_THROW_ON_ERROR));

        $web->beginRequest(new Request('GET', '/', [], [],
            ['squehub_remember_web' => $recalled->credential->cookie->value()]));
        $clock->advance('+2 seconds');
        self::assertNull($web->recall($provider));
        self::assertNull($repository->find($recalled->credential->selector));
        self::assertSame(0, $web->pendingCookie()?->maxAge());
    }

    public function testArrayRepositoriesAndIdentityKeysDoNotLeakAcrossScopes(): void
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
        $record = new RememberTokenRecord(str_repeat('A', 22), hash('sha256', 'validator'),
            'web', 's:Case', hash('sha256', 'password'), $now, $now->modify('+1 day'));
        $other = new RememberTokenRecord(str_repeat('B', 22), hash('sha256', 'other'),
            'web', 's:case', hash('sha256', 'password'), $now, $now->modify('+1 day'));
        $first = new ArrayRememberTokenRepository();
        $second = new ArrayRememberTokenRepository();
        $first->insert($record);
        $first->insert($other);
        self::assertNull($second->find($record->selector));
        self::assertNotSame(RememberTokenRecord::scopeHash('web', 's:Case'),
            RememberTokenRecord::scopeHash('web', 's:case'));
        $first->revokeIdentity('web', 's:Case');
        self::assertNull($first->find($record->selector));
        self::assertNotNull($first->find($other->selector));
        self::assertSame('[]', json_encode($other, JSON_THROW_ON_ERROR));
    }
}

/** Fixed UTC clock keeps expiry assertions deterministic. */
final class RememberTestClock implements ModelClock
{
    private DateTimeImmutable $current;
    public function __construct() { $this->current = new DateTimeImmutable('2026-01-01T00:00:00Z'); }
    public function now(): DateTimeImmutable { return $this->current; }
    public function advance(string $modifier): void { $this->current = $this->current->modify($modifier); }
}

/** Minimal persisted-identity stand-in for the repository boundary. */
final class RememberTestIdentity implements Authenticatable
{
    public function __construct(private int $id, private string $hash) {}
    public function authIdentifier(): int|string { return $this->id; }
    public function authPasswordHash(): string { return $this->hash; }
}

/** Resolves the one test identity without relying on a database. */
final class RememberTestProvider implements IdentityProvider
{
    public function __construct(private RememberTestIdentity $identity) {}
    public function retrieveById(int|string $identifier): ?Authenticatable
    {
        return (string) $identifier === (string) $this->identity->authIdentifier() ? $this->identity : null;
    }
    public function retrieveByCredentials(array $credentials): ?Authenticatable { return $this->identity; }
    public function updatePassword(Authenticatable $identity, string $passwordHash): void {}
    public function supports(Authenticatable $identity): bool { return $identity === $this->identity; }
}
