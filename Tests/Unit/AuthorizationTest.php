<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Auth\AuthManager;
use App\Auth\PasswordHasher;
use App\Authorization\AuthorizationConfigurationException;
use App\Authorization\AuthorizationDecision;
use App\Authorization\AuthorizationException;
use App\Authorization\AuthorizationManager;
use App\Config\Repository;
use App\Container\Container;
use App\Diagnostics\Diagnostics;
use App\Http\Request;
use App\Session\SessionManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Authorization rules use explicit identities without a database or started session. */
final class AuthorizationTest extends TestCase
{
    private AuthorizationManager $authorization;
    private Container $container;
    private Diagnostics $diagnostics;

    protected function setUp(): void
    {
        $config = new Repository(['auth' => ['default' => null, 'guards' => [], 'identities' => []]]);
        $this->container = new Container();
        $this->diagnostics = new Diagnostics($config);
        $this->authorization = new AuthorizationManager(
            new AuthManager($config, new SessionManager($config), new PasswordHasher($config)),
            $this->container,
            $this->diagnostics
        );
    }

    public function testDecisionIsImmutableAndCarriesOnlyAnOptionalDenialMessage(): void
    {
        $allowed = AuthorizationDecision::allow();
        $denied = AuthorizationDecision::deny('Unavailable.');
        self::assertTrue($allowed->allowed());
        self::assertFalse($allowed->denied());
        self::assertNull($allowed->message());
        self::assertTrue($denied->denied());
        self::assertSame('Unavailable.', $denied->message());
        self::assertTrue((new \ReflectionClass($allowed))->isReadOnly());
    }

    public function testGuestDeniesWithoutInvokingRegisteredRule(): void
    {
        $calls = 0;
        $this->authorization->define('reports.view', static function () use (&$calls): bool {
            ++$calls;
            return true;
        });
        self::assertFalse($this->authorization->allows('reports.view'));
        self::assertTrue($this->authorization->denies('reports.view'));
        try {
            $this->authorization->require('reports.view');
            self::fail('A guest must be denied.');
        } catch (AuthorizationException $exception) {
            self::assertSame(403, $exception->status());
        }
        self::assertSame(0, $calls);
    }

    public function testGlobalCallableAndExplicitContextEvaluateWithoutChangingAuth(): void
    {
        $identity = new AuthorizationIdentity(true);
        $other = new AuthorizationIdentity(false);
        $this->authorization->define('reports.view', static fn (AuthorizationIdentity $user): bool => $user->reports);
        $context = $this->authorization->forIdentity($identity);
        self::assertTrue($context->allows('reports.view'));
        self::assertFalse($context->denies('reports.view'));
        self::assertTrue($this->authorization->forIdentity($other)->denies('reports.view'));
        self::assertFalse($this->authorization->forIdentity(null)->allows('reports.view'));
        self::assertFalse($this->authorization->allows('reports.view'));
        $identity->reports = false;
        self::assertFalse($context->allows('reports.view')); // No decision cache.
    }

    public function testClassRuleAndPolicyResolveLazilyThroughContainer(): void
    {
        $probe = new AuthorizationProbe();
        $this->container->instance(AuthorizationProbe::class, $probe);
        $this->authorization->define('reports.view', AuthorizationReportsRule::class);
        $this->authorization->policy(AuthorizationPost::class, AuthorizationPostPolicy::class);
        self::assertSame(0, $probe->constructed);

        $user = new AuthorizationIdentity(true);
        $post = new AuthorizationPost(true);
        self::assertTrue($this->authorization->forIdentity($user)->allows('reports.view'));
        self::assertTrue($this->authorization->forIdentity($user)->allows('update', $post));
        self::assertTrue($this->authorization->forIdentity($user)->allows('create', AuthorizationPost::class));
        self::assertSame(3, $probe->constructed);
        $post->editable = false;
        self::assertTrue($this->authorization->forIdentity($user)->denies('update', $post));
        self::assertSame(4, $probe->constructed);
    }

    public function testCustomDecisionMessageAndInvalidResultsRemainDistinct(): void
    {
        $user = new AuthorizationIdentity(true);
        $post = new AuthorizationPost(false);
        $this->authorization->policy(AuthorizationPost::class, AuthorizationPostPolicy::class);
        $this->container->instance(AuthorizationProbe::class, new AuthorizationProbe());
        try {
            $this->authorization->forIdentity($user)->require('delete', $post);
            self::fail('A denied decision must throw.');
        } catch (AuthorizationException $exception) {
            self::assertSame('Locked <record>.', $exception->getMessage());
        }

        $this->authorization->define('invalid.result', static fn (AuthorizationIdentity $identity): string => 'yes');
        $this->expectException(AuthorizationConfigurationException::class);
        $this->authorization->forIdentity($user)->allows('invalid.result');
    }

    public function testMissingRulesBadNamesAndDuplicateRegistrationsFailClearly(): void
    {
        $user = new AuthorizationIdentity(true);
        foreach (['unknown.ability', '__construct', 'bad:ability'] as $name) {
            try {
                $this->authorization->forIdentity($user)->allows($name);
                self::fail('An unknown or malformed ability must fail.');
            } catch (AuthorizationConfigurationException) {
            }
        }
        $this->authorization->define('reports.view', static fn (AuthorizationIdentity $identity): bool => true);
        $this->expectException(AuthorizationConfigurationException::class);
        $this->authorization->define('reports.view', static fn (AuthorizationIdentity $identity): bool => false);
    }

    public function testPolicyMappingIsExactAndMethodFailuresAreConfigurationErrors(): void
    {
        $user = new AuthorizationIdentity(true);
        $this->authorization->policy(AuthorizationPost::class, AuthorizationPostPolicy::class);
        $this->container->instance(AuthorizationProbe::class, new AuthorizationProbe());
        $failures = 0;
        foreach ([new AuthorizationPostChild(true), new \stdClass()] as $subject) {
            try {
                $this->authorization->forIdentity($user)->allows('update', $subject);
                self::fail('An unregistered exact class must fail.');
            } catch (AuthorizationConfigurationException) {
                ++$failures;
            }
        }
        foreach (['missing', 'helper', 'staticRule', '__construct'] as $name) {
            try {
                $this->authorization->forIdentity($user)->allows($name, new AuthorizationPost(true));
                self::fail('An invalid policy method must fail.');
            } catch (AuthorizationConfigurationException) {
                ++$failures;
            }
        }
        try {
            $this->authorization->forIdentity($user)->allows('badSignature', new AuthorizationPost(true));
            self::fail('A wrong policy signature must fail.');
        } catch (AuthorizationConfigurationException) {
            ++$failures;
        }
        self::assertSame(7, $failures);
    }

    public function testPolicyDuplicatesInvalidResultsAndBusinessFailuresStayDistinct(): void
    {
        $user = new AuthorizationIdentity(true);
        $post = new AuthorizationPost(true);
        $this->authorization->policy(AuthorizationPost::class, AuthorizationPostPolicy::class);
        $this->container->instance(AuthorizationProbe::class, new AuthorizationProbe());
        try {
            $this->authorization->policy(AuthorizationPost::class, AuthorizationPostPolicy::class);
            self::fail('Duplicate policy registration was accepted.');
        } catch (AuthorizationConfigurationException) {
            self::assertTrue(true);
        }
        try {
            $this->authorization->forIdentity($user)->allows('invalidResult', $post);
            self::fail('An invalid policy return was accepted.');
        } catch (AuthorizationConfigurationException) {
            self::assertTrue(true);
        }
        try {
            $this->authorization->forIdentity($user)->allows('explode', $post);
            self::fail('An application exception was converted to deny.');
        } catch (RuntimeException $exception) {
            self::assertSame('Policy execution failed.', $exception->getMessage());
        }
        try {
            $this->authorization->forIdentity($user)->allows('update', 'not-a-class');
            self::fail('An arbitrary string subject was accepted.');
        } catch (AuthorizationConfigurationException) {
            self::assertTrue(true);
        }
    }

    public function testBusinessExceptionPropagatesAndDiagnosticsStoreOnlyCounts(): void
    {
        $this->diagnostics->begin(new Request('GET', '/'));
        $this->authorization->define('reports.view', static fn (AuthorizationIdentity $identity): bool => true);
        $this->authorization->define('reports.fail', static function (AuthorizationIdentity $identity): never {
            throw new RuntimeException('Policy failed.');
        });
        $user = new AuthorizationIdentity(true);
        self::assertTrue($this->authorization->forIdentity($user)->allows('reports.view'));
        self::assertFalse($this->authorization->forIdentity(null)->allows('reports.view'));
        try {
            $this->authorization->forIdentity($user)->allows('reports.fail');
            self::fail('A policy failure must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('Policy failed.', $exception->getMessage());
        }
        self::assertSame(['checks' => 3, 'allowed' => 1, 'denied' => 1, 'errors' => 1],
            $this->diagnostics->snapshot()['authorization']);
        self::assertStringNotContainsString('reports.view', json_encode($this->diagnostics->snapshot()));
        $this->diagnostics->begin(new Request('GET', '/next'));
        self::assertSame(['checks' => 0, 'allowed' => 0, 'denied' => 0, 'errors' => 0],
            $this->diagnostics->snapshot()['authorization']);
    }
}

/** Mutable identity fixture makes decision-cache regressions visible. */
final class AuthorizationIdentity
{
    public function __construct(public bool $reports)
    {
    }
}

/** Resource fixture has no dependency on SqueHub Model or persistence. */
class AuthorizationPost
{
    public function __construct(public bool $editable)
    {
    }
}

/** Subclasses require their own explicit policy registration. */
final class AuthorizationPostChild extends AuthorizationPost
{
}

/** Tracks Container construction without creating any database connection. */
final class AuthorizationProbe
{
    public int $constructed = 0;
}

/** One class-based global rule with constructor injection. */
final class AuthorizationReportsRule
{
    public function __construct(private AuthorizationProbe $probe)
    {
        ++$this->probe->constructed;
    }

    public function check(AuthorizationIdentity $identity): bool
    {
        return $identity->reports;
    }
}

/** Policy fixture exercises class and object decisions plus safe denial text. */
final class AuthorizationPostPolicy
{
    public function __construct(private AuthorizationProbe $probe)
    {
        ++$this->probe->constructed;
    }

    public function update(AuthorizationIdentity $identity, AuthorizationPost $post): bool
    {
        return $identity->reports && $post->editable;
    }

    public function create(AuthorizationIdentity $identity): bool
    {
        return $identity->reports;
    }

    public function delete(AuthorizationIdentity $identity, AuthorizationPost $post): AuthorizationDecision
    {
        return AuthorizationDecision::deny('Locked <record>.');
    }

    private function helper(AuthorizationIdentity $identity, AuthorizationPost $post): bool
    {
        return true;
    }

    public static function staticRule(AuthorizationIdentity $identity, AuthorizationPost $post): bool
    {
        return true;
    }

    public function badSignature(AuthorizationIdentity $identity): bool
    {
        return true;
    }

    public function invalidResult(AuthorizationIdentity $identity, AuthorizationPost $post): string
    {
        return 'allow';
    }

    public function explode(AuthorizationIdentity $identity, AuthorizationPost $post): never
    {
        throw new RuntimeException('Policy execution failed.');
    }
}
