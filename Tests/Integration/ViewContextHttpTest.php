<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Auth\Contracts\Authenticatable;
use App\Database\DatabaseManager;
use App\Database\Model;
use App\Database\Schema\Table;
use App\Diagnostics\Diagnostics;
use App\Http\Kernel;
use App\Http\Request;
use App\Plugins\TestCase;
use App\Plugins\ViewContext;
use App\Support\RuntimeContext;
use App\Testing\TestApplication;
use App\Testing\TestClient;
use PDO;

require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** Counts class-provider calls without sharing state between test Applications. */
final class ViewContextHttpProbe
{
    public int $calls = 0;
}

/** Verifies that a View provider class uses the normal Application container. */
final class ViewContextHttpProvider
{
    public function __construct(private ViewContextHttpProbe $probe)
    {
    }

    public function provide(ViewContext $context): array
    {
        return ['serial' => ++$this->probe->calls];
    }
}

/** Disposable session identity for the Auth isolation test. */
final class ViewContextHttpUser extends Model implements Authenticatable
{
    protected string $table = 'view_context_users';
    protected array $fillable = ['email', 'password'];
    protected array $guarded = [];

    public function authIdentifier(): int|string { return (int) $this->getAttribute('id'); }
    public function authPasswordHash(): string { return (string) $this->getAttribute('password'); }
}

/** Exercises View context through the real Testing API and Kernel. */
final class ViewContextHttpTest extends TestCase
{
    public function testOneApplicationGetsFreshProviderValuesForEachRequestAndNoRequestRender(): void
    {
        $this->testApplication()->write('Project/Views/Phase14A/Scope.squehub.php',
            '{{ $appName }}|{{ $path }}|{{ $serial }}');
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/first')->get(static function (): void {
    \App\Core\View::render('Phase14A.Scope');
});
\App\Routing\Route::path('/second')->get(static function (): void {
    \App\Core\View::render('Phase14A.Scope');
});
PHP);
        $views = $this->app()->views();
        $views->share('appName', 'Alpha');
        $calls = 0;
        $views->provide(static function (ViewContext $context) use (&$calls): array {
            return ['path' => $context->request()?->path() ?? 'none', 'serial' => ++$calls];
        });

        $this->get('/first')->assertOk()->assertContains('Alpha|/first|1');
        $this->get('/second')->assertOk()->assertContains('Alpha|/second|2');
        $this->get('/first')->assertOk()->assertContains('Alpha|/first|3');
        self::assertSame('Alpha|none|4', $this->render('Phase14A.Scope'));
    }

    public function testClassProviderIsLazyContainerResolvedAndRunsOncePerRequest(): void
    {
        $this->testApplication()->write('Project/Views/Phase14A/Serial.squehub.php', '{{ $serial }}');
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/plain')->get(static fn (): string => 'plain');
\App\Routing\Route::path('/twice')->get(static function (): void {
    \App\Core\View::render('Phase14A.Serial');
    echo '|';
    \App\Core\View::render('Phase14A.Serial');
});
PHP);
        $probe = new ViewContextHttpProbe();
        $this->app()->container()->instance(ViewContextHttpProbe::class, $probe);
        $this->app()->views()->provide(ViewContextHttpProvider::class);

        $this->get('/plain')->assertOk();
        self::assertSame(0, $probe->calls);
        $this->get('/twice')->assertOk()->assertContains('1|1');
        self::assertSame(1, $probe->calls);
        $this->get('/twice')->assertOk()->assertContains('2|2');
        self::assertSame(2, $probe->calls);

        $this->testApplication()->loadRoutes();
        $kernel = $this->app()->container()->make(Kernel::class);
        $request = new Request('GET', '/twice');
        self::assertSame('3|3', $kernel->handle($request)->content());
        self::assertSame('4|4', $kernel->handle($request)->content());
        self::assertSame(4, $probe->calls);
    }

    public function testExplicitControllerDataFlowsThroughLayoutAndNestedIncludesWithoutLeaking(): void
    {
        $this->testApplication()->write('Project/Views/Phase14A/Page.squehub.php',
            "@section('body')P={{ \$title }}:{{ \$post }};"
            . "@include('Phase14A.Card', ['title' => 'Overlay'])"
            . ";Q={{ \$title }}:{{ \$post }};@include('Phase14A.Card')"
            . "@endsection@extends('Phase14A.Layout')");
        $this->testApplication()->write('Project/Views/Phase14A/Layout.squehub.php',
            "L={{ \$title }}:{{ \$post }};@yield('body')");
        $this->testApplication()->write('Project/Views/Phase14A/Card.squehub.php',
            "C={{ \$title }}:{{ \$post }};@include('Phase14A.Nested')");
        $this->testApplication()->write('Project/Views/Phase14A/Nested.squehub.php',
            "N={{ \$title }}:{{ \$post }}");
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/context/many')->get(static function (): void {
    \App\Core\View::render('Phase14A.Page', ['title' => 'First', 'post' => 'One']);
    echo '||';
    \App\Core\View::render('Phase14A.Page', ['title' => 'Second', 'post' => 'Two']);
});
\App\Routing\Route::path('/context/single')->get(static function (): void {
    \App\Core\View::render('Phase14A.Page', ['title' => 'Third', 'post' => 'Three']);
});
PHP);
        $this->app()->views()->share('title', 'Shared');
        $this->app()->views()->share('post', 'Default');
        $providerCalls = 0;
        $this->app()->views()->provide(static function (ViewContext $context) use (&$providerCalls): array {
            ++$providerCalls;
            return ['renderTreeMarker' => 'request'];
        });

        $many = $this->get('/context/many')->assertOk()->content();
        $parts = explode('||', $many);
        self::assertCount(2, $parts);
        self::assertSame($this->expectedContextPage('First', 'One'), $parts[0]);
        self::assertSame($this->expectedContextPage('Second', 'Two'), $parts[1]);
        self::assertSame(1, $providerCalls, 'Includes and layouts reuse the request provider result.');
        self::assertSame($this->expectedContextPage('Third', 'Three'),
            $this->get('/context/single')->assertOk()->content());
        self::assertSame(2, $providerCalls);
    }

    public function testInterleavedApplicationsKeepSharedAndRequestContextSeparate(): void
    {
        $first = $this->testApplication();
        $second = TestApplication::temporary();
        try {
            $this->writeMarkerFixture($first);
            $this->writeMarkerFixture($second);
            $appA = $first->application();
            $appA->views()->share('appName', 'Alpha');
            $appA->views()->provide(static fn (ViewContext $context): array => [
                'path' => $context->request()?->path() ?? 'none',
            ]);
            $appB = $second->application();
            $appB->views()->share('appName', 'Beta');
            $appB->views()->provide(static fn (ViewContext $context): array => [
                'path' => $context->request()?->path() ?? 'none',
            ]);
            $clientA = new TestClient($first);
            $clientB = new TestClient($second);

            $clientA->get('/marker')->assertOk()->assertContains('Alpha|/marker');
            $clientB->get('/marker')->assertOk()->assertContains('Beta|/marker');
            $clientA->get('/marker')->assertOk()->assertContains('Alpha|/marker');
            RuntimeContext::select($appB);
            self::assertSame('Beta|none', $this->render('Phase14A.Marker'));
            RuntimeContext::select($appA);
            self::assertSame('Alpha|none', $this->render('Phase14A.Marker'));
        } finally {
            $second->cleanup();
        }
    }

    public function testCsrfProviderUsesEachApplicationsSessionAndNeverWritesTokensToCompiledViews(): void
    {
        $first = $this->testApplication();
        $second = TestApplication::temporary();
        try {
            $this->writeTokenFixture($first);
            $this->writeTokenFixture($second);
            $appA = $first->application();
            $appA->views()->provide(static fn (ViewContext $context): array => [
                'csrf' => \csrf_token(),
            ]);
            $appB = $second->application();
            $appB->views()->provide(static fn (ViewContext $context): array => [
                'csrf' => \csrf_token(),
            ]);
            $clientA = new TestClient($first);
            $clientB = new TestClient($second);

            $bodyA = $clientA->get('/token')->assertOk()->content();
            $tokenA = $this->tokenFrom($bodyA);
            $bodyB = $clientB->get('/token')->assertOk()->content();
            $tokenB = $this->tokenFrom($bodyB);
            $againA = $this->tokenFrom($clientA->get('/token')->assertOk()->content());
            self::assertFalse(hash_equals($tokenA, $tokenB));
            self::assertTrue(hash_equals($tokenA, $againA));
            self::assertTrue(str_contains($bodyA, 'value="' . $tokenA . '"'));
            self::assertTrue(str_contains($bodyB, 'value="' . $tokenB . '"'));
            self::assertFalse(str_contains($bodyB, $tokenA));

            foreach ([$appA, $appB] as $app) {
                $diagnostics = json_encode($app->container()->make(Diagnostics::class)->snapshot(),
                    JSON_THROW_ON_ERROR);
                self::assertFalse(str_contains($diagnostics, $tokenA));
                self::assertFalse(str_contains($diagnostics, $tokenB));
            }
            $firstCache = glob($first->path('Storage/Views/*.php'));
            $secondCache = glob($second->path('Storage/Views/*.php'));
            self::assertIsArray($firstCache);
            self::assertIsArray($secondCache);
            self::assertNotEmpty($firstCache);
            self::assertNotEmpty($secondCache);
            $cacheFiles = array_merge($firstCache, $secondCache);
            $sourceContainsTokenA = false;
            $sourceContainsTokenB = false;
            foreach ($cacheFiles as $file) {
                $source = file_get_contents($file);
                if (!is_string($source)) {
                    throw new \RuntimeException('Compiled View source could not be read.');
                }
                $sourceContainsTokenA = $sourceContainsTokenA || str_contains($source, $tokenA);
                $sourceContainsTokenB = $sourceContainsTokenB || str_contains($source, $tokenB);
            }
            self::assertFalse($sourceContainsTokenA);
            self::assertFalse($sourceContainsTokenB);
        } finally {
            $second->cleanup();
        }
    }

    public function testProviderFailureEndsScopeBeforeTheNextRequest(): void
    {
        $this->testApplication()->write('Project/Views/Phase14A/Scope.squehub.php', '{{ $path }}');
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/broken')->get(static function (): void {
    \App\Core\View::render('Phase14A.Scope');
});
\App\Routing\Route::path('/healthy')->get(static function (): void {
    \App\Core\View::render('Phase14A.Scope');
});
PHP);
        $this->app()->views()->provide(static function (ViewContext $context): array {
            $path = $context->request()?->path() ?? 'none';
            if ($path === '/broken') throw new \RuntimeException('PRIVATE_VIEW_PROVIDER_MARKER');
            return ['path' => $path];
        });

        $this->get('/broken')->assertStatus(500)->assertNotContains('PRIVATE_VIEW_PROVIDER_MARKER');
        self::assertSame('none', $this->render('Phase14A.Scope'));
        $this->get('/absent')->assertStatus(404);
        self::assertSame('none', $this->render('Phase14A.Scope'));
        $this->post('/healthy')->assertStatus(403);
        self::assertSame('none', $this->render('Phase14A.Scope'));
        $this->get('/healthy')->assertOk()->assertContains('/healthy');
    }

    public function testSessionFlashValidationErrorsOldInputAndCsrfKeepTheirRequestLifetimes(): void
    {
        $this->testApplication()->write('Project/Views/Phase14A/Security.squehub.php',
            '{{ $notice }}|{{ $hasErrors }}|{{ $oldEmail }}|@csrf');
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/security')->get(static function (): void {
    \App\Core\View::render('Phase14A.Security');
});
\App\Routing\Route::path('/flash')->post(static function (): string {
    \session()->flash('notice', 'saved');
    return 'saved';
});
\App\Routing\Route::path('/submit')->post(static function (\App\Http\Request $request): string {
    $request->validate(['email' => 'required|email']);
    return 'submitted';
});
PHP);
        $this->app()->views()->provide(static fn (ViewContext $context): array => [
            'notice' => \session()->get('notice', 'none'),
            'hasErrors' => \errors()->any() ? 'yes' : 'no',
            'oldEmail' => \old('email', 'none'),
        ]);

        $this->get('/security')->assertOk()->assertContains('none|no|none|');
        $token = \csrf_token();
        $this->withCsrfToken();
        $this->post('/flash')->assertOk();
        $this->get('/security')->assertOk()->assertContains('saved|no|none|');
        $this->get('/security')->assertOk()->assertContains('none|no|none|');

        $this->post('/submit', ['email' => 'invalid', 'password' => 'PRIVATE_PASSWORD_MARKER'])
            ->assertRedirect('/security');
        $this->get('/security')->assertOk()->assertContains('none|yes|invalid|')
            ->assertNotContains('PRIVATE_PASSWORD_MARKER');
        self::assertNull(\old('password'));
        $this->get('/security')->assertOk()->assertContains('none|no|none|');
        self::assertTrue(hash_equals($token, \csrf_token()));
    }

    public function testAuthIdentityDoesNotRemainInViewProviderAfterLogout(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for Auth identity integration.');
        }
        $this->testApplication()->configure(['auth' => [
            'default' => 'web',
            'guards' => ['web' => ['driver' => 'session', 'identity' => 'users']],
            'identities' => ['users' => [
                'driver' => 'model', 'model' => ViewContextHttpUser::class,
                'identifier' => 'id', 'password' => 'password', 'credentials' => ['email'],
            ]],
        ]]);
        $this->testApplication()->write('Project/Views/Phase14A/Identity.squehub.php',
            '{{ $viewer }}');
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/identity')->get(static function (): void {
    \App\Core\View::render('Phase14A.Identity');
});
PHP);
        $database = $this->app()->container()->make(DatabaseManager::class);
        $database->schema()->create('view_context_users', static function (Table $table): void {
            $table->id();
            $table->string('email');
            $table->string('password');
        });
        $user = ViewContextHttpUser::create([
            'email' => 'ada@example.test',
            'password' => password_hash('fixture', PASSWORD_BCRYPT, ['cost' => 4]),
        ]);
        $this->app()->views()->provide(static fn (ViewContext $context): array => [
            'viewer' => (string) (\auth()->id() ?? 'guest'),
        ]);

        $this->get('/identity')->assertOk()->assertContains('guest');
        $this->actingAs($user);
        $this->get('/identity')->assertOk()->assertContains((string) $user->authIdentifier());
        $this->guest();
        $this->get('/identity')->assertOk()->assertContains('guest');
    }

    private function writeMarkerFixture(TestApplication $testing): void
    {
        $testing->write('Project/Views/Phase14A/Marker.squehub.php', '{{ $appName }}|{{ $path }}');
        $testing->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/marker')->get(static function (): void {
    \App\Core\View::render('Phase14A.Marker');
});
PHP);
    }

    private function writeTokenFixture(TestApplication $testing): void
    {
        $testing->write('Project/Views/Phase14A/Token.squehub.php', '{{ $csrf }}|@csrf');
        $testing->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/token')->get(static function (): void {
    \App\Core\View::render('Phase14A.Token');
});
PHP);
    }

    private function tokenFrom(string $content): string
    {
        $token = explode('|', $content, 2)[0];
        self::assertTrue(preg_match('/\A[0-9a-f]{64}\z/D', $token) === 1,
            'Expected a session-bound CSRF token in the rendered view.');
        return $token;
    }

    private function expectedContextPage(string $title, string $post): string
    {
        return "L={$title}:{$post};P={$title}:{$post};C=Overlay:{$post};N=Overlay:{$post}"
            . ";Q={$title}:{$post};C={$title}:{$post};N={$title}:{$post}";
    }

    private function render(string $view): string
    {
        ob_start();
        try {
            \App\Core\View::render($view);
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
