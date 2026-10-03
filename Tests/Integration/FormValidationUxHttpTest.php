<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Core\View;
use App\Foundation\Application;
use App\Http\BrowserFormsServiceProvider;
use App\Http\HttpServiceProvider;
use App\Http\Kernel;
use App\Http\Request;
use App\Routing\Route;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use App\Security\Csrf\Csrf;
use App\Security\Csrf\CsrfServiceProvider;
use App\Session\Session;
use App\Session\SessionManager;
use App\Session\SessionServiceProvider;
use App\Validation\ValidationServiceProvider;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** Exercises the browser PATCH form through CSRF, validation, flash, and View. */
final class FormValidationUxHttpTest extends TestCase
{
    private TemporaryProject $project;
    private Application $app;
    private Kernel $kernel;
    private SessionManager $sessions;
    private int $patchHits = 0;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
        $this->project->write('Config/Session.php', '<?php return ["driver" => "array"];');
        $this->project->write('Config/Csrf.php', '<?php return ["field" => "form_guard", "enabled" => true, "header" => "X-CSRF-Token", "except" => []];');

        $this->app = new Application($this->project->path());
        foreach ([SessionServiceProvider::class, ValidationServiceProvider::class,
            CsrfServiceProvider::class, HttpServiceProvider::class,
            BrowserFormsServiceProvider::class, RoutingServiceProvider::class] as $provider) {
            $this->app->register($provider);
        }
        $this->app->bootstrap();
        $this->kernel = $this->app->container()->make(Kernel::class);
        $this->sessions = $this->app->container()->make(SessionManager::class);

        $this->project->write('Project/Views/Phase14I/Edit.squehub.php', <<<'VIEW'
<form method="POST" action="/profile">
    @csrf
    @method('PATCH')
    @include('Phase14I.Partials.Email', ['fallback' => 'before@example.test'])
    <input type="checkbox" name="newsletter" value="1" {{ checked((bool) old('newsletter', false)) }}>
    <select name="country">
        @component('CountryOptions', ['current' => old('country', 'ng')])@endcomponent
    </select>
    @if ($errors->any())
        <ul class="errors">
            @foreach ($errors->all() as $field => $messages)
                @foreach ($messages as $entry)<li>{{ $entry }}</li>@endforeach
            @endforeach
        </ul>
    @endif
    <button type="submit">Save</button>
</form>
VIEW);
        $this->project->write('Project/Views/Phase14I/Partials/Email.squehub.php', <<<'VIEW'
<input type="email" name="email" value="{{ old('email', $fallback) }}">
@error('email')<span class="field-error">{{ $message }}</span>@enderror
VIEW);
        $this->project->write('Project/Views/Components/CountryOptions.squehub.php', <<<'VIEW'
@props(['current'])
<option value="ng" {{ selected($current === 'ng') }}>Nigeria</option>
<option value="us" {{ selected($current === 'us') }}>United States</option>
VIEW);
        View::initViewPaths();

        $routes = $this->app->container()->make(RouteRegistry::class);
        $routes->get('/profile/edit', static function (): void {
            View::render('Phase14I.Edit');
        });
        $routes->patch('/profile', function (Request $request): string {
            ++$this->patchHits;
            $request->validate(['email' => 'required|email', 'country' => 'required']);
            return 'saved';
        });
    }

    protected function tearDown(): void
    {
        Csrf::setResolver(null);
        Session::setResolver(null);
        Route::setResolver(null);
        $this->project->remove();
    }

    public function testSpoofedPatchValidationRedirectAndFormStateAcrossRenders(): void
    {
        $first = $this->kernel->handle(new Request('GET', '/profile/edit'));
        self::assertSame(200, $first->status());
        self::assertStringContainsString('name="form_guard"', $first->content());
        self::assertStringContainsString('name="_method" value="PATCH"', $first->content());
        self::assertStringContainsString('value="before@example.test"', $first->content());
        self::assertStringNotContainsString('name="newsletter" value="1" checked', $first->content());
        self::assertStringContainsString('value="ng" selected', $first->content());
        self::assertStringNotContainsString('<ul class="errors">', $first->content());
        $token = \csrf_token();
        $this->sessions->store()->close();

        $failed = $this->kernel->handle(new Request('POST', '/profile', [], [
            '_method' => 'PATCH', 'form_guard' => $token,
            'email' => '<img src=x onerror=alert(1)>',
            'newsletter' => '1', 'country' => 'us', 'password' => 'FORM_SECRET',
        ], [], [], ['Content-Type' => 'application/x-www-form-urlencoded']));
        self::assertSame(303, $failed->status());
        self::assertSame('/profile/edit', $failed->header('Location'));
        self::assertSame(1, $this->patchHits);
        $this->sessions->store()->close();

        $returned = $this->kernel->handle(new Request('GET', '/profile/edit'));
        self::assertSame(200, $returned->status());
        self::assertStringContainsString('value="&lt;img src=x onerror=alert(1)&gt;"',
            $returned->content());
        self::assertStringNotContainsString('<img src=', $returned->content());
        self::assertStringContainsString('<span class="field-error">', $returned->content());
        self::assertStringContainsString('<ul class="errors">', $returned->content());
        self::assertStringContainsString('name="newsletter" value="1" checked', $returned->content());
        self::assertStringContainsString('value="us" selected', $returned->content());
        self::assertNull(\old('_method'));
        self::assertNull(\old('password'));
        self::assertTrue(\errors()->has('email'));
        $token = \csrf_token();
        $this->sessions->store()->close();

        $corrected = $this->kernel->handle(new Request('POST', '/profile', [], [
            '_method' => 'PATCH', 'form_guard' => $token,
            'email' => 'valid@example.test', 'country' => 'ng',
        ], [], [], ['Content-Type' => 'application/x-www-form-urlencoded']));
        self::assertSame(200, $corrected->status());
        self::assertSame('saved', $corrected->content());
        self::assertSame(2, $this->patchHits);

        $this->sessions->store()->close();
        $expired = $this->kernel->handle(new Request('GET', '/profile/edit'));
        self::assertSame(200, $expired->status());
        self::assertFalse(\errors()->any());
        self::assertSame([], \old());
        self::assertStringNotContainsString('<ul class="errors">', $expired->content());
    }
}
