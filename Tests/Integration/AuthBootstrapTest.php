<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Auth\Auth;
use App\Auth\AuthException;
use App\Auth\AuthManager;
use App\Auth\AuthServiceProvider;
use App\Auth\Contracts\Authenticatable;
use App\Auth\PasswordHasher;
use App\Database\Database;
use App\Database\DatabaseServiceProvider;
use App\Database\Model;
use App\Database\Schema\Table;
use App\Foundation\Application;
use App\Http\HttpServiceProvider;
use App\Routing\Route;
use App\Routing\RoutingServiceProvider;
use App\Session\Session;
use App\Session\SessionServiceProvider;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** A project with no User model or Auth config remains safe to bootstrap for CLI. */
final class AuthBootstrapTest extends TestCase
{
    public function testUnconfiguredAuthenticationDoesNotOpenSessionOrDatabase(): void
    {
        $project = new TemporaryProject();
        $project->write('Config/Session.php', '<?php return ["driver" => "native"];');
        $project->write('Config/Database.php', '<?php return ["default" => "missing", "connections" => []];');
        try {
            $app = new Application($project->path());
            foreach ([DatabaseServiceProvider::class, SessionServiceProvider::class,
                HttpServiceProvider::class, RoutingServiceProvider::class, AuthServiceProvider::class] as $provider) {
                $app->register($provider);
            }
            $app->bootstrap();
            self::assertSame(PHP_SESSION_NONE, session_status());
            self::assertInstanceOf(AuthManager::class, $app->container()->make(AuthManager::class));
            $this->expectException(AuthException::class);
            $app->container()->make(AuthManager::class)->check();
        } finally {
            Auth::setResolver(null);
            Session::setResolver(null);
            Database::setResolver(null);
            Route::setResolver(null);
            $project->remove();
        }
    }

    public function testAuthenticationWorksWithoutHttpRoutingOrOtherServices(): void
    {
        $project = new TemporaryProject();
        $project->write('Config/Session.php', '<?php return ["driver" => "array"];');
        $project->write('Config/Database.php', '<?php return ["default" => "main", "connections" => ["main" => ["driver" => "sqlite", "database" => ":memory:"]]];');
        $project->write('Config/Auth.php', '<?php return ' . var_export([
            'default' => 'web',
            'guards' => ['web' => ['driver' => 'session', 'identity' => 'users']],
            'identities' => ['users' => ['driver' => 'model', 'model' => MinimalAuthUser::class,
                'identifier' => 'id', 'password' => 'password', 'credentials' => ['email']]],
            'passwords' => ['algorithm' => 'bcrypt', 'options' => ['cost' => 4]],
        ], true) . ';');
        try {
            $app = new Application($project->path());
            foreach ([DatabaseServiceProvider::class, SessionServiceProvider::class,
                AuthServiceProvider::class] as $provider) $app->register($provider);
            $app->bootstrap();
            $database = $app->container()->make(\App\Database\DatabaseManager::class);
            $database->schema()->create('minimal_auth_users', static function (Table $table): void {
                $table->id();
                $table->string('email');
                $table->string('password');
            });
            $hash = $app->container()->make(PasswordHasher::class)->hash('correct');
            MinimalAuthUser::create(['email' => 'minimal@example.test', 'password' => $hash]);
            self::assertTrue($app->container()->make(AuthManager::class)->attempt([
                'email' => 'minimal@example.test', 'password' => 'correct',
            ]));
            self::assertTrue($app->container()->make(AuthManager::class)->check());
        } finally {
            Auth::setResolver(null);
            Session::setResolver(null);
            Database::setResolver(null);
            $project->remove();
        }
    }
}

/** Minimal modern identity for the Application-only provider path. */
final class MinimalAuthUser extends Model implements Authenticatable
{
    protected string $table = 'minimal_auth_users';
    protected array $fillable = ['email', 'password'];
    public function authIdentifier(): int|string { return $this->getAttribute('id'); }
    public function authPasswordHash(): string { return $this->getAttribute('password'); }
}
