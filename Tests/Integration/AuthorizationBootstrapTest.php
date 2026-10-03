<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Auth\Auth;
use App\Auth\AuthServiceProvider;
use App\Authorization\Authorization;
use App\Authorization\AuthorizationConfigurationException;
use App\Authorization\AuthorizationManager;
use App\Authorization\AuthorizationServiceProvider;
use App\Foundation\Application;
use App\Foundation\ServiceProvider;
use App\Session\Session;
use App\Session\SessionServiceProvider;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** Config validation and provider ordering need no Database or started Session. */
final class AuthorizationBootstrapTest extends TestCase
{
    protected function tearDown(): void
    {
        Authorization::setResolver(null);
        Auth::setResolver(null);
        Session::setResolver(null);
    }

    public function testConfiguredClassesStayLazyAndHelperUsesApplicationManager(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Config/Authorization.php', '<?php return '
                . var_export(['abilities' => ['reports.view' => BootstrapAbilityRule::class],
                    'policies' => [BootstrapSubject::class => BootstrapPolicy::class]], true) . ';');
            $app = $this->boot($project);
            $authorization = $app->container()->make(AuthorizationManager::class);
            self::assertSame($authorization, \authorize());
            self::assertTrue($authorization->forIdentity(new BootstrapIdentity())->allows('reports.view'));
            self::assertTrue($authorization->forIdentity(new BootstrapIdentity())->allows('create', BootstrapSubject::class));
            self::assertSame(0, session_status() === PHP_SESSION_ACTIVE ? 1 : 0);
        } finally {
            $project->remove();
        }
    }

    public function testInvalidConfigurationFailsAtBootBeforeRulesExecute(): void
    {
        $invalid = [
            ['abilities' => 'broken', 'policies' => []],
            ['abilities' => ['bad:name' => BootstrapAbilityRule::class], 'policies' => []],
            ['abilities' => ['reports.view' => \stdClass::class], 'policies' => []],
            ['abilities' => ['reports.view' => 'Missing\\Ability'], 'policies' => []],
            ['abilities' => [], 'policies' => ['Missing\\Subject' => BootstrapPolicy::class]],
            ['abilities' => [], 'policies' => [BootstrapSubject::class => 'Missing\\Policy']],
            ['abilities' => [], 'policies' => [], 'unexpected' => true],
        ];
        $failures = 0;
        foreach ($invalid as $data) {
            $project = new TemporaryProject();
            try {
                $project->write('Config/Authorization.php', '<?php return ' . var_export($data, true) . ';');
                try {
                    $this->boot($project);
                    self::fail('Invalid Authorization configuration was accepted.');
                } catch (AuthorizationConfigurationException) {
                    ++$failures;
                }
            } finally {
                $project->remove();
            }
        }
        self::assertSame(count($invalid), $failures);
    }

    public function testEmptyConfigurationBootsWithoutAuthGuardOrDatabase(): void
    {
        $project = new TemporaryProject();
        try {
            $app = $this->boot($project);
            self::assertInstanceOf(AuthorizationManager::class,
                $app->container()->make(AuthorizationManager::class));
            self::assertNotSame(PHP_SESSION_ACTIVE, session_status());
        } finally {
            $project->remove();
        }
    }

    public function testLaterApplicationProviderCanAddNonConflictingRule(): void
    {
        $project = new TemporaryProject();
        try {
            $app = new Application($project->path());
            foreach ([SessionServiceProvider::class, AuthServiceProvider::class,
                AuthorizationServiceProvider::class, BootstrapAuthorizationProvider::class] as $provider) {
                $app->register($provider);
            }
            $app->bootstrap();
            self::assertTrue($app->container()->make(AuthorizationManager::class)
                ->forIdentity(new BootstrapIdentity())->allows('provider.feature'));
        } finally {
            $project->remove();
        }
    }

    public function testConfiguredClosureIsRejectedBeforeEvaluation(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Config/Authorization.php',
                '<?php return ["abilities" => ["reports.view" => static fn ($user) => true], "policies" => []];');
            $this->expectException(AuthorizationConfigurationException::class);
            $this->boot($project);
        } finally {
            $project->remove();
        }
    }

    private function boot(TemporaryProject $project): Application
    {
        $app = new Application($project->path());
        foreach ([SessionServiceProvider::class, AuthServiceProvider::class,
            AuthorizationServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        $app->bootstrap();
        return $app;
    }
}

/** Explicit identity fixture for boot-time configuration checks. */
final class BootstrapIdentity
{
}

/** Class-level subject fixture does not require persistence. */
final class BootstrapSubject
{
}

/** Configured class rule is resolved only after a matching evaluation. */
final class BootstrapAbilityRule
{
    public function check(BootstrapIdentity $identity): bool { return true; }
}

/** Configured policy supports one class-level ability. */
final class BootstrapPolicy
{
    public function create(BootstrapIdentity $identity): bool { return true; }
}

/** Application providers register only after configured Authorization rules. */
final class BootstrapAuthorizationProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->container()->make(AuthorizationManager::class)
            ->define('provider.feature', static fn (BootstrapIdentity $identity): bool => true);
    }
}
