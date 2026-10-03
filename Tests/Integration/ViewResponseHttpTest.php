<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Auth\Contracts\Authenticatable;
use App\Authorization\AuthorizationManager;
use App\Database\DatabaseManager;
use App\Database\Model;
use App\Database\Schema\Table;
use App\Foundation\Application;
use App\Http\Request;
use App\Http\Response;
use App\Packages\PackageManager;
use App\Plugins\TestCase;
use App\Testing\TestApplication;
use App\Testing\TestClient;
use PDO;

/** Route middleware sees the same Response subtype as any other controller. */
final class ViewResponseHeaderMiddleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        return $next($request)->withHeader('X-View-Middleware', 'visited');
    }
}

/** Persisted fixture for request-scoped Auth and Authorization presentation. */
final class ViewResponseIdentity extends Model implements Authenticatable
{
    protected string $table = 'view_response_users';
    protected array $fillable = ['email', 'name', 'password'];
    protected array $hidden = ['password'];

    public function authIdentifier(): int|string { return (int) $this->getAttribute('id'); }
    public function authPasswordHash(): string { return (string) $this->getAttribute('password'); }
}

/** Returned Views traverse the real route, middleware and exception pipeline. */
final class ViewResponseHttpTest extends TestCase
{
    public static int $renders = 0;

    protected function setUp(): void
    {
        parent::setUp();
        self::$renders = 0;
        $this->testApplication()->write('Project/Views/Pages/HttpPage.squehub.php',
            '@php \\SqueHub\\Tests\\Integration\\ViewResponseHttpTest::$renders++; @endphp'
            . '<main>{{ $label }}</main>');
        $this->testApplication()->write('Project/Views/Pages/CompilerFailure.squehub.php',
            '@if($ready)');
        $this->testApplication()->write('Project/Views/Pages/RuntimeFailure.squehub.php',
            "PARTIAL<?php throw new \\RuntimeException('VIEW_RESPONSE_SECRET'); ?>");
        $this->testApplication()->write('Project/Views/Pages/HttpFailure.squehub.php',
            "<?php throw new \\App\\Http\\Exception\\HttpException(503, 'PRIVATE_HTTP_REASON'); ?>");
        $this->testApplication()->write('Project/Views/Pages/Form.squehub.php', <<<'VIEW'
@csrf
@guest<guest>yes</guest>@else<guest>no</guest>@endguest
@auth<auth>yes</auth>@else<auth>no</auth>@endauth
@can('reports.view')<can>yes</can>@else<can>no</can>@endcan
@session('notice')<notice>{{ $value }}</notice>@else<notice>none</notice>@endsession
<old>{{ old('email', 'none') }}</old>
@error('email')<error>{{ $message }}</error>@enderror
<script>@json($payload)</script><div class="{{ classes(['base', 'active' => true]) }}"></div>
VIEW);
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/view-response/page')->get(static fn (): \App\Http\Response =>
    \App\Plugins\View::response('Pages.HttpPage', ['label' => 'PAGE']))
    ->through(new \SqueHub\Tests\Integration\ViewResponseHeaderMiddleware());
\App\Routing\Route::path('/view-response/dynamic')->get(static function (\App\Http\Request $request): \App\Http\Response {
    $mode = $request->query('mode') === 'second' ? 'second' : 'first';
    return \App\Plugins\View::response('Pages.HttpPage', ['label' => $mode],
        status: $mode === 'second' ? 422 : 201, headers: ['X-Mode' => $mode]);
});
\App\Routing\Route::path('/view-response/intentional-404')->get(static fn (): \App\Http\Response =>
    \App\Plugins\View::response('Pages.HttpPage', ['label' => 'intentional'], status: 404));
\App\Routing\Route::path('/view-response/missing')->get(static fn (): \App\Http\Response =>
    \App\Plugins\View::response('Pages.Absent'));
\App\Routing\Route::path('/view-response/compiler')->get(static fn (): \App\Http\Response =>
    \App\Plugins\View::response('Pages.CompilerFailure'));
\App\Routing\Route::path('/view-response/runtime')->get(static fn (): \App\Http\Response =>
    \App\Plugins\View::response('Pages.RuntimeFailure'));
\App\Routing\Route::path('/view-response/http')->get(static fn (): \App\Http\Response =>
    \App\Plugins\View::response('Pages.HttpFailure'));
\App\Routing\Route::path('/view-response/form')->get(static fn (): \App\Http\Response =>
    \App\Plugins\View::response('Pages.Form', ['payload' => ['state' => 'ready']]));
PHP);
    }

    public function testKernelMiddlewareAndBodyContainReturnedViewExactlyOnce(): void
    {
        ob_start();
        try {
            $result = $this->get('/view-response/page');
            $priorOutput = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame('', $priorOutput);
        $result->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertHeader('X-View-Middleware', 'visited');
        self::assertSame('<main>PAGE</main>', $result->content());
        self::assertSame(1, substr_count($result->content(), 'PAGE'));
        self::assertSame(1, self::$renders);
    }

    public function testSameCompiledViewCanReturnDifferentStatusesAndHeadersAcrossRequests(): void
    {
        $first = $this->get('/view-response/dynamic?mode=first')->assertCreated()
            ->assertHeader('X-Mode', 'first');
        self::assertSame('<main>first</main>', $first->content());
        $second = $this->get('/view-response/dynamic?mode=second')->assertStatus(422)
            ->assertHeader('X-Mode', 'second');
        self::assertSame('<main>second</main>', $second->content());
        self::assertSame('first', $first->header('X-Mode'));
        self::assertSame(201, $first->status());
        self::assertSame(2, self::$renders);

        $intentional = $this->get('/view-response/intentional-404')->assertStatus(404);
        self::assertSame('<main>intentional</main>', $intentional->content());
        self::assertSame(3, self::$renders);
    }

    public function testDevelopmentErrorsKeepLogicalContextAndNeverLeakPartialBody(): void
    {
        $this->app()->config()->set('app.debug', true);
        foreach ([
            '/view-response/missing' => 'Pages.Absent',
            '/view-response/compiler' => 'Pages.CompilerFailure',
            '/view-response/runtime' => 'Pages.RuntimeFailure',
        ] as $path => $logical) {
            $body = $this->get($path)->assertStatus(500)->content();
            self::assertStringContainsString($logical, $body);
            self::assertStringNotContainsString('PARTIAL', $body);
            self::assertStringNotContainsString('VIEW_RESPONSE_SECRET', $body);
            self::assertStringNotContainsString($this->testApplication()->root(), $body);
            self::assertStringNotContainsString('Storage/Views', $body);
        }
        $this->get('/view-response/http')->assertStatus(503)
            ->assertNotContains('PRIVATE_HTTP_REASON');
    }

    public function testProductionErrorsHideNamesSourcesAndSecrets(): void
    {
        $this->app()->config()->set('app.env', 'production');
        $this->app()->config()->set('app.debug', false);
        foreach (['missing', 'compiler', 'runtime'] as $kind) {
            $body = $this->get('/view-response/' . $kind)->assertStatus(500)->content();
            foreach (['Pages.Absent', 'Pages.CompilerFailure', 'Pages.RuntimeFailure',
                'VIEW_RESPONSE_SECRET', 'PARTIAL', $this->testApplication()->root(),
                'Storage/Views'] as $private) {
                self::assertStringNotContainsString($private, $body);
            }
        }
        $this->get('/view-response/http')->assertStatus(503)
            ->assertNotContains('PRIVATE_HTTP_REASON');
    }

    public function testReturnedFormUsesCurrentAuthAuthorizationSessionValidationAndCsrfState(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for View response Auth integration.');
        }
        $this->testApplication()->configure(['auth' => [
            'default' => 'web',
            'guards' => ['web' => ['driver' => 'session', 'identity' => 'users']],
            'identities' => ['users' => [
                'driver' => 'model', 'model' => ViewResponseIdentity::class,
                'identifier' => 'id', 'password' => 'password', 'credentials' => ['email'],
            ]],
        ]]);
        $container = $this->app()->container();
        $container->make(DatabaseManager::class)->schema()->create('view_response_users',
            static function (Table $table): void {
                $table->id();
                $table->string('email');
                $table->string('name');
                $table->string('password');
            });
        $container->make(AuthorizationManager::class)->define('reports.view',
            static fn (ViewResponseIdentity $identity): bool =>
                $identity->getAttribute('name') === 'Ada');
        $user = ViewResponseIdentity::create([
            'email' => 'ada@example.test', 'name' => 'Ada',
            'password' => password_hash('fixture', PASSWORD_BCRYPT, ['cost' => 4]),
        ]);

        $this->session()->flash('notice', 'saved');
        $this->withOldInput(['email' => '<bad@example.test>']);
        $this->withErrors(['email' => ['Invalid address']]);
        $guest = $this->get('/view-response/form')->assertOk()->content();
        self::assertStringContainsString('<guest>yes</guest>', $guest);
        self::assertStringContainsString('<auth>no</auth>', $guest);
        self::assertStringContainsString('<can>no</can>', $guest);
        self::assertStringContainsString('<notice>saved</notice>', $guest);
        self::assertStringContainsString('<old>&lt;bad@example.test&gt;</old>', $guest);
        self::assertStringContainsString('<error>Invalid address</error>', $guest);
        self::assertStringContainsString('name="_csrf"', $guest);
        self::assertStringContainsString('"state":"ready"', $guest);
        self::assertStringContainsString('class="base active"', $guest);

        $this->actingAs($user);
        $authenticated = $this->get('/view-response/form')->assertOk()->content();
        self::assertStringContainsString('<guest>no</guest>', $authenticated);
        self::assertStringContainsString('<auth>yes</auth>', $authenticated);
        self::assertStringContainsString('<can>yes</can>', $authenticated);
        self::assertStringContainsString('<notice>none</notice>', $authenticated);
        self::assertStringContainsString('<old>none</old>', $authenticated);
        self::assertStringNotContainsString('<error>', $authenticated);
    }

    public function testHttpNamespacedResponseUsesOverrideButDisabledPackageCannotRender(): void
    {
        $package = 'Commerce' . bin2hex(random_bytes(4));
        $enabled = $this->testApplication();
        $this->writePackageResponseFixture($enabled, $package);
        $manager = new PackageManager(new Application($enabled->root()));
        self::assertTrue($manager->apply($manager->planEnable($package))->complete());

        $source = $this->get('/view-response/namespaced')->assertStatus(202)
            ->assertHeader('X-Package', $package);
        self::assertSame('<p>PACKAGE</p>', $source->content());
        $enabled->write('Project/PackagesViews/' . $package
            . '/Orders/Index.squehub.php', '<p>OVERRIDE</p>');
        $override = $this->get('/view-response/namespaced')->assertStatus(202);
        self::assertSame('<p>OVERRIDE</p>', $override->content());

        $disabled = TestApplication::temporary();
        try {
            $this->writePackageResponseFixture($disabled, $package);
            $disabled->write('Project/PackagesViews/' . $package
                . '/Orders/Index.squehub.php', '<p>DISABLED_OVERRIDE_SECRET</p>');
            $body = (new TestClient($disabled))->get('/view-response/namespaced')
                ->assertStatus(500)->content();
            self::assertStringNotContainsString('DISABLED_OVERRIDE_SECRET', $body);
            self::assertStringNotContainsString($package, $body);
        } finally {
            $disabled->cleanup();
        }
    }

    /** Install one route and source without enabling the Package implicitly. */
    private function writePackageResponseFixture(TestApplication $testing, string $package): void
    {
        $testing->write("Project/Packages/{$package}/{$package}.php",
            '<?php namespace Packages\\' . $package . '; final class ' . $package
            . ' extends \\App\\Plugins\\ServiceProvider {}');
        $testing->write('Project/Packages/' . $package
            . '/Views/Orders/Index.squehub.php', '<p>PACKAGE</p>');
        $routePath = $testing->path('Project/Routes/Web.php');
        $routes = is_file($routePath) ? file_get_contents($routePath) : false;
        $testing->write('Project/Routes/Web.php', (is_string($routes) ? $routes : '<?php')
            . "\n\\App\\Routing\\Route::path('/view-response/namespaced')"
            . "->get(static fn (): \\App\\Http\\Response => \\App\\Plugins\\View::response('"
            . $package . "::Orders.Index', status: 202, headers: ['X-Package' => '"
            . $package . "']));\n");
    }
}
