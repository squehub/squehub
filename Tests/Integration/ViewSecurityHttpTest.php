<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Auth\Contracts\Authenticatable;
use App\Authorization\AuthorizationManager;
use App\Database\DatabaseManager;
use App\Database\Model;
use App\Database\Schema\Table;
use App\Diagnostics\Diagnostics;
use App\Plugins\TestCase;
use App\Testing\TestApplication;
use App\Testing\TestClient;
use PDO;
use RuntimeException;

/** A persisted identity exercises the normal session guard and provider path. */
final class ViewSecurityUser extends Model implements Authenticatable
{
    protected string $table = 'view_security_users';
    protected array $fillable = ['email', 'name', 'password'];
    protected array $hidden = ['password'];

    public function authIdentifier(): int|string { return (int) $this->getAttribute('id'); }
    public function authPasswordHash(): string { return (string) $this->getAttribute('password'); }
}

/** Resource policy fixtures deliberately use Authorization's method-name contract. */
final readonly class ViewSecurityOrder
{
    public function __construct(public string $owner)
    {
    }
}

/** A thrown policy error must remain an infrastructure failure, not a denial. */
final class ViewSecurityOrderPolicy
{
    public function update(ViewSecurityUser $identity, ViewSecurityOrder $order): bool
    {
        return $identity->getAttribute('name') === $order->owner;
    }

    public function explode(ViewSecurityUser $identity, ViewSecurityOrder $order): never
    {
        throw new RuntimeException('POLICY_SECRET_DO_NOT_EXPOSE');
    }
}

/** Real Kernel coverage for View security presentation and route enforcement. */
final class ViewSecurityHttpTest extends TestCase
{
    private ViewSecurityUser $ada;
    private ViewSecurityUser $bea;

    /** @return array<string, array<string, mixed>> */
    protected function testingConfig(): array
    {
        return ['auth' => [
            'default' => 'web',
            'guards' => [
                'web' => ['driver' => 'session', 'identity' => 'users'],
                'admin' => ['driver' => 'session', 'identity' => 'users'],
            ],
            'identities' => ['users' => [
                'driver' => 'model', 'model' => ViewSecurityUser::class,
                'identifier' => 'id', 'password' => 'password', 'credentials' => ['email'],
            ]],
            'passwords' => ['algorithm' => 'bcrypt', 'options' => ['cost' => 4],
                'rehash_on_login' => false, 'max_bytes' => 4096],
            'browser' => ['login_path' => null, 'authenticated_path' => null],
        ]];
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for View security integration.');
        }
        $this->writeFixtures();
        $container = $this->app()->container();
        $container->make(DatabaseManager::class)->schema()->create('view_security_users',
            static function (Table $table): void {
                $table->id();
                $table->string('email');
                $table->string('name');
                $table->string('password');
            });
        $authorization = $container->make(AuthorizationManager::class);
        $authorization->define('reports.view',
            static fn (ViewSecurityUser $identity): bool => $identity->getAttribute('name') === 'Ada');
        $authorization->policy(ViewSecurityOrder::class, ViewSecurityOrderPolicy::class);
        $this->ada = $this->user('Ada');
        $this->bea = $this->user('Bea');
    }

    private function user(string $name): ViewSecurityUser
    {
        return ViewSecurityUser::create([
            'email' => strtolower($name) . '@example.test',
            'name' => $name,
            'password' => password_hash('fixture', PASSWORD_BCRYPT, ['cost' => 4]),
        ]);
    }

    private function writeFixtures(): void
    {
        $testing = $this->testApplication();
        $testing->write('Project/Views/Phase14J/Auth.squehub.php', <<<'VIEW'
@auth<default-auth>{{ auth()->user()->name }}</default-auth>@else<default-auth>guest</default-auth>@endauth
@guest<default-guest>yes</default-guest>@else<default-guest>no</default-guest>@endguest
@auth('admin')<admin-auth>{{ auth()->guard('admin')->user()->name }}</admin-auth>@else<admin-auth>guest</admin-auth>@endauth
@guest('admin')<admin-guest>yes</admin-guest>@else<admin-guest>no</admin-guest>@endguest
VIEW);
        $testing->write('Project/Views/Phase14J/Permissions.squehub.php', <<<'VIEW'
@can('reports.view')<report>allowed</report>@else<report>denied</report>@endcan
@cannot('reports.view')<report-cannot>yes</report-cannot>@else<report-cannot>no</report-cannot>@endcannot
@can('update', $order)<edit>yes</edit>@else<edit>no</edit>@endcan
@cannot('update', $order)<cannot-edit>yes</cannot-edit>@else<cannot-edit>no</cannot-edit>@endcannot
VIEW);
        $testing->write('Project/Views/Phase14J/Assets.squehub.php', <<<'VIEW'
<head>@stack('scripts')</head>
@auth @script('/account.js') <account-menu>visible</account-menu> @endauth
@can('reports.view') @component('SecurityChart')@endcomponent @endcan
VIEW);
        $testing->write('Project/Views/Phase14J/Status.squehub.php', <<<'VIEW'
@session('status')<status>{{ $value }}</status>@else<status>missing</status>@endsession
VIEW);
        $testing->write('Project/Views/Components/SecurityChart.squehub.php', <<<'VIEW'
@props([])
@script('/chart.js')
<security-chart>visible</security-chart>
VIEW);
        $testing->write('Project/Views/Phase14J/BadGuard.squehub.php',
            "@auth('AUTH_SECRET_DO_NOT_EXPOSE')<secret>bad</secret>@endauth");
        $testing->write('Project/Views/Phase14J/BadAbility.squehub.php',
            "@can('missing.ability')<secret>bad</secret>@else<denied>wrong</denied>@endcan");
        $testing->write('Project/Views/Phase14J/BadPolicy.squehub.php',
            "@can('explode', \$order)<secret>bad</secret>@else<denied>wrong</denied>@endcan");
        $testing->write('Project/Views/Phase14J/BadSession.squehub.php', <<<'VIEW'
@session("SESSION_SECRET_DO_NOT_EXPOSE\n")<secret>bad</secret>@endsession
VIEW);
        $testing->write('Project/Views/Phase14J/BadSyntax.squehub.php',
            "@can('TOKEN_SECRET_DO_NOT_EXPOSE',)<secret>bad</secret>@endcan");
        $testing->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/view-auth')->get(static function (): void {
    \App\Core\View::render('Phase14J.Auth');
});
\App\Routing\Route::path('/orders/ada')->get(static function (): void {
    \App\Core\View::render('Phase14J.Permissions', [
        'order' => new \SqueHub\Tests\Integration\ViewSecurityOrder('Ada'),
    ]);
});
\App\Routing\Route::path('/orders/bea')->get(static function (): void {
    \App\Core\View::render('Phase14J.Permissions', [
        'order' => new \SqueHub\Tests\Integration\ViewSecurityOrder('Bea'),
    ]);
});
\App\Routing\Route::path('/security-assets')->get(static function (): void {
    \App\Core\View::render('Phase14J.Assets');
});
\App\Routing\Route::path('/flash-status')->post(static function (): \App\Http\Response {
    \session()->flash('status', 'Saved');
    return new \App\Http\RedirectResponse('/flash-state', 303);
});
\App\Routing\Route::path('/flash-state')->get(static function (): void {
    \App\Core\View::render('Phase14J.Status');
});
\App\Routing\Route::path('/unguarded-edit')->get(static fn (): string => 'action-executed');
\App\Routing\Route::path('/protected-edit')->get(static function (): string {
    \authorize()->require('update', new \SqueHub\Tests\Integration\ViewSecurityOrder('Ada'));
    return 'action-executed';
});
\App\Routing\Route::path('/protected-report')->get(static fn (): string => 'report')
    ->through(\App\Authorization\Middleware\RequireAbility::named('reports.view'));
\App\Routing\Route::path('/bad-guard')->get(static function (): void {
    \App\Core\View::render('Phase14J.BadGuard');
});
\App\Routing\Route::path('/bad-ability')->get(static function (): void {
    \App\Core\View::render('Phase14J.BadAbility');
});
\App\Routing\Route::path('/bad-policy')->get(static function (): void {
    \App\Core\View::render('Phase14J.BadPolicy', [
        'order' => new \SqueHub\Tests\Integration\ViewSecurityOrder('Ada'),
    ]);
});
\App\Routing\Route::path('/bad-session')->get(static function (): void {
    \App\Core\View::render('Phase14J.BadSession');
});
\App\Routing\Route::path('/bad-syntax')->get(static function (): void {
    \App\Core\View::render('Phase14J.BadSyntax');
});
PHP);
    }

    public function testDefaultAndNamedGuardsStayIndependentAcrossRequests(): void
    {
        $guest = $this->get('/view-auth')->assertOk()->content();
        self::assertStringContainsString('<default-auth>guest</default-auth>', $guest);
        self::assertStringContainsString('<default-guest>yes</default-guest>', $guest);
        self::assertStringContainsString('<admin-auth>guest</admin-auth>', $guest);
        self::assertStringContainsString('<admin-guest>yes</admin-guest>', $guest);

        $this->actingAs($this->ada);
        $webOnly = $this->get('/view-auth')->assertOk()->content();
        self::assertStringContainsString('<default-auth>Ada</default-auth>', $webOnly);
        self::assertStringContainsString('<default-guest>no</default-guest>', $webOnly);
        self::assertStringContainsString('<admin-auth>guest</admin-auth>', $webOnly);
        self::assertStringContainsString('<admin-guest>yes</admin-guest>', $webOnly);
        // Repeated directives share SessionGuard's one identity lookup for a request.
        self::assertSame(1, $this->app()->container()->make(Diagnostics::class)->queryCount());

        $this->actingAs($this->bea, 'admin');
        $both = $this->get('/view-auth')->assertOk()->content();
        self::assertStringContainsString('<default-auth>Ada</default-auth>', $both);
        self::assertStringContainsString('<admin-auth>Bea</admin-auth>', $both);
        self::assertStringContainsString('<admin-guest>no</admin-guest>', $both);

        $this->guest();
        $loggedOut = $this->get('/view-auth')->assertOk()->content();
        self::assertStringContainsString('<default-auth>guest</default-auth>', $loggedOut);
        self::assertStringContainsString('<admin-auth>guest</admin-auth>', $loggedOut);

        $this->actingAs($this->bea, 'admin');
        $adminOnly = $this->get('/view-auth')->assertOk()->content();
        self::assertStringContainsString('<default-auth>guest</default-auth>', $adminOnly);
        self::assertStringContainsString('<admin-auth>Bea</admin-auth>', $adminOnly);
    }

    public function testGlobalAbilitiesAndResourcePoliciesFollowCurrentIdentityAndResource(): void
    {
        $guest = $this->get('/orders/ada')->assertOk()->content();
        self::assertStringContainsString('<report>denied</report>', $guest);
        self::assertStringContainsString('<report-cannot>yes</report-cannot>', $guest);
        self::assertStringContainsString('<edit>no</edit>', $guest);
        self::assertStringContainsString('<cannot-edit>yes</cannot-edit>', $guest);

        $this->actingAs($this->ada);
        $own = $this->get('/orders/ada')->assertOk()->content();
        self::assertStringContainsString('<report>allowed</report>', $own);
        self::assertStringContainsString('<report-cannot>no</report-cannot>', $own);
        self::assertStringContainsString('<edit>yes</edit>', $own);
        self::assertStringContainsString('<cannot-edit>no</cannot-edit>', $own);
        $other = $this->get('/orders/bea')->assertOk()->content();
        self::assertStringContainsString('<report>allowed</report>', $other);
        self::assertStringContainsString('<edit>no</edit>', $other);

        $this->actingAs($this->bea);
        $beaOwn = $this->get('/orders/bea')->assertOk()->content();
        self::assertStringContainsString('<report>denied</report>', $beaOwn);
        self::assertStringContainsString('<edit>yes</edit>', $beaOwn);
        $adaOrder = $this->get('/orders/ada')->assertOk()->content();
        self::assertStringContainsString('<edit>no</edit>', $adaOrder);
    }

    public function testHiddenActionDoesNotReplaceRouteOrControllerAuthorization(): void
    {
        $this->actingAs($this->bea);
        $view = $this->get('/orders/ada')->assertOk()->content();
        self::assertStringContainsString('<edit>no</edit>', $view);
        $this->get('/unguarded-edit')->assertOk()->assertContains('action-executed');
        $this->get('/protected-edit')->assertStatus(403)->assertNotContains('action-executed');
        $this->get('/protected-report')->assertStatus(403)->assertNotContains('report');

        $this->actingAs($this->ada);
        $this->get('/protected-edit')->assertOk()->assertContains('action-executed');
        $this->get('/protected-report')->assertOk()->assertContains('report');
    }

    public function testHiddenSecurityBranchesDoNotActivateNestedAssets(): void
    {
        $guest = $this->get('/security-assets')->assertOk()->content();
        self::assertStringNotContainsString('/account.js', $guest);
        self::assertStringNotContainsString('/chart.js', $guest);
        self::assertStringNotContainsString('<account-menu>', $guest);
        self::assertStringNotContainsString('<security-chart>', $guest);

        $this->actingAs($this->bea);
        $denied = $this->get('/security-assets')->assertOk()->content();
        self::assertStringContainsString('/account.js', $denied);
        self::assertStringContainsString('<account-menu>', $denied);
        self::assertStringNotContainsString('/chart.js', $denied);
        self::assertStringNotContainsString('<security-chart>', $denied);

        $this->actingAs($this->ada);
        $allowed = $this->get('/security-assets')->assertOk()->content();
        self::assertStringContainsString('/account.js', $allowed);
        self::assertStringContainsString('/chart.js', $allowed);
        self::assertStringContainsString('<security-chart>', $allowed);
    }

    public function testCredentialChangeInvalidatesTheNextRequestViewState(): void
    {
        $this->actingAs($this->ada);
        $this->get('/view-auth')->assertOk()->assertContains('<default-auth>Ada</default-auth>');
        $changed = password_hash('changed', PASSWORD_BCRYPT, ['cost' => 4]);
        self::assertTrue($this->ada->persistAttributeOnly('password', $changed));
        $after = $this->get('/view-auth')->assertOk()->content();
        self::assertStringContainsString('<default-auth>guest</default-auth>', $after);
        self::assertStringContainsString('<default-guest>yes</default-guest>', $after);
    }

    public function testPostFlashAppearsForOneRedirectedRequestOnly(): void
    {
        $this->get('/flash-state')->assertOk()->assertContains('<status>missing</status>');
        $this->withCsrfToken();
        $this->post('/flash-status')->assertRedirect('/flash-state');
        $this->get('/flash-state')->assertOk()->assertContains('<status>Saved</status>');
        $this->get('/flash-state')->assertOk()->assertContains('<status>missing</status>');
    }

    public function testIndependentApplicationsKeepAuthAndSessionStateSeparate(): void
    {
        $this->actingAs($this->ada);
        $this->session()->put('status', 'First application');
        $this->get('/view-auth')->assertOk()->assertContains('<default-auth>Ada</default-auth>');

        $other = TestApplication::temporary($this->testingConfig());
        try {
            $other->write('Project/Views/Phase14J/Other.squehub.php', <<<'VIEW'
@auth<identity>authenticated</identity>@else<identity>guest</identity>@endauth
@session('status')<status>{{ $value }}</status>@else<status>missing</status>@endsession
VIEW);
            $other->write('Project/Views/Phase14J/OtherAbility.squehub.php',
                "@can('reports.view')<report>wrong</report>@endcan");
            $other->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/other')->get(static function (): void {
    \App\Core\View::render('Phase14J.Other');
});
\App\Routing\Route::path('/other-ability')->get(static function (): void {
    \App\Core\View::render('Phase14J.OtherAbility');
});
PHP);
            $otherClient = new TestClient($other);
            $otherClient->get('/other')->assertOk()
                ->assertContains('<identity>guest</identity>')
                ->assertContains('<status>missing</status>');
            $other->application()->container()->make(\App\Session\SessionManager::class)
                ->store()->put('status', 'Second application');
            $otherClient->get('/other')->assertOk()
                ->assertContains('<identity>guest</identity>')
                ->assertContains('<status>Second application</status>');
            // A rule registered on the first Application is not available here.
            $otherClient->get('/other-ability')->assertStatus(500)
                ->assertNotContains('<report>wrong</report>');

            $this->get('/view-auth')->assertOk()->assertContains('<default-auth>Ada</default-auth>');
            $this->get('/flash-state')->assertOk()->assertContains('<status>First application</status>');
            $this->get('/orders/ada')->assertOk()->assertContains('<report>allowed</report>');
        } finally {
            $other->cleanup();
        }
    }

    public function testUnknownGuardAndBrokenAuthorizationRemainSafeProductionErrors(): void
    {
        $this->app()->config()->set('app.env', 'production');
        $this->app()->config()->set('app.debug', false);
        $this->actingAs($this->ada);
        foreach (['/bad-guard', '/bad-ability', '/bad-policy',
            '/bad-session', '/bad-syntax'] as $path) {
            $body = $this->get($path)->assertStatus(500)->content();
            self::assertStringContainsString('Internal Server Error', $body);
            self::assertStringNotContainsString('<denied>wrong</denied>', $body);
            foreach (['AUTH_SECRET_DO_NOT_EXPOSE', 'POLICY_SECRET_DO_NOT_EXPOSE',
                'SESSION_SECRET_DO_NOT_EXPOSE', 'TOKEN_SECRET_DO_NOT_EXPOSE',
                $this->testApplication()->root()] as $secret) {
                self::assertStringNotContainsString($secret, $body);
            }
        }
    }
}
