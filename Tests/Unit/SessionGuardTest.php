<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Auth\AuthException;
use App\Auth\Contracts\Authenticatable;
use App\Auth\Contracts\IdentityProvider;
use App\Auth\Guards\SessionGuard;
use App\Auth\PasswordHasher;
use App\Config\Repository;
use App\Session\Drivers\ArraySessionDriver;
use App\Session\SessionDriver;
use App\Session\SessionException;
use App\Session\SessionStore;
use PHPUnit\Framework\TestCase;

/** Failure injection proves the authentication boundary never claims a failed state change. */
final class SessionGuardTest extends TestCase
{
    private function hasher(int $cost = 4): ProbePasswordHasher
    {
        return new ProbePasswordHasher(new Repository(['auth' => ['passwords' => [
            'algorithm' => 'bcrypt', 'options' => ['cost' => $cost],
        ]]]));
    }

    public function testUnknownIdentityUsesDummyVerifierAndDoesNotRotate(): void
    {
        $hasher = $this->hasher();
        $session = new SessionStore(new ArraySessionDriver());
        $before = $session->id();
        $guard = new SessionGuard('web', new ProbeIdentityProvider(null), $session, $hasher, ['email'], true);
        self::assertFalse($guard->attempt(['email' => 'missing@example.test', 'password' => 'secret']));
        self::assertSame(1, $hasher->dummyCalls);
        self::assertSame($before, $session->id());
        self::assertNull($session->authIdentifier('web'));
    }

    public function testRehashFailureDoesNotEstablishState(): void
    {
        $identity = new ProbeIdentity(42, password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]));
        $provider = new ProbeIdentityProvider($identity);
        $provider->failUpdate = true;
        $session = new SessionStore(new ArraySessionDriver());
        $before = $session->id();
        $guard = new SessionGuard('web', $provider, $session, $this->hasher(5), ['email'], true);
        $this->expectException(AuthException::class);
        try {
            $guard->attempt(['email' => 'x@example.test', 'password' => 'secret']);
        } finally {
            self::assertSame($before, $session->id());
            self::assertNull($session->authIdentifier('web'));
        }
    }

    public function testRegenerationAndInvalidationFailuresPreserveGuardState(): void
    {
        $identity = new ProbeIdentity(42, $this->hasher()->hash('secret'));
        $driver = new FailingAuthDriver();
        $session = new SessionStore($driver);
        $guard = new SessionGuard('web', new ProbeIdentityProvider($identity), $session,
            $this->hasher(), ['email'], true);
        $driver->failRegenerate = true;
        try {
            $guard->login($identity);
            self::fail('Regeneration failure was accepted.');
        } catch (SessionException) {
            self::assertNull($session->authIdentifier('web'));
            self::assertFalse($guard->check());
        }
        $driver->failRegenerate = false;
        $guard->login($identity);
        self::assertTrue($guard->check());
        $driver->failInvalidate = true;
        try {
            $guard->logout();
            self::fail('Invalidation failure was accepted.');
        } catch (SessionException) {
            self::assertTrue($guard->check());
            self::assertSame(42, $session->authIdentifier('web'));
        }
    }

    public function testUnsupportedIdentityCannotLogIn(): void
    {
        $session = new SessionStore(new ArraySessionDriver());
        $guard = new SessionGuard('web', new ProbeIdentityProvider(null), $session,
            $this->hasher(), ['email'], true);
        $this->expectException(AuthException::class);
        $guard->login(new ProbeIdentity(1, 'hash'));
    }
}

/** Test seam exposes dummy invocation without timing assertions. */
final class ProbePasswordHasher extends PasswordHasher
{
    public int $dummyCalls = 0;

    public function verifyDummy(string $plainPassword): void
    {
        ++$this->dummyCalls;
        parent::verifyDummy($plainPassword);
    }
}

/** A non-Model provider demonstrates that guard contracts are persistence-neutral. */
final class ProbeIdentityProvider implements IdentityProvider
{
    public bool $failUpdate = false;

    public function __construct(private ?ProbeIdentity $identity)
    {
    }

    public function retrieveById(int|string $identifier): ?Authenticatable { return $this->identity; }
    public function retrieveByCredentials(array $credentials): ?Authenticatable { return $this->identity; }
    public function supports(Authenticatable $identity): bool { return $identity === $this->identity; }

    public function updatePassword(Authenticatable $identity, string $passwordHash): void
    {
        if ($this->failUpdate) throw new AuthException('Password update failed.');
        $this->identity = new ProbeIdentity($identity->authIdentifier(), $passwordHash);
    }
}

/** Minimal application identity for guard isolation tests. */
final class ProbeIdentity implements Authenticatable
{
    public function __construct(private int $identifier, private string $hash)
    {
    }
    public function authIdentifier(): int|string { return $this->identifier; }
    public function authPasswordHash(): string { return $this->hash; }
}

/** Faulting driver keeps previously persisted state unchanged on errors. */
final class FailingAuthDriver implements SessionDriver
{
    public bool $failRegenerate = false;
    public bool $failInvalidate = false;
    private ArraySessionDriver $inner;

    public function __construct() { $this->inner = new ArraySessionDriver(); }
    public function start(): void { $this->inner->start(); }
    public function data(): array { return $this->inner->data(); }
    public function replace(array $data): void { $this->inner->replace($data); }
    public function id(): string { return $this->inner->id(); }
    public function regenerate(): void
    {
        if ($this->failRegenerate) throw new SessionException('Controlled regeneration failure.');
        $this->inner->regenerate();
    }
    public function invalidate(): void
    {
        if ($this->failInvalidate) throw new SessionException('Controlled invalidation failure.');
        $this->inner->invalidate();
    }
    public function close(): void { $this->inner->close(); }
}
