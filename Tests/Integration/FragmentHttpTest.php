<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Auth\Contracts\Authenticatable;
use App\Authorization\AuthorizationManager;
use App\Database\DatabaseManager;
use App\Database\Model;
use App\Database\Schema\Table;
use App\Plugins\TestCase;
use PDO;

/** A persisted identity proves fragment security reads the current HTTP request. */
final class FragmentHttpUser extends Model implements Authenticatable
{
    protected string $table = 'fragment_http_users';
    protected array $fillable = ['email', 'name', 'password'];
    protected array $hidden = ['password'];

    public function authIdentifier(): int|string { return (int) $this->getAttribute('id'); }
    public function authPasswordHash(): string { return (string) $this->getAttribute('password'); }
}

/** Exercises explicit fragment transport through the real HTTP Kernel. */
final class FragmentHttpTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    protected function testingConfig(): array
    {
        return ['auth' => [
            'default' => 'web',
            'guards' => ['web' => ['driver' => 'session', 'identity' => 'users']],
            'identities' => ['users' => [
                'driver' => 'model', 'model' => FragmentHttpUser::class,
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
        $testing = $this->testApplication();
        $testing->write('Project/Views/Layouts/FragmentApp.squehub.php',
            '<html><head>@stack(\'styles\')</head><body>LAYOUT_HEADER @yield(\'content\') LAYOUT_FOOTER</body></html>');
        $testing->write('Project/Views/Pages/FragmentPage.squehub.php', <<<'VIEW'
@extends('Layouts.FragmentApp')
@section('content')
<h1>PAGE_ONLY</h1>
@fragment('orders.list')
<ul><li>{{ $item }}</li></ul>
@style('/assets/fragment.css')
@endfragment
<aside>SIBLING_ONLY</aside>
@endsection
VIEW);
        $testing->write('Project/Views/Pages/FragmentForm.squehub.php', <<<'VIEW'
@fragment('edit.form')
<form method="POST" action="/fragments/form/submit">
@csrf
@method('PATCH')
<input name="email" value="{{ old('email', '') }}">
@error('email')<span class="field-error">{{ $message }}</span>@enderror
<input type="checkbox" name="accepted" value="1" {{ checked((bool) old('accepted', false)) }}>
</form>
@endfragment
VIEW);
        $testing->write('Project/Views/Pages/FragmentSecurity.squehub.php', <<<'VIEW'
@fragment('account.panel')
@guest<guest>visible</guest>@endguest
@auth
    @can('reports.view')
        @component('FragmentCard', ['name' => auth()->user()->name])@endcomponent
    @endcan
@endauth
@session('status')<status>{{ $value }}</status>@endsession
@endfragment
VIEW);
        $testing->write('Project/Views/Components/FragmentCard.squehub.php', <<<'VIEW'
@props(['name'])
@script('/assets/card.js')
<card>{{ $name }}</card>
VIEW);
        $testing->write('Project/Views/Pages/FragmentFailure.squehub.php', <<<'VIEW'
<?php throw new \RuntimeException('OUTSIDE_FRAGMENT_SECRET'); ?>
@fragment('safe')<p>SAFE</p>@endfragment
@fragment('broken')<p>FRAGMENT_SECRET</p><?php throw new \RuntimeException('AUTH_SECRET'); ?>@endfragment
VIEW);
        $testing->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/fragments/page')->get(static function (): void {
    \App\Plugins\View::render('Pages.FragmentPage', ['item' => '<Order>']);
});
\App\Routing\Route::path('/fragments/html')->get(static function (): \App\Http\Response {
    $result = \App\Plugins\View::fragment('Pages.FragmentPage', 'orders.list', ['item' => '<Order>']);
    return new \App\Http\Response($result->html(), 200,
        ['Content-Type' => 'text/html; charset=UTF-8']);
});
\App\Routing\Route::path('/fragments/json')->get(static function (): \App\Http\JsonResponse {
    $result = \App\Plugins\View::fragment('Pages.FragmentPage', 'orders.list', ['item' => '<Order>']);
    return new \App\Http\JsonResponse([
        'html' => $result->html(), 'styles' => $result->stack('styles'),
        'scripts' => $result->stack('scripts'),
    ]);
});
\App\Routing\Route::path('/fragments/form')->get(static function (): \App\Http\Response {
    return new \App\Http\Response(
        \App\Plugins\View::fragment('Pages.FragmentForm', 'edit.form')->html(),
        200, ['Content-Type' => 'text/html; charset=UTF-8']);
});
\App\Routing\Route::path('/fragments/form/submit')->patch(
    static function (\App\Http\Request $request): string {
        $request->validate(['email' => 'required|email']);
        return 'saved';
    });
\App\Routing\Route::path('/fragments/security')->get(static function (): \App\Http\JsonResponse {
    $result = \App\Plugins\View::fragment('Pages.FragmentSecurity', 'account.panel');
    return new \App\Http\JsonResponse([
        'html' => $result->html(), 'scripts' => $result->stack('scripts'),
    ]);
});
\App\Routing\Route::path('/fragments/safe')->get(static function (): \App\Http\Response {
    return new \App\Http\Response(
        \App\Plugins\View::fragment('Pages.FragmentFailure', 'safe')->html());
});
\App\Routing\Route::path('/fragments/broken')->get(static function (): \App\Http\Response {
    return new \App\Http\Response(
        \App\Plugins\View::fragment('Pages.FragmentFailure', 'broken')->html());
});
\App\Routing\Route::path('/fragments/missing')->get(static function (): \App\Http\Response {
    return new \App\Http\Response(
        \App\Plugins\View::fragment('Pages.FragmentFailure', 'unknown')->html());
});
PHP);
    }

    public function testFullHtmlAndJsonTransportsAreExplicitAndEscaped(): void
    {
        $full = $this->get('/fragments/page')->assertOk()->content();
        self::assertStringContainsString('LAYOUT_HEADER', $full);
        self::assertStringContainsString('<li>&lt;Order&gt;</li>', $full);
        self::assertStringContainsString('SIBLING_ONLY', $full);

        $html = $this->get('/fragments/html')->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')->content();
        self::assertStringContainsString('<li>&lt;Order&gt;</li>', $html);
        self::assertStringNotContainsString('LAYOUT_HEADER', $html);
        self::assertStringNotContainsString('PAGE_ONLY', $html);
        self::assertStringNotContainsString('SIBLING_ONLY', $html);
        $this->get('/fragments/json')->assertOk()->assertJson()
            ->assertJsonPath('html', $html)
            ->assertJsonPath('scripts', '');
        self::assertStringContainsString('/assets/fragment.css',
            (string) json_decode($this->get('/fragments/json')->content(), true)['styles']);

        // An AJAX-looking header must never silently select a fragment.
        $ajax = $this->get('/fragments/page', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->content();
        self::assertStringContainsString('LAYOUT_HEADER', $ajax);
        self::assertStringContainsString('SIBLING_ONLY', $ajax);
    }

    public function testFragmentFormRetainsCsrfValidationErrorsAndOldInput(): void
    {
        $first = $this->get('/fragments/form')->assertOk()->content();
        self::assertStringContainsString('name="_method" value="PATCH"', $first);
        self::assertStringContainsString('type="hidden"', $first);

        $this->post('/fragments/form/submit', ['_method' => 'PATCH', 'email' => 'bad'])
            ->assertStatus(403);
        $this->withCsrfToken();
        $this->post('/fragments/form/submit', [
            '_method' => 'PATCH', 'email' => '<img src=x onerror=alert(1)>',
            'accepted' => '1',
        ])->assertRedirect('/fragments/form');
        $returned = $this->get('/fragments/form')->assertOk()->content();
        self::assertStringContainsString('value="&lt;img src=x onerror=alert(1)&gt;"', $returned);
        self::assertStringNotContainsString('<img src=', $returned);
        self::assertStringContainsString('field-error', $returned);
        self::assertStringContainsString('name="accepted" value="1" checked', $returned);
    }

    public function testAuthAuthorizationSessionAndComponentAssetsUseCurrentRequest(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for fragment Auth integration.');
        }
        $this->app()->container()->make(DatabaseManager::class)->schema()->create(
            'fragment_http_users', static function (Table $table): void {
                $table->id();
                $table->string('email');
                $table->string('name');
                $table->string('password');
            });
        $this->app()->container()->make(AuthorizationManager::class)
            ->define('reports.view',
                static fn (FragmentHttpUser $user): bool => $user->getAttribute('name') === 'Ada');
        $guest = $this->get('/fragments/security')->assertOk()->assertJson();
        $guestHtml = json_decode($guest->content(), true, 512, JSON_THROW_ON_ERROR)['html'];
        self::assertStringContainsString('<guest>visible</guest>', $guestHtml);
        $guest->assertJsonPath('scripts', '');

        $ada = FragmentHttpUser::create([
            'email' => 'ada@example.test', 'name' => 'Ada',
            'password' => password_hash('fixture', PASSWORD_BCRYPT, ['cost' => 4]),
        ]);
        $this->actingAs($ada);
        $this->session()->flash('status', 'Saved');
        $allowed = $this->get('/fragments/security')->assertOk()->assertJson();
        $allowedHtml = json_decode($allowed->content(), true, 512, JSON_THROW_ON_ERROR)['html'];
        self::assertStringContainsString('<card>Ada</card>', $allowedHtml);
        self::assertStringContainsString('<status>Saved</status>', $allowedHtml);
        self::assertStringContainsString('/assets/card.js', $allowed->content());

        $bea = FragmentHttpUser::create([
            'email' => 'bea@example.test', 'name' => 'Bea',
            'password' => password_hash('fixture', PASSWORD_BCRYPT, ['cost' => 4]),
        ]);
        $this->actingAs($bea);
        $denied = $this->get('/fragments/security')->assertOk()->assertJson();
        $deniedHtml = json_decode($denied->content(), true, 512, JSON_THROW_ON_ERROR)['html'];
        self::assertStringNotContainsString('<card>', $deniedHtml);
        $denied->assertJsonPath('scripts', '');
    }

    public function testProductionFailureDiscardsPartialOutputAndNextRequestRemainsHealthy(): void
    {
        $this->app()->config()->set('app.env', 'production');
        $this->app()->config()->set('app.debug', false);
        self::assertSame('<p>SAFE</p>',
            $this->get('/fragments/safe')->assertOk()->content());

        foreach (['/fragments/broken', '/fragments/missing'] as $path) {
            $body = $this->get($path)->assertStatus(500)->content();
            self::assertStringContainsString('Internal Server Error', $body);
            foreach (['FRAGMENT_SECRET', 'AUTH_SECRET', 'OUTSIDE_FRAGMENT_SECRET',
                'Pages.FragmentFailure', 'Storage/Cache', $this->testApplication()->root()]
                as $private) {
                self::assertStringNotContainsString($private, $body);
            }
            self::assertSame('<p>SAFE</p>',
                $this->get('/fragments/safe')->assertOk()->content());
        }
    }
}
