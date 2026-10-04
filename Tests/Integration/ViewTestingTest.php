<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Auth\Contracts\Authenticatable;
use App\Authorization\AuthorizationManager;
use App\Core\View;
use App\Database\DatabaseManager;
use App\Database\Model;
use App\Database\Schema\Table;
use App\Plugins\TestCase;
use App\Plugins\ViewContext;
use App\Plugins\ViewTestResult as PublicViewTestResult;
use App\Support\RuntimeContext;
use App\Testing\TestApplication;
use App\Testing\ViewTestResult;
use PDO;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;

/** Persisted identity for the real SessionGuard and View directive test. */
final class ViewTestingUser extends Model implements Authenticatable
{
    protected string $table = 'view_testing_users';
    protected array $fillable = ['name', 'email', 'password'];
    protected array $guarded = [];

    public function authIdentifier(): int|string { return (int) $this->getAttribute('id'); }
    public function authPasswordHash(): string { return (string) $this->getAttribute('password'); }
}

/** Exercises the public test helper against the normal View and Fragment paths. */
final class ViewTestingTest extends TestCase
{
    public function testFullViewRendersLayoutLoopIncludeComponentAndFinalizedStacks(): void
    {
        $testing = $this->testApplication();
        $testing->write('Project/Views/Layouts/Shell.squehub.php', <<<'VIEW'
<html><head>@stack('styles')@stack('head.meta')</head><body>@yield('content')@stack('scripts')</body></html>
VIEW);
        $testing->write('Project/Views/Partials/Row.squehub.php',
            '<li>{{ $number }}:{{ $item }}</li>');
        $testing->write('Project/Views/Components/Badge.squehub.php', <<<'VIEW'
@props(['title'])<article>{{ $title }}|{!! $slot->toHtml() !!}|{!! $slots->get('footer')->toHtml() !!}</article>@script('/badge.js')
VIEW);
        $testing->write('Project/Views/Pages/Index.squehub.php', <<<'VIEW'
@extends('Layouts.Shell')
@section('content')
<h1>{{ $heading }}</h1>
<ol>@foreach($items as $item)@include('Partials.Row', ['number' => $loop->iteration])@endforeach</ol>
@component('Badge', ['title' => '<Badge>'])<span>Body</span>@slot('footer')Footer@endslot@endcomponent
@style('/page.css', once: 'page-style')@style('/page.css', once: 'page-style')
@script('/page.js')
@if($enabled)@script('/enabled.js')@else@script('/disabled.js')@endif
@push('head.meta')<meta name="page">@endpush
@prepend('head.meta')<meta name="first">@endprepend
@endsection
VIEW);

        $this->app();
        View::assets()->for('Pages.Index')->style('/external.css');
        $result = $this->view('Pages.Index', [
            'heading' => '<Orders>', 'items' => ['First', 'Second'], 'enabled' => true,
        ]);
        self::assertInstanceOf(PublicViewTestResult::class, $result);
        self::assertSame($result, $result->assertSee('<html>')
            ->assertSeeEscaped('<Orders>')->assertDontSee('<Orders>')
            ->assertSeeInOrder(['<li>1:First</li>', '<li>2:Second</li>', 'Body'])
            ->assertSeeEscaped('<Badge>')
            ->assertStackContains('styles', '/external.css')
            ->assertStackContains('styles', '/page.css')
            ->assertStackContains('scripts', '/badge.js')
            ->assertStackContains('scripts', '/page.js')
            ->assertStackContains('scripts', '/enabled.js')
            ->assertStackMissing('scripts', '/disabled.js')
            ->assertStackContains('head.meta', '<meta name="page">')
            ->assertStackMissing('scripts', '/admin.js'));
        self::assertSame(1, substr_count($result->stack('scripts'), '/page.js'));
        self::assertSame(1, substr_count($result->stack('styles'), '/page.css'));
        self::assertTrue(strpos($result->stack('head.meta'), '<meta name="first">')
            < strpos($result->stack('head.meta'), '<meta name="page">'));
    }

    public function testFragmentHelperSkipsSiblingsAndSharesAssertionsAndAssetState(): void
    {
        $this->testApplication()->write('Project/Views/Pages/Fragments.squehub.php', <<<'VIEW'
@php $sibling(); @endphp
<div>SIBLING</div>
@fragment('orders.list')<ul><li>{{ $label }}</li></ul>@script('/fragment.js')@endfragment
@fragment('orders.other')<div>OTHER</div>@script('/other.js')@endfragment
VIEW);
        $calls = 0;
        $data = ['label' => '<First>', 'sibling' => static function () use (&$calls): void { ++$calls; }];

        $result = $this->fragment('Pages.Fragments', 'orders.list', $data);
        $result->assertSeeEscaped('<First>')->assertDontSee('SIBLING')
            ->assertDontSee('OTHER')->assertStackContains('scripts', '/fragment.js')
            ->assertStackMissing('scripts', '/other.js');
        self::assertSame(0, $calls);
        self::assertSame('', $result->stack('unknown'));

        $this->view('Pages.Fragments', $data)->assertSee('SIBLING')->assertSee('OTHER');
        self::assertSame(1, $calls);
    }

    public function testFormsUseActualSessionFlashCsrfAndMethodFields(): void
    {
        $this->testApplication()->configure(['csrf' => ['field' => 'form_csrf']]);
        $this->testApplication()->write('Project/Views/Pages/Form.squehub.php', <<<'VIEW'
<form method="post">@csrf @method('PATCH')
<input name="email" value="{{ old('email', '') }}">
@error('email')<small>{{ $message }}</small>@enderror
@session('notice')<b>{{ $value }}</b>@endsession
</form>
VIEW);
        $this->withErrors(['email' => ['<Invalid email>']]);
        $this->withOldInput(['email' => '<old@example.test>', 'password' => 'FILTER_ME']);
        $this->session()->put('notice', '<Saved>');

        $result = $this->view('Pages.Form');
        $result->assertHasCsrfField()->assertHasMethodField('patch')
            ->assertSeeEscaped('<Invalid email>')
            ->assertSeeEscaped('<old@example.test>')
            ->assertSeeEscaped('<Saved>')
            ->assertDontSee('FILTER_ME');
        self::assertStringContainsString('name="form_csrf"', $result->html());
        self::assertSame($result->html(), $this->view('Pages.Form')->html());

        $this->testApplication()->write('Project/Views/Pages/NoCsrf.squehub.php', '<p>No field</p>');
        $failed = false;
        try {
            $this->view('Pages.NoCsrf')->assertHasCsrfField();
        } catch (AssertionFailedError $failure) {
            $failed = true;
            self::assertStringContainsString('assertHasCsrfField', $failure->getMessage());
            self::assertStringNotContainsString((string) $this->session()->csrfToken(),
                $failure->getMessage());
        }
        self::assertTrue($failed, 'A View without the current CSRF control must fail its assertion.');

        $this->session()->close();
        $this->view('Pages.Form')->assertSeeEscaped('<Invalid email>');
        $this->session()->close();
        $this->view('Pages.Form')->assertDontSee('Invalid email')
            ->assertDontSee('old@example.test')->assertSeeEscaped('<Saved>');
    }

    public function testGuestAuthNamedGuardAuthorizationAndRequestProviderUseTheSameApplication(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for View Auth integration.');
        }
        $this->testApplication()->configure(['auth' => [
            'default' => 'web',
            'guards' => [
                'web' => ['driver' => 'session', 'identity' => 'users'],
                'admin' => ['driver' => 'session', 'identity' => 'users'],
            ],
            'identities' => ['users' => [
                'driver' => 'model', 'model' => ViewTestingUser::class,
                'identifier' => 'id', 'password' => 'password', 'credentials' => ['email'],
            ]],
        ]]);
        $this->testApplication()->write('Project/Views/Pages/Security.squehub.php', <<<'VIEW'
@auth<auth>{{ auth()->user()->getAttribute('name') }}</auth>@else<guest>yes</guest>@endauth
@auth('admin')<admin>yes</admin>@else<admin>no</admin>@endauth
@can('dashboard.view')<allowed>yes</allowed>@endcan
<path>{{ $requestPath }}</path>
VIEW);
        $app = $this->app();
        $app->views()->provide(static function (ViewContext $context): array {
            return ['requestPath' => $context->request()?->path() ?? 'missing'];
        });
        $app->container()->make(AuthorizationManager::class)->define('dashboard.view',
            static fn (ViewTestingUser $user): bool => $user->getAttribute('name') === 'Ada');
        $database = $app->container()->make(DatabaseManager::class);
        $database->schema()->create('view_testing_users', static function (Table $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password');
        });
        $user = ViewTestingUser::create([
            'name' => 'Ada', 'email' => 'ada@example.test',
            'password' => password_hash('fixture', PASSWORD_BCRYPT, ['cost' => 4]),
        ]);

        $this->view('Pages.Security')->assertSee('<guest>yes</guest>')
            ->assertSee('<admin>no</admin>')->assertSee('<path>/</path>');
        $this->actingAs($user);
        $this->view('Pages.Security')->assertSee('<auth>Ada</auth>')
            ->assertSee('<allowed>yes</allowed>')->assertSee('<admin>no</admin>');
        $this->guest();
        $this->actingAs($user, 'admin');
        $this->view('Pages.Security')->assertSee('<guest>yes</guest>')
            ->assertSee('<admin>yes</admin>');
    }

    public function testHelperReselectsItsApplicationAfterAnotherApplicationWasActive(): void
    {
        $this->testApplication()->write('Project/Views/Pages/Identity.squehub.php',
            '<p>{{ $brand }}</p>@script(\'/one.js\')');
        $this->app()->views()->share('brand', 'FIRST');
        $first = $this->view('Pages.Identity');
        $first->assertSee('FIRST')->assertStackContains('scripts', '/one.js');

        $other = TestApplication::temporary();
        try {
            $other->write('Project/Views/Pages/Identity.squehub.php',
                '<p>{{ $brand }}</p>@script(\'/two.js\')');
            $otherApp = $other->application();
            $otherApp->views()->share('brand', 'SECOND');
            RuntimeContext::select($otherApp);
            View::assets()->for('Pages.Identity')->script('/other-external.js');
            $alternate = View::renderResult('Pages.Identity');
            self::assertStringContainsString('SECOND', $alternate->html());
            self::assertStringContainsString('/two.js', $alternate->stack('scripts'));
            self::assertStringContainsString('/other-external.js', $alternate->stack('scripts'));

            $again = $this->view('Pages.Identity');
            $again->assertSee('FIRST')->assertDontSee('SECOND')
                ->assertStackContains('scripts', '/one.js')
                ->assertStackMissing('scripts', '/two.js')
                ->assertStackMissing('scripts', '/other-external.js');
        } finally {
            $other->cleanup();
        }
    }

    public function testFailedRenderDoesNotPoisonTheNextHelperRender(): void
    {
        $this->testApplication()->write('Project/Views/Pages/Explodes.squehub.php',
            "@push('head')<meta name=\"failed\">@endpush<?php throw new \\RuntimeException('failure'); ?>");
        $this->testApplication()->write('Project/Views/Pages/Healthy.squehub.php',
            "<p>healthy</p>@push('head')<meta name=\"healthy\">@endpush");
        try {
            $this->view('Pages.Explodes');
            self::fail('The template failure must propagate through the test helper.');
        } catch (RuntimeException) {
            $this->view('Pages.Healthy')->assertSee('<p>healthy</p>')
                ->assertStackContains('head', '<meta name="healthy">')
                ->assertStackMissing('head', 'failed');
        }
    }
}
