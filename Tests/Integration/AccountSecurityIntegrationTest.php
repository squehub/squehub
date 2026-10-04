<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\AccountSecurity\AccountSecurity;
use App\AccountSecurity\AccountSecurityConfigurationException;
use App\AccountSecurity\AccountSecurityException;
use App\AccountSecurity\AccountSecurityManager;
use App\AccountSecurity\AccountSecurityServiceProvider;
use App\AccountSecurity\Repositories\DatabaseSecurityTokenRepository;
use App\AccountSecurity\SecurityTokenRecord;
use App\Auth\Auth;
use App\Auth\AuthManager;
use App\Auth\AuthServiceProvider;
use App\Auth\Contracts\Authenticatable;
use App\Auth\Guards\SessionGuard;
use App\Auth\Identity\ModelIdentityProvider;
use App\Auth\PasswordHasher;
use App\Database\Database;
use App\Database\DatabaseManager;
use App\Database\DatabaseServiceProvider;
use App\Database\Model;
use App\Database\ModelClock;
use App\Database\Schema\Table;
use App\Diagnostics\Diagnostics;
use App\Diagnostics\DiagnosticsServiceProvider;
use App\Foundation\Application;
use App\Logging\Drivers\ArrayLogger;
use App\Logging\Log;
use App\Logging\LoggingServiceProvider;
use App\Mail\Mail;
use App\Mail\Mailer;
use App\Mail\MailMessage;
use App\Mail\MailServiceProvider;
use App\Notifications\Notification;
use App\Notifications\Notifiable;
use App\Notifications\Notifications;
use App\Notifications\NotificationServiceProvider;
use App\Notifications\QueueNotifiable;
use App\Notifications\ShouldQueue;
use App\Queue\Queue;
use App\Queue\QueueServiceProvider;
use App\Queue\Worker;
use App\Session\Drivers\ArraySessionDriver;
use App\Session\Session;
use App\Session\SessionManager;
use App\Session\SessionServiceProvider;
use App\Session\SessionStore;
use App\Session\SessionDriver;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** Real SQLite account transitions with a controlled UTC framework clock. */
final class AccountSecurityIntegrationTest extends TestCase
{
    private TemporaryProject $project;
    private Application $app;
    private AuthManager $auth;
    private AccountSecurityManager $security;
    private DatabaseManager $database;
    private SessionStore $session;
    private AccountSecurityClock $clock;
    private Diagnostics $diagnostics;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) self::markTestSkipped('pdo_sqlite is required.');
        $this->project = new TemporaryProject();
        $this->project->write('Config/Database.php', '<?php return ["default" => "main", "connections" => ["main" => ["driver" => "sqlite", "database" => ":memory:"]]];');
        $this->project->write('Config/Session.php', '<?php return ["driver" => "array"];');
        $this->project->write('Config/Logging.php', '<?php return ["driver" => "array", "level" => "debug"];');
        $this->project->write('Config/Auth.php', '<?php return ' . var_export(self::authConfig(), true) . ';');
        $this->project->write('Config/AccountSecurity.php', '<?php return ["tokens" => ["driver" => "database", "table" => "account_security_tokens"], "password_reset" => ["ttl" => 60], "email_verification" => ["ttl" => 120]];');
        $this->project->write('Config/Mail.php', '<?php return ["default" => "array", "from" => ["address" => "sender@example.test"], "transports" => ["array" => ["driver" => "array"]]];');
        $this->project->write('Config/Queue.php', '<?php return ["default"=>"database","connections"=>'
            . '["database"=>["driver"=>"database","database_connection"=>"main"]]];');
        $this->app = new Application($this->project->path());
        foreach ([DiagnosticsServiceProvider::class, LoggingServiceProvider::class, DatabaseServiceProvider::class,
            SessionServiceProvider::class, AuthServiceProvider::class, AccountSecurityServiceProvider::class,
            MailServiceProvider::class, NotificationServiceProvider::class,
            QueueServiceProvider::class] as $provider) {
            $this->app->register($provider);
        }
        $this->clock = new AccountSecurityClock();
        $this->app->container()->instance(ModelClock::class, $this->clock);
        $this->app->bootstrap();
        $container = $this->app->container();
        $this->auth = $container->make(AuthManager::class);
        $this->security = $container->make(AccountSecurityManager::class);
        $this->database = $container->make(DatabaseManager::class);
        $this->session = $container->make(SessionManager::class)->store();
        $this->diagnostics = $container->make(Diagnostics::class);
        $this->database->schema()->create('security_users', static function (Table $table): void {
            $table->id();
            $table->string('email');
            $table->string('password');
            $table->datetime('email_verified_at')->nullable();
            $table->datetime('deleted_at')->nullable();
        });
        $this->database->schema()->create('account_security_tokens', static function (Table $table): void {
            $table->string('token_hash', 64);
            $table->string('purpose', 32);
            $table->string('guard', 64);
            $table->string('identity_identifier', 255);
            $table->string('context_hash', 64);
            $table->datetime('expires_at');
            $table->datetime('created_at');
            $table->unique('token_hash');
            $table->index(['guard', 'identity_identifier', 'purpose']);
            $table->index('expires_at');
        });
        require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_24_create_queue_tables.php';
        (new \CreateQueueTables())->up($this->database->connection()->pdo(),
            $this->database->connection()->schema());
    }

    protected function tearDown(): void
    {
        AccountSecurity::setResolver(null);
        Mail::setResolver(null);
        Notifications::setResolver(null);
        Queue::setResolver(null);
        Auth::setResolver(null);
        Log::setResolver(null);
        Session::setResolver(null);
        Database::setResolver(null);
        if (isset($this->project)) $this->project->remove();
    }

    private static function authConfig(): array
    {
        return ['default' => 'web', 'guards' => ['web' => ['driver' => 'session', 'identity' => 'users'],
            'admin' => ['driver' => 'session', 'identity' => 'users']],
            'identities' => ['users' => ['driver' => 'model', 'model' => AccountSecurityUser::class,
                'identifier' => 'id', 'password' => 'password', 'credentials' => ['email'],
                'verification_address' => 'email', 'verified_at' => 'email_verified_at']],
            'passwords' => ['algorithm' => 'bcrypt', 'options' => ['cost' => 4],
                'rehash_on_login' => false, 'max_bytes' => 4096]];
    }

    private function user(string $email = 'ada@example.test'): AccountSecurityUser
    {
        return AccountSecurityUser::create(['email' => $email,
            'password' => $this->app->container()->make(PasswordHasher::class)->hash('old-secret')]);
    }

    private function tokenRows(): array { return $this->database->table('account_security_tokens')->all(); }

    public function testResetAndVerificationTokensCanBeDeliveredExplicitly(): void
    {
        $user = $this->user();
        $mailer = $this->app->container()->make(Mailer::class);
        $outbox = $mailer->transport();
        $reset = $this->security->issuePasswordReset(['email' => $user->email]);
        $verify = $this->security->issueEmailVerification($user);
        self::assertNotNull($reset);
        self::assertNotNull($verify);
        self::assertCount(0, $outbox->messages());
        $mailer->send((new MailMessage())->to($user->email)->subject('Reset your password')
            ->text('Reset token: ' . $reset->token()));
        $mailer->send((new MailMessage())->to($user->email)->subject('Verify your email')
            ->text('Verification token: ' . $verify->token()));
        self::assertCount(2, $outbox->messages());
        self::assertStringContainsString($reset->token(), $outbox->messages()[0]->textBody());
        self::assertStringContainsString($verify->token(), $outbox->messages()[1]->textBody());
    }

    public function testResetAndVerificationTokensCanBeQueuedWithoutAccountSecurityDelivery(): void
    {
        $user = $this->user();
        $outbox = $this->app->container()->make(Mailer::class)->transport();
        $reset = $this->security->issuePasswordReset(['email' => $user->email]);
        $verify = $this->security->issueEmailVerification($user);
        self::assertNotNull($reset);
        self::assertNotNull($verify);
        self::assertSame([], $outbox->messages());
        $user->notify(new AccountQueuedTokenNotification('Reset', $reset->token()));
        $user->notify(new AccountQueuedTokenNotification('Verify', $verify->token()));
        self::assertSame([], $outbox->messages());
        self::assertSame(2, (int) $this->database->connection()->raw(
            'SELECT COUNT(*) FROM `queue_jobs`')->fetchColumn());
        $worker = $this->app->container()->make(Worker::class);
        self::assertTrue($worker->workOnce());
        self::assertTrue($worker->workOnce());
        self::assertCount(2, $outbox->messages());
        self::assertStringContainsString($reset->token(), $outbox->messages()[0]->textBody());
        self::assertStringContainsString($verify->token(), $outbox->messages()[1]->textBody());
        self::assertStringNotContainsString($reset->token(), json_encode($this->diagnostics->snapshot()));
        self::assertStringNotContainsString($verify->token(), json_encode($this->diagnostics->snapshot()));
    }

    public function testAccountTokenNotificationsWaitForCommitAndRollbackDiscardsDelivery(): void
    {
        $user = $this->user();
        $db = $this->database->connection();
        $outbox = $this->app->container()->make(Mailer::class)->transport();
        $db->begin();
        $reset = $this->security->issuePasswordReset(['email' => $user->email]);
        self::assertNotNull($reset);
        $user->notify(new AccountQueuedTokenNotification('Reset', $reset->token(), true));
        self::assertSame(0, (int) $db->raw('SELECT COUNT(*) FROM `queue_jobs`')->fetchColumn());
        self::assertSame([], $outbox->messages());
        $db->rollback();
        self::assertSame(0, (int) $db->raw('SELECT COUNT(*) FROM `queue_jobs`')->fetchColumn());
        self::assertSame([], $outbox->messages());

        $db->transaction(function () use ($user, $db): void {
            $verify = $this->security->issueEmailVerification($user);
            self::assertNotNull($verify);
            $user->notify(new AccountQueuedTokenNotification('Verify', $verify->token(), true));
            self::assertSame(0, (int) $db->raw('SELECT COUNT(*) FROM `queue_jobs`')->fetchColumn());
        });
        self::assertSame(1, (int) $db->raw('SELECT COUNT(*) FROM `queue_jobs`')->fetchColumn());
        self::assertSame([], $outbox->messages());
        self::assertTrue($this->app->container()->make(Worker::class)->workOnce());
        self::assertCount(1, $outbox->messages());
        self::assertStringNotContainsString('Token:', json_encode($this->diagnostics->snapshot()));
    }

    public function testQueuedAccountTokenFailureRetainsNoTokenInFailedMetadata(): void
    {
        $user = $this->user();
        $token = $this->security->issuePasswordReset(['email' => $user->email]);
        self::assertNotNull($token);
        $user->notify(new AccountQueuedTokenNotification('Reset', $token->token()));
        self::assertTrue($user->delete());
        self::assertTrue($this->app->container()->make(Worker::class)
            ->workOnce(tries: 1));
        self::assertSame([], $this->app->container()->make(Mailer::class)
            ->transport()->messages());
        $failed = $this->database->connection()->raw('SELECT * FROM `queue_failed_jobs`')->fetchAll();
        self::assertCount(1, $failed);
        self::assertStringNotContainsString($token->token(), json_encode($failed));
        self::assertStringNotContainsString($token->token(), json_encode($this->diagnostics->snapshot()));
    }

    public function testResetAndVerificationTokensCanBeDeliveredByNotification(): void
    {
        $user = $this->user();
        $before = $user->toArray();
        $reset = $this->security->issuePasswordReset(['email' => $user->email]);
        $verify = $this->security->issueEmailVerification($user);
        self::assertNotNull($reset);
        self::assertNotNull($verify);
        $outbox = $this->app->container()->make(Mailer::class)->transport();
        self::assertCount(0, $outbox->messages()); // Account Security has no delivery side effect.
        $user->notify(new AccountTokenNotification('Password reset', $reset->token()));
        $user->notify(new AccountTokenNotification('Email verification', $verify->token()));
        self::assertCount(2, $outbox->messages());
        self::assertStringContainsString($reset->token(), $outbox->messages()[0]->textBody());
        self::assertStringContainsString($verify->token(), $outbox->messages()[1]->textBody());
        self::assertSame($user->email, $outbox->messages()[0]->recipients()[0]->address());
        self::assertSame($before, $user->toArray());
        self::assertFalse($user->isDirty());
    }

    public function testPasswordChangeRotatesCurrentSessionAndStalesAnother(): void
    {
        $user = $this->user();
        $otherStore = new SessionStore(new ArraySessionDriver());
        $provider = new ModelIdentityProvider(AccountSecurityUser::class, 'id', 'password', ['email']);
        $other = new SessionGuard('web', $provider, $otherStore,
            $this->app->container()->make(PasswordHasher::class), ['email'], false);
        $this->auth->login($user);
        $other->login($user);
        $issued = $this->security->issuePasswordReset(['email' => $user->email]);
        $before = $this->session->id();
        $this->session->setCsrfToken('csrf-preserved');
        self::assertFalse($this->security->changePassword('incorrect', 'new-secret'));
        self::assertSame($before, $this->session->id());
        self::assertCount(1, $this->tokenRows());
        self::assertTrue($this->security->changePassword('old-secret', 'new-secret'));
        self::assertNotSame($before, $this->session->id());
        self::assertTrue($this->auth->check());
        self::assertSame('csrf-preserved', $this->session->csrfToken());
        self::assertSame([], $this->session->all());
        self::assertCount(0, $this->tokenRows());
        self::assertFalse($this->security->resetPassword($issued->token(), 'third-secret'));
        $other->resetRequestState();
        self::assertNull($other->user());
        self::assertNull($otherStore->authIdentifier('web'));
    }

    public function testResetTokensAreHashedBoundedReplacedExpiredAndConsumed(): void
    {
        $user = $this->user();
        self::assertNull($this->security->issuePasswordReset(['email' => 'missing@example.test']));
        self::assertCount(0, $this->tokenRows());
        $first = $this->security->issuePasswordReset(['email' => $user->email]);
        $second = $this->security->issuePasswordReset(['email' => $user->email]);
        self::assertNotSame($first->token(), $second->token());
        self::assertFalse($this->security->resetPassword($first->token(), 'new-secret'));
        $rows = $this->tokenRows();
        self::assertCount(1, $rows);
        self::assertSame(hash('sha256', $second->token()), $rows[0]['token_hash']);
        self::assertStringNotContainsString($second->token(), json_encode($rows));
        self::assertStringNotContainsString($user->authPasswordHash(), json_encode($rows));
        self::assertStringNotContainsString($user->email, json_encode($rows));
        self::assertTrue($this->security->resetPassword($second->token(), 'new-secret'));
        self::assertFalse($this->security->resetPassword($second->token(), 'again-secret'));
        self::assertTrue($this->app->container()->make(PasswordHasher::class)->verify('new-secret',
            AccountSecurityUser::find($user->id)->authPasswordHash()));
        $expiring = $this->security->issuePasswordReset(['email' => $user->email]);
        self::assertSame('2030-01-01 00:01:00', $this->tokenRows()[0]['expires_at']);
        $this->clock->advance('+60 seconds');
        self::assertSame('2030-01-01 00:01:00', $this->clock->now()->format('Y-m-d H:i:s'));
        self::assertFalse($this->security->resetPassword($expiring->token(), 'later-secret'));
        self::assertCount(0, $this->tokenRows());
    }

    public function testPasswordChangeAndSeparateHashChangeInvalidateResetContext(): void
    {
        $user = $this->user();
        $issued = $this->security->issuePasswordReset(['email' => $user->email]);
        $provider = $this->auth->guard('web')->identityProvider();
        $provider->updatePassword($user, $this->app->container()->make(PasswordHasher::class)->hash('outside'));
        self::assertFalse($this->security->resetPassword($issued->token(), 'new-secret'));
        self::assertFalse($this->security->resetPassword($issued->token(), 'new-secret'));
    }

    public function testResetInvalidatesTwoAuthenticatedSessionsOnTheirNextResolution(): void
    {
        $user = $this->user();
        $this->auth->login($user);
        $otherStore = new SessionStore(new ArraySessionDriver());
        $other = new SessionGuard('web',
            new ModelIdentityProvider(AccountSecurityUser::class, 'id', 'password', ['email']),
            $otherStore, $this->app->container()->make(PasswordHasher::class), ['email'], false);
        $other->login($user);
        $token = $this->security->issuePasswordReset(['email' => $user->email]);
        self::assertTrue($this->security->resetPassword($token->token(), 'reset-secret'));
        $this->auth->resetRequestState();
        $other->resetRequestState();
        self::assertFalse($this->auth->check());
        self::assertNull($other->user());
        self::assertNull($this->session->authIdentifier('web'));
        self::assertNull($otherStore->authIdentifier('web'));
    }

    public function testDeletedIdentitiesAreNotIssuedOrRestoredByTokens(): void
    {
        $user = $this->user();
        $reset = $this->security->issuePasswordReset(['email' => $user->email]);
        $verify = $this->security->issueEmailVerification($user);
        self::assertTrue($user->delete());
        self::assertNull($this->security->issuePasswordReset(['email' => $user->email]));
        self::assertFalse($this->security->resetPassword($reset->token(), 'new-secret'));
        self::assertFalse($this->security->verifyEmail($verify->token()));
        self::assertTrue($user->isDeleted());
        self::assertNull(AccountSecurityUser::find($user->id));
    }

    public function testIdentifierOnlySessionRequiresFreshLogin(): void
    {
        $user = $this->user();
        $this->session->setAuthIdentifier('web', $user->authIdentifier());
        self::assertFalse($this->auth->check());
        self::assertNull($this->session->authIdentifier('web'));
        $this->auth->login($user);
        self::assertTrue($this->auth->check());
    }

    public function testFailedRotationAfterCredentialWriteClearsCurrentGuard(): void
    {
        $user = $this->user();
        $driver = new AccountSecurityFailingRotationDriver();
        $store = new SessionStore($driver);
        $provider = new ModelIdentityProvider(AccountSecurityUser::class, 'id', 'password', ['email']);
        $guard = new SessionGuard('web', $provider, $store,
            $this->app->container()->make(PasswordHasher::class), ['email'], false);
        $guard->login($user);
        $provider->updatePassword($user, $this->app->container()->make(PasswordHasher::class)->hash('changed'));
        $driver->failRotation = true;
        try {
            $guard->refreshCredential($user);
            self::fail('Failed rotation must propagate.');
        } catch (\RuntimeException) {
            self::assertNull($store->authIdentifier('web'));
            $guard->resetRequestState();
            self::assertNull($guard->user());
        }
    }

    public function testEmailVerificationBindsAddressAndDoesNotAuthenticate(): void
    {
        $user = $this->user();
        $first = $this->security->issueEmailVerification($user);
        self::assertNotNull($first);
        $second = $this->security->issueEmailVerification($user);
        self::assertFalse($this->security->verifyEmail($first->token()));
        self::assertCount(1, $this->tokenRows());
        self::assertTrue($this->security->verifyEmail($second->token()));
        self::assertFalse($this->security->verifyEmail($second->token()));
        self::assertFalse($this->auth->check());
        self::assertNotNull(AccountSecurityUser::find($user->id)->email_verified_at);
        self::assertNull($this->security->issueEmailVerification(AccountSecurityUser::find($user->id)));

        $other = $this->user('bea@example.test');
        $token = $this->security->issueEmailVerification($other);
        $other->email = 'new@example.test';
        $other->save();
        self::assertFalse($this->security->verifyEmail($token->token()));
        self::assertNull(AccountSecurityUser::find($other->id)->email_verified_at);
    }

    public function testNamedGuardAndRepositoryClaimIsolation(): void
    {
        $user = $this->user();
        $admin = $this->security->forGuard('admin');
        $adminToken = $admin->issuePasswordReset(['email' => $user->email]);
        self::assertFalse($this->security->resetPassword($adminToken->token(), 'new-secret'));
        self::assertTrue($admin->resetPassword($adminToken->token(), 'new-secret'));
        self::assertFalse($admin->resetPassword($adminToken->token(), 'again-secret'));
        $repo = new DatabaseSecurityTokenRepository($this->database, 'account_security_tokens');
        $now = $this->clock->now();
        $record = new SecurityTokenRecord(str_repeat('a', 64), 'password_reset', 'web',
            SecurityTokenRecord::key($user->id), str_repeat('b', 64), $now->modify('+60 seconds'), $now);
        $repo->replace($record);
        try {
            $repo->replace($record);
            self::fail('A duplicate token hash was accepted.');
        } catch (AccountSecurityConfigurationException) {
            self::assertCount(1, $this->tokenRows());
        }
        self::assertNull($repo->claim($record->tokenHash, 'email_verification', 'web', $now));
        self::assertNotNull($repo->claim($record->tokenHash, 'password_reset', 'web', $now));
        self::assertNull($repo->claim($record->tokenHash, 'password_reset', 'web', $now));
    }

    public function testTokenReplacementRespectsCallerTransaction(): void
    {
        $user = $this->user();
        $this->database->begin();
        try {
            $token = $this->security->issuePasswordReset(['email' => $user->email]);
            self::assertNotNull($token);
            self::assertCount(1, $this->tokenRows());
            $this->database->rollback();
        } catch (\Throwable $failure) {
            $this->database->rollback();
            throw $failure;
        }
        self::assertCount(0, $this->tokenRows());
    }

    public function testConfigErrorsAreDistinctFromInvalidTokens(): void
    {
        self::assertFalse($this->security->resetPassword('bad-token', 'new-secret'));
        try {
            $this->security->forGuard('missing');
            self::fail('Unknown guard was accepted.');
        } catch (AccountSecurityConfigurationException) {
            self::assertTrue(true);
        }
        $user = $this->user();
        try {
            $this->security->changePassword('old-secret', 'old-secret');
            self::fail('Guest password change was accepted.');
        } catch (AccountSecurityException) {
            self::assertTrue(true);
        }
        $this->auth->login($user);
        $this->expectException(AccountSecurityException::class);
        $this->security->changePassword('old-secret', 'old-secret');
    }

    public function testDiagnosticsContainOnlyAggregateCounters(): void
    {
        $this->diagnostics->begin(new \App\Http\Request('GET', '/'));
        $user = $this->user();
        $this->security->issuePasswordReset(['email' => $user->email]);
        $this->security->issueEmailVerification($user);
        $snapshot = $this->diagnostics->snapshot()['account_security'];
        self::assertSame(1, $snapshot['reset_tokens_issued']);
        self::assertSame(1, $snapshot['verification_tokens_issued']);
        self::assertStringNotContainsString($user->email, json_encode($snapshot));
        self::assertSame([], $this->app->container()->make(ArrayLogger::class)->records());
        $this->diagnostics->begin(new \App\Http\Request('GET', '/next'));
        self::assertSame(0, $this->diagnostics->snapshot()['account_security']['reset_tokens_issued']);
    }

    public function testResetPathHasBoundedIdentityAndTokenQueries(): void
    {
        $user = $this->user();
        $this->diagnostics->begin(new \App\Http\Request('GET', '/issue'));
        $token = $this->security->issuePasswordReset(['email' => $user->email]);
        self::assertSame(4, $this->diagnostics->queryCount());
        $this->diagnostics->begin(new \App\Http\Request('GET', '/consume'));
        self::assertTrue($this->security->resetPassword($token->token(), 'changed'));
        self::assertSame(5, $this->diagnostics->queryCount());
    }

    public function testMissingTokenTableSurfacesInfrastructureFailureWithoutIssuing(): void
    {
        $user = $this->user();
        $this->diagnostics->begin(new \App\Http\Request('GET', '/'));
        $this->database->schema()->drop('account_security_tokens');
        try {
            $this->security->issuePasswordReset(['email' => $user->email]);
            self::fail('A missing token table must not report issuance.');
        } catch (\App\Database\Exception\QueryException $exception) {
            self::assertStringNotContainsString($user->email, $exception->getMessage());
            self::assertSame(0, $this->diagnostics->snapshot()['account_security']['reset_tokens_issued']);
            self::assertSame(1, $this->diagnostics->snapshot()['account_security']['errors']);
        }
    }

    public function testIdentityLookupFailureAfterClaimConsumesResetToken(): void
    {
        $user = $this->user();
        $token = $this->security->issuePasswordReset(['email' => $user->email]);
        $this->database->schema()->drop('security_users');
        try {
            $this->security->resetPassword($token->token(), 'new-secret');
            self::fail('A failed identity lookup must propagate.');
        } catch (\App\Database\Exception\QueryException) {
            self::assertCount(0, $this->tokenRows());
            self::assertFalse($this->security->resetPassword($token->token(), 'new-secret'));
        }
    }
}

/** Mutable UTC fixture avoids sleep() in token-expiry tests. */
final class AccountSecurityClock implements ModelClock
{
    private DateTimeImmutable $current;
    public function __construct() { $this->current = new DateTimeImmutable('2030-01-01 00:00:00', new DateTimeZone('UTC')); }
    public function now(): DateTimeImmutable { return $this->current; }
    public function advance(string $modifier): void { $this->current = $this->current->modify($modifier); }
}

/** Modern identity fixture uses targeted password and verification writes. */
final class AccountSecurityUser extends Model implements Authenticatable, QueueNotifiable
{
    use Notifiable;
    protected string $table = 'security_users';
    protected array $fillable = ['email', 'password'];
    protected bool $softDeletes = true;
    public function authIdentifier(): int|string { return $this->getAttribute('id'); }
    public function authPasswordHash(): string { return $this->getAttribute('password'); }
    public function routeNotificationForMail(): ?string { return $this->getAttribute('email'); }
    public function notificationQueueIdentity(): array { return ['id' => $this->getAttribute('id')]; }
    public static function resolveNotificationQueueIdentity(array $identity): static
    {
        $id = $identity['id'] ?? null;
        if (!is_int($id) && !is_string($id)) throw new \RuntimeException('Missing identity.');
        return static::find($id) ?? throw new \RuntimeException('Identity no longer exists.');
    }
}

/** Application-authored token content; routing belongs to the identity. */
final class AccountTokenNotification extends Notification
{
    public function __construct(private string $subject, private string $token) {}
    public function via(mixed $notifiable): array { return ['mail']; }
    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage())->subject($this->subject)->text('Token: ' . $this->token);
    }
}

/** Queue payload carries only the issued token and application-authored text. */
final class AccountQueuedTokenNotification extends Notification implements ShouldQueue
{
    public function __construct(private string $subject, private string $token,
        private bool $afterCommit = false) {}
    public function via(mixed $notifiable): array { return ['mail']; }
    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage())->subject($this->subject)->text('Token: ' . $this->token);
    }
    public function toQueuePayload(): array { return ['subject' => $this->subject, 'token' => $this->token]; }
    public function queueAfterCommit(): bool { return $this->afterCommit; }
    public static function fromQueuePayload(array $payload): static
    {
        if (!is_string($payload['subject'] ?? null) || !is_string($payload['token'] ?? null)) {
            throw new \RuntimeException('Invalid token notice.');
        }
        return new static($payload['subject'], $payload['token']);
    }
}

/** Injects a rotation failure after login to verify fail-closed guard state. */
final class AccountSecurityFailingRotationDriver implements SessionDriver
{
    private ArraySessionDriver $inner;
    public bool $failRotation = false;
    public function __construct() { $this->inner = new ArraySessionDriver(); }
    public function start(): void { $this->inner->start(); }
    public function data(): array { return $this->inner->data(); }
    public function replace(array $data): void { $this->inner->replace($data); }
    public function id(): string { return $this->inner->id(); }
    public function regenerate(): void
    {
        if ($this->failRotation) throw new \RuntimeException('Rotation unavailable.');
        $this->inner->regenerate();
    }
    public function invalidate(): void { $this->inner->invalidate(); }
    public function close(): void { $this->inner->close(); }
}
