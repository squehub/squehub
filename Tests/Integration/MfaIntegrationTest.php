<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\AccountSecurity\AccountSecurityManager;
use App\Auth\AuthManager;
use App\Auth\Contracts\Authenticatable;
use App\Auth\Guards\SessionGuard;
use App\Auth\PasswordHasher;
use App\Authorization\AuthorizationManager;
use App\Authorization\Rbac\RbacManager;
use App\Cryptography\CryptManager;
use App\Database\DatabaseManager;
use App\Database\Model;
use App\Database\ModelClock;
use App\Database\Schema\Table;
use App\Diagnostics\Diagnostics;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Logging\Drivers\ArrayLogger;
use App\Mfa\Mfa;
use App\Mfa\MfaEnrollment;
use App\Mfa\MfaException;
use App\Mfa\MfaManager;
use App\Mfa\Repositories\DatabaseMfaRepository;
use App\Mfa\Repositories\MfaIdentity;
use App\Mfa\Totp;
use App\Plugins\RequireAbility;
use App\Routing\RouteRegistry;
use App\Security\Csrf\CsrfTokenManager;
use App\Session\SessionManager;
use App\Session\SessionStore;
use App\Testing\TestApplication;
use App\Testing\TestCase;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/** Optional MFA exercised through real Auth, Session, Crypt, RateLimit, and SQLite. */
final class MfaIntegrationTest extends TestCase
{
    private AuthManager $auth;
    private MfaManager $mfa;
    private DatabaseManager $database;
    private SessionStore $session;
    private MfaClock $clock;

    protected function testingConfig(): array
    {
        return self::configuration('a');
    }

    private static function configuration(string $key): array
    {
        return [
            'rbac' => ['enabled' => true, 'driver' => 'array'],
            'accountSecurity' => ['tokens' => ['driver' => 'array']],
            'auth' => [
                'default' => 'web',
                'guards' => [
                    'web' => ['driver' => 'session', 'identity' => 'users'],
                    'admin' => ['driver' => 'session', 'identity' => 'users'],
                ],
                'identities' => ['users' => [
                    'driver' => 'model', 'model' => MfaTestUser::class,
                    'identifier' => 'id', 'password' => 'password', 'credentials' => ['email'],
                ]],
                'passwords' => ['algorithm' => 'bcrypt', 'options' => ['cost' => 4],
                    'rehash_on_login' => false, 'max_bytes' => 4096],
                'remember' => ['enabled' => true, 'driver' => 'array', 'ttl' => 3600],
            ],
            'security' => ['mfa' => [
                'enabled' => true, 'driver' => 'database', 'issuer' => 'SqueHub Test',
                'enrollment_ttl' => 600, 'challenge_ttl' => 300,
                'recovery_count' => 4, 'max_attempts' => 5,
            ]],
            'crypt' => ['driver' => 'auto', 'current' => 'primary',
                'keys' => ['primary' => 'base64:' . base64_encode(str_repeat($key, 32))]],
            'rateLimit' => ['driver' => 'array'],
        ];
    }

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        parent::setUp();
        $app = $this->app();
        $this->clock = new MfaClock();
        $app->container()->instance(ModelClock::class, $this->clock);
        $this->auth = $app->container()->make(AuthManager::class);
        $this->mfa = $app->container()->make(MfaManager::class);
        $this->database = $app->container()->make(DatabaseManager::class);
        $this->session = $app->container()->make(SessionManager::class)->store();
        $this->database->schema()->create('mfa_test_users', static function (Table $table): void {
            $table->id();
            $table->string('email');
            $table->string('password');
        });
        require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_30_create_mfa_tables.php';
        (new \CreateMfaTables())->up($this->database->connection()->pdo(), $this->database->schema());
    }

    private function user(string $email = 'ada@example.test'): MfaTestUser
    {
        return MfaTestUser::create(['email' => $email,
            'password' => $this->app()->container()->make(PasswordHasher::class)->hash('correct')]);
    }

    /** @return array{0:MfaEnrollment,1:list<string>} */
    private function enroll(MfaTestUser $user): array
    {
        $this->auth->login($user);
        $context = $this->mfa->forGuard('web');
        $enrollment = $context->beginEnrollment($user->email);
        $codes = $context->confirmEnrollment($this->code($enrollment));
        self::assertNotNull($codes);
        self::assertTrue($context->enabled());
        return [$enrollment, $codes];
    }

    private function code(MfaEnrollment $enrollment): string
    {
        return Totp::at($enrollment->secret(), intdiv($this->clock->now()->getTimestamp(), 30));
    }

    private function attempt(string $email = 'ada@example.test', bool $remember = false): bool
    {
        return $this->auth->attempt(['email' => $email, 'password' => 'correct'], $remember);
    }

    public function testEnrollmentStoresEncryptedSecretAndOnlyHashedRecoveryCodes(): void
    {
        $user = $this->user();
        $this->auth->login($user);
        $context = $this->mfa->forGuard('web');
        $enrollment = $context->beginEnrollment('Ada+test@example.test');
        self::assertFalse($context->enabled());
        self::assertSame($this->clock->now()->getTimestamp() + 600, $enrollment->expiresAt);
        self::assertStringContainsString('issuer=SqueHub%20Test', $enrollment->provisioningUri());
        self::assertStringContainsString('secret=' . $enrollment->secret(), $enrollment->provisioningUri());
        $before = $this->database->table('mfa_credentials')->first();
        self::assertNotNull($before);
        self::assertSame(0, (int) $before['enabled']);
        self::assertNull($before['encrypted_secret']);
        self::assertStringNotContainsString($enrollment->secret(), json_encode($before, JSON_THROW_ON_ERROR));
        self::assertNull($context->confirmEnrollment('bad'));
        self::assertFalse($context->enabled());

        $codes = $context->confirmEnrollment($this->code($enrollment));
        self::assertCount(4, $codes);
        self::assertCount(4, array_unique($codes));
        self::assertSame(4, $this->database->table('mfa_recovery_codes')->count());
        $after = $this->database->table('mfa_credentials')->first();
        self::assertSame(1, (int) $after['enabled']);
        self::assertNull($after['pending_secret']);
        self::assertSame($before['pending_secret'], $after['encrypted_secret']);
        $stored = json_encode([$after, $this->database->table('mfa_recovery_codes')->all()], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($enrollment->secret(), $stored);
        foreach ($codes as $code) self::assertStringNotContainsString($code, $stored);
        self::assertNull($context->confirmEnrollment($this->code($enrollment)));
    }

    public function testChallengeIsGuestOnlyRotatesSessionAndRejectsTotpReplay(): void
    {
        $user = $this->user();
        [$enrollment] = $this->enroll($user);
        $this->auth->logout();
        $this->session->put('cart', 'private');
        $this->session->setCsrfToken('old-token');
        $oldId = $this->session->id();
        self::assertFalse($this->attempt());
        self::assertNotSame($oldId, $this->session->id());
        self::assertTrue($this->mfa->pending());
        self::assertTrue($this->mfa->forGuard('web')->pending());
        self::assertFalse($this->auth->check());
        $this->auth->resetRequestState();
        self::assertTrue($this->mfa->pending());
        self::assertFalse($this->auth->check());
        self::assertNull($this->auth->id());
        self::assertNull($this->session->authIdentifier('web'));
        self::assertSame([], $this->session->all());
        self::assertNull($this->session->csrfToken());
        self::assertStringNotContainsString($enrollment->secret(),
            json_encode($this->session->pendingMfa(), JSON_THROW_ON_ERROR));
        $pendingId = $this->session->id();
        self::assertFalse($this->mfa->completeChallenge($this->code($enrollment)),
            'The confirmation counter is already consumed.');
        self::assertSame($pendingId, $this->session->id());
        $this->clock->advance('+30 seconds');
        self::assertTrue($this->mfa->completeChallenge($this->code($enrollment)));
        self::assertNotSame($pendingId, $this->session->id());
        self::assertFalse($this->mfa->pending());
        self::assertTrue($this->auth->check());
        $this->auth->resetRequestState();
        self::assertTrue($this->auth->check());
        self::assertSame((string) $user->id, (string) $this->auth->id());

        $this->auth->logout();
        self::assertFalse($this->attempt());
        self::assertFalse($this->mfa->completeChallenge($this->code($enrollment)));
        $this->clock->advance('+30 seconds');
        self::assertTrue($this->mfa->completeChallenge($this->code($enrollment)));
    }

    public function testRecoveryCodesAreOneTimeAndRegenerationRevokesUnusedCodes(): void
    {
        $user = $this->user();
        [, $codes] = $this->enroll($user);
        $replacement = $this->mfa->forGuard('web')->regenerateRecoveryCodes(strtolower($codes[0]));
        self::assertCount(4, $replacement);
        self::assertNull($this->mfa->forGuard('web')->regenerateRecoveryCodes($codes[1]));
        $this->auth->logout();
        self::assertFalse($this->attempt());
        self::assertFalse($this->mfa->completeChallenge($codes[2]));
        self::assertTrue($this->mfa->completeChallenge($replacement[0]));
        self::assertTrue($this->auth->check());

        $this->auth->logout();
        self::assertFalse($this->attempt());
        self::assertFalse($this->mfa->completeChallenge($replacement[0]));
        self::assertTrue($this->mfa->completeChallenge($replacement[1]));
        self::assertTrue($this->mfa->forGuard('web')->disable($replacement[2]));
        self::assertFalse($this->mfa->forGuard('web')->enabled());
        self::assertSame(0, $this->database->table('mfa_credentials')->count());
        self::assertSame(0, $this->database->table('mfa_recovery_codes')->count());
        $this->auth->logout();
        self::assertTrue($this->attempt());
        self::assertFalse($this->mfa->pending());
    }

    public function testEnrollmentAndPendingChallengeExpireOnTheFrameworkClock(): void
    {
        $user = $this->user();
        $this->auth->login($user);
        $enrollment = $this->mfa->forGuard('web')->beginEnrollment($user->email);
        $this->clock->advance('+600 seconds');
        self::assertNull($this->mfa->forGuard('web')->confirmEnrollment($this->code($enrollment)));
        self::assertFalse($this->mfa->forGuard('web')->enabled());
        $fresh = $this->mfa->forGuard('web')->beginEnrollment($user->email);
        self::assertNotNull($this->mfa->forGuard('web')->confirmEnrollment($this->code($fresh)));

        $this->auth->logout();
        self::assertFalse($this->attempt());
        $pendingId = $this->session->id();
        $this->clock->advance('+300 seconds');
        self::assertFalse($this->mfa->pending());
        self::assertNotSame($pendingId, $this->session->id());
        self::assertNull($this->session->pendingMfa());
        self::assertFalse($this->mfa->completeChallenge('123456'));
        self::assertFalse($this->auth->check());
    }

    public function testCredentialChangeAndDeletedIdentityAbandonPendingChallenge(): void
    {
        $user = $this->user();
        [, $codes] = $this->enroll($user);
        $this->auth->logout();
        self::assertFalse($this->attempt());
        $hash = $this->app()->container()->make(PasswordHasher::class)->hash('changed');
        $guard = $this->auth->guard('web');
        self::assertInstanceOf(SessionGuard::class, $guard);
        $guard->identityProvider()->updatePassword($user, $hash);
        self::assertFalse($this->mfa->completeChallenge($codes[0]));
        self::assertFalse($this->mfa->pending());
        self::assertFalse($this->auth->check());

        $fresh = MfaTestUser::find($user->id);
        self::assertNotNull($fresh);
        $this->auth->login($fresh);
        self::assertTrue($this->mfa->pending());
        $this->database->table('mfa_test_users')->filter('id', $user->id)->delete();
        self::assertFalse($this->mfa->completeChallenge($codes[1]));
        self::assertFalse($this->mfa->pending());
        self::assertFalse($this->auth->check());
    }

    public function testChallengeAttemptsAreBoundedAndGuardScoped(): void
    {
        $user = $this->user();
        [, $codes] = $this->enroll($user);
        $admin = $this->auth->guard('admin');
        self::assertInstanceOf(SessionGuard::class, $admin);
        $admin->login($user);
        self::assertTrue($this->auth->guard('admin')->check());
        self::assertFalse($this->mfa->forGuard('admin')->enabled());
        $this->auth->logout();
        self::assertFalse($this->attempt());
        self::assertFalse($this->auth->guard('admin')->check());
        for ($i = 0; $i < 5; ++$i) {
            self::assertFalse($this->mfa->completeChallenge('invalid'));
        }
        self::assertFalse($this->mfa->completeChallenge($codes[0]));
        self::assertTrue($this->mfa->pending());
        self::assertSame(4, $this->database->table('mfa_recovery_codes')->count());
    }

    public function testRememberRecallStillRequiresMfaAndPublishesCookieOnlyAfterProof(): void
    {
        $user = $this->user();
        $rbac = $this->app()->container()->make(RbacManager::class);
        $rbac->createRole('reader');
        $rbac->createPermission('reports.view');
        $rbac->grantPermission('reader', 'reports.view');
        $rbac->assignRole($user, 'reader');
        $this->app()->container()->make(RouteRegistry::class)
            ->get('/protected', static fn (): string => 'authorized')
            ->through(['auth', RequireAbility::named('reports.view')]);
        [, $codes] = $this->enroll($user);
        $this->auth->logout();
        $this->auth->beginRequest(new Request('GET', '/login'));
        self::assertFalse($this->attempt(remember: true));
        self::assertTrue($this->mfa->pending());
        self::assertSame([], $this->auth->decorateResponse(new Response())->cookies());
        self::assertTrue($this->mfa->completeChallenge($codes[0]));
        /** @var list<\App\Http\Cookie> $issued */
        $issued = $this->auth->decorateResponse(new Response())->cookies();
        self::assertCount(1, $issued);
        $cookie = null;
        foreach ($issued as $candidate) { $cookie = $candidate; break; }
        self::assertNotNull($cookie);
        self::assertSame('squehub_remember_web', $cookie->name());
        self::assertGreaterThan(0, $cookie->maxAge());

        $this->session->invalidate();
        $denied = $this->app()->container()->make(Kernel::class)->handle(
            new Request('GET', '/protected', [], [], [$cookie->name() => $cookie->value()],
                ['Accept' => 'application/json']));
        self::assertSame(401, $denied->status());
        self::assertFalse($this->auth->check());
        self::assertTrue($this->mfa->pending());
        self::assertNull($this->session->authIdentifier('web'));
        self::assertFalse($this->app()->container()->make(AuthorizationManager::class)
            ->allows('reports.view'));
        self::assertFalse($this->auth->check());
        self::assertTrue($this->mfa->completeChallenge($codes[1]));
        self::assertTrue($this->auth->check());
        /** @var list<\App\Http\Cookie> $replacement */
        $replacement = $this->auth->decorateResponse(new Response())->cookies();
        self::assertCount(1, $replacement);
        self::assertNotSame($cookie->value(), $replacement[0]->value());
        $allowed = $this->app()->container()->make(Kernel::class)->handle(
            new Request('GET', '/protected', headers: ['Accept' => 'application/json']));
        self::assertSame(200, $allowed->status());
        self::assertSame('authorized', $allowed->content());
    }

    public function testAccountSecurityPasswordChangesPreserveMfaStateAndKeepSecretsPrivate(): void
    {
        $user = $this->user();
        [$enrollment, $codes] = $this->enroll($user);
        $credentials = $this->database->table('mfa_credentials')->first();
        $recovery = $this->database->table('mfa_recovery_codes')->all();
        self::assertNotNull($credentials);
        $security = $this->app()->container()->make(AccountSecurityManager::class);

        self::assertTrue($security->changePassword('correct', 'changed'));
        $reset = $security->issuePasswordReset(['email' => $user->email]);
        self::assertNotNull($reset);
        self::assertTrue($security->resetPassword($reset->token(), 'reset'));
        self::assertSame($credentials, $this->database->table('mfa_credentials')->first());
        self::assertSame($recovery, $this->database->table('mfa_recovery_codes')->all());

        $this->auth->resetRequestState();
        self::assertFalse($this->auth->check());
        self::assertFalse($this->auth->attempt(['email' => $user->email, 'password' => 'reset']));
        self::assertTrue($this->mfa->pending());
        self::assertTrue($this->mfa->completeChallenge($codes[0]));
        self::assertTrue($this->auth->check());

        $diagnostics = json_encode($this->app()->container()->make(Diagnostics::class)->snapshot(),
            JSON_THROW_ON_ERROR);
        $logs = json_encode($this->app()->container()->make(ArrayLogger::class)->records(),
            JSON_THROW_ON_ERROR);
        foreach ([$enrollment->secret(), $enrollment->provisioningUri(), $codes[0]] as $private) {
            self::assertStringNotContainsString($private, $diagnostics);
            self::assertStringNotContainsString($private, $logs);
        }
    }

    public function testMountedTrustedHttpsKeepsMfaChallengeCsrfAndRememberCookieScoped(): void
    {
        $config = self::configuration('b');
        $config['http'] = ['base_path' => '/app'];
        $config['trustedProxies'] = ['proxies' => ['10.0.0.8'], 'profile' => 'x-forwarded'];
        $config['security']['browser'] = [
            'enabled' => true,
            'csp' => ['directives' => ['default-src' => ["'self'"]]],
            'hsts' => ['enabled' => true, 'max_age' => 60],
        ];
        $other = TestApplication::temporary($config);
        try {
            $app = $other->application();
            $container = $app->container();
            $clock = new MfaClock();
            $container->instance(ModelClock::class, $clock);
            $database = $container->make(DatabaseManager::class);
            $database->schema()->create('mfa_test_users', static function (Table $table): void {
                $table->id();
                $table->string('email');
                $table->string('password');
            });
            (new \CreateMfaTables())->up($database->connection()->pdo(), $database->schema());
            $user = MfaTestUser::create(['email' => 'mounted@example.test',
                'password' => $container->make(PasswordHasher::class)->hash('correct')]);
            $auth = $container->make(AuthManager::class);
            $mfa = $container->make(MfaManager::class);
            $auth->login($user);
            $enrollment = $mfa->forGuard('web')->beginEnrollment($user->email);
            $confirmation = Totp::at($enrollment->secret(), intdiv($clock->now()->getTimestamp(), 30));
            $codes = $mfa->forGuard('web')->confirmEnrollment($confirmation);
            self::assertNotNull($codes);
            $auth->logout();

            $routes = $container->make(RouteRegistry::class);
            $routes->post('/login', static function () use ($auth, $mfa): string {
                $authenticated = $auth->attempt(['email' => 'mounted@example.test',
                    'password' => 'correct'], remember: true);
                return !$authenticated && $mfa->pending() ? 'pending' : 'unexpected';
            });
            $csrf = $container->make(CsrfTokenManager::class);
            $routes->get('/challenge', static fn (): string => $csrf->token());
            $routes->post('/challenge', static fn (Request $request): string =>
                $mfa->completeChallenge((string) $request->input('proof')) ? 'complete' : 'denied');
            $routes->get('/protected', static fn (): string => 'protected')->through('auth');
            $kernel = $container->make(Kernel::class);
            $request = static fn (string $method, string $path, array $form = []): Request =>
                new Request($method, '/app' . $path, form: $form,
                    headers: ['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'],
                    server: ['REMOTE_ADDR' => '10.0.0.8', 'HTTP_HOST' => 'app.example.test',
                        'HTTPS' => 'off']);

            $before = $csrf->token();
            $login = $kernel->handle($request('POST', '/login', ['_csrf' => $before]));
            self::assertSame('pending', $login->content());
            self::assertSame([], $login->cookies());
            self::assertSame("default-src 'self'", $login->header('Content-Security-Policy'));
            self::assertSame('max-age=60', $login->header('Strict-Transport-Security'));
            self::assertTrue($mfa->pending());
            self::assertNull($container->make(SessionManager::class)->store()->authIdentifier('web'));

            self::assertSame(401, $kernel->handle($request('GET', '/protected'))->status());
            self::assertSame(403, $kernel->handle($request('POST', '/challenge',
                ['_csrf' => $before, 'proof' => $codes[0]]))->status());
            $challenge = $kernel->handle($request('GET', '/challenge'));
            self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', $challenge->content());
            $finish = $kernel->handle($request('POST', '/challenge',
                ['_csrf' => $challenge->content(), 'proof' => $codes[0]]));
            self::assertSame('complete', $finish->content());
            self::assertSame('max-age=60', $finish->header('Strict-Transport-Security'));
            self::assertSame("default-src 'self'", $finish->header('Content-Security-Policy'));
            self::assertCount(1, $finish->cookies());
            $remember = $finish->cookies()[0];
            self::assertSame('/app', $remember->path());
            self::assertTrue($remember->secure());
            self::assertTrue($remember->httpOnly());
            self::assertSame(200, $kernel->handle($request('GET', '/protected'))->status());
        } finally {
            $other->cleanup();
        }
    }

    public function testSeparateApplicationDoesNotInheritPendingStateOrEnrollment(): void
    {
        $user = $this->user();
        $this->enroll($user);
        $this->auth->logout();
        self::assertFalse($this->attempt());
        $firstManager = $this->mfa;
        $firstCrypt = $this->app()->container()->make(CryptManager::class);
        $other = TestApplication::temporary(self::configuration('b'));
        try {
            $otherApp = $other->application();
            $otherManager = $otherApp->container()->make(MfaManager::class);
            self::assertNotSame($firstManager, $otherManager);
            self::assertSame($otherManager, Mfa::manager());
            self::assertFalse($otherManager->pending());
            self::assertNotSame($firstCrypt, $otherApp->container()->make(CryptManager::class));
            $otherDatabase = $otherApp->container()->make(DatabaseManager::class);
            $otherDatabase->schema()->create('mfa_test_users', static function (Table $table): void {
                $table->id();
                $table->string('email');
                $table->string('password');
            });
            (new \CreateMfaTables())->up($otherDatabase->connection()->pdo(), $otherDatabase->schema());
            $otherUser = MfaTestUser::create(['email' => $user->email,
                'password' => $otherApp->container()->make(PasswordHasher::class)->hash('correct')]);
            self::assertSame((string) $user->id, (string) $otherUser->id);
            $otherApp->container()->make(AuthManager::class)->login($otherUser);
            self::assertFalse($otherManager->forGuard('web')->enabled());
        } finally {
            $other->cleanup();
        }
    }

    public function testPendingMfaDeniesRbacUntilARecoveryProofCompletesTheChallenge(): void
    {
        $user = $this->user();
        $rbac = $this->app()->container()->make(RbacManager::class);
        $rbac->createRole('editor');
        $rbac->createPermission('reports.view');
        $rbac->grantPermission('editor', 'reports.view');
        $rbac->assignRole($user, 'editor');
        [, $codes] = $this->enroll($user);
        $authorization = $this->app()->container()->make(AuthorizationManager::class);
        self::assertTrue($authorization->allows('reports.view'));

        $this->auth->logout();
        self::assertFalse($this->attempt());
        self::assertTrue($this->mfa->pending());
        self::assertFalse($this->auth->check());
        self::assertFalse($authorization->allows('reports.view'));
        self::assertTrue($this->mfa->completeChallenge($codes[0]));
        self::assertTrue($this->auth->check());
        self::assertTrue($authorization->allows('reports.view'));
    }

    public function testStaleEnrollmentSnapshotCannotClaimOrManageNewGeneration(): void
    {
        $identity = MfaIdentity::from('web', $this->user());
        $repository = new DatabaseMfaRepository($this->database);
        $now = $this->clock->now()->getTimestamp();
        $firstSecret = 'encrypted-first-generation';
        $secondSecret = 'encrypted-second-generation';
        $firstHash = hash('sha256', $firstSecret);
        $secondHash = hash('sha256', $secondSecret);
        $sharedRecoveryHash = hash('sha256', 'same-code-in-both-generations');

        $repository->savePending($identity, $firstSecret, $now + 600);
        self::assertTrue($repository->activate($identity, $firstSecret, 100,
            [$sharedRecoveryHash], $now));
        self::assertTrue($repository->disable($identity, $firstHash));
        $repository->savePending($identity, $secondSecret, $now + 600);
        self::assertTrue($repository->activate($identity, $secondSecret, 200,
            [$sharedRecoveryHash], $now));

        self::assertFalse($repository->claimCounter($identity, 201, $firstHash));
        self::assertFalse($repository->claimRecoveryCode($identity, $sharedRecoveryHash, $firstHash));
        self::assertFalse($repository->replaceRecoveryCodes($identity,
            [hash('sha256', 'stale-replacement')], $firstHash));
        self::assertFalse($repository->disable($identity, $firstHash));
        self::assertSame(200, $repository->find($identity)['last_counter']);
        self::assertSame(1, $this->database->table('mfa_recovery_codes')->count());

        self::assertTrue($repository->claimCounter($identity, 201, $secondHash));
        self::assertFalse($repository->claimCounter($identity, 201, $secondHash));
        self::assertTrue($repository->claimRecoveryCode($identity, $sharedRecoveryHash, $secondHash));
        self::assertFalse($repository->claimRecoveryCode($identity, $sharedRecoveryHash, $secondHash));
    }
}

final class MfaClock implements ModelClock
{
    private DateTimeImmutable $current;
    public function __construct()
    {
        $this->current = new DateTimeImmutable('2030-01-01 00:00:00', new DateTimeZone('UTC'));
    }
    public function now(): DateTimeImmutable { return $this->current; }
    public function advance(string $modifier): void { $this->current = $this->current->modify($modifier); }
}

/**
 * @property int|string $id
 * @property string $email
 */
final class MfaTestUser extends Model implements Authenticatable
{
    protected string $table = 'mfa_test_users';
    protected array $fillable = ['email', 'password'];
    public function authIdentifier(): int|string { return $this->getAttribute('id'); }
    public function authPasswordHash(): string { return $this->getAttribute('password'); }
}
