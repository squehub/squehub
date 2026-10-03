<?php

declare(strict_types=1);

use App\Auth\Contracts\Authenticatable;
use App\Auth\Contracts\IdentityProvider;
use App\Auth\Guards\SessionGuard;
use App\Auth\PasswordHasher;
use App\Config\Repository;
use App\Session\SessionManager;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** Probe native session persistence in a separate PHP process and save path. */
$directory = $argv[1];
session_save_path($directory);
$config = new Repository(['session' => ['driver' => 'native', 'name' => 'auth_probe'],
    'auth' => ['passwords' => ['algorithm' => 'bcrypt', 'options' => ['cost' => 4]]]]);
$identity = new class implements Authenticatable {
    public function authIdentifier(): int|string { return 17; }
    public function authPasswordHash(): string { return ''; }
};
$provider = new class($identity) implements IdentityProvider {
    public function __construct(private Authenticatable $identity) {}
    public function retrieveById(int|string $identifier): ?Authenticatable
    {
        return $identifier === 17 ? $this->identity : null;
    }
    public function retrieveByCredentials(array $credentials): ?Authenticatable { return null; }
    public function updatePassword(Authenticatable $identity, string $passwordHash): void {}
    public function supports(Authenticatable $identity): bool { return $identity === $this->identity; }
};
$first = (new SessionManager($config))->store();
$before = $first->id();
$first->put('cart', 'kept');
$guard = new SessionGuard('web', $provider, $first, new PasswordHasher($config), ['email'], true);
$guard->login($identity);
$afterLogin = $first->id();
$first->close();

session_id($afterLogin);
$second = (new SessionManager($config))->store();
$next = new SessionGuard('web', $provider, $second, new PasswordHasher($config), ['email'], true);
$restoredId = $next->id();
$cart = $second->get('cart');
$next->logout();
$afterLogout = $second->id();
$guest = $next->guest();
$all = $second->all();
$second->close();

echo json_encode(compact('before', 'afterLogin', 'restoredId', 'cart', 'afterLogout', 'guest', 'all'), JSON_THROW_ON_ERROR);
