<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Core\View;
use App\Config\Repository;
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

/** Exercises the complete GET, invalid POST, redirected GET, expiry cycle. */
final class BrowserFormHttpTest extends TestCase
{
    private TemporaryProject $project;
    private Application $app;
    private Kernel $kernel;
    private SessionManager $sessions;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
        $this->project->write('Config/Session.php', '<?php return ["driver" => "array"];');
        $this->project->write('Config/Csrf.php', '<?php return ["field" => "form_guard", "enabled" => true, "header" => "X-CSRF-Token", "except" => []];');
        $this->app = new Application($this->project->path());
        foreach ([SessionServiceProvider::class, ValidationServiceProvider::class, CsrfServiceProvider::class,
            HttpServiceProvider::class, BrowserFormsServiceProvider::class, RoutingServiceProvider::class] as $provider) {
            $this->app->register($provider);
        }
        $this->app->bootstrap();
        $this->kernel = $this->app->container()->make(Kernel::class);
        $this->sessions = $this->app->container()->make(SessionManager::class);

        $this->project->write('Project/Views/Phase7D/Form.squehub.php',
            '<form method="POST" action="/submit">@csrf<input name="email" value="{{ old(\'email\', \'\') }}">'
            . '<p>{{ $errors->first(\'email\') }}</p></form>');
        View::initViewPaths();

        $routes = $this->app->container()->make(RouteRegistry::class);
        $routes->get('/form', static function (): void { View::render('Phase7D.Form'); });
        $routes->post('/submit', static function (Request $request): string {
            $request->validate(['email' => 'required|email']);
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

    public function testBrowserFormValidationRedirectFlashAndExpiry(): void
    {
        $first = $this->kernel->handle(new Request('GET', '/form'));
        self::assertSame(200, $first->status());
        self::assertStringContainsString('name="form_guard"', $first->content());
        $token = csrf_token();
        self::assertStringContainsString('value="' . $token . '"', $first->content());
        $this->sessions->store()->close();

        $failed = $this->kernel->handle(new Request('POST', '/submit', [], [
            'email' => '<img src=x onerror=alert(1)>', 'password' => 'secret',
            'form_guard' => $token, 'profile' => ['api_key' => 'private', 'name' => 'Ada'],
            'avatar' => ['tmp_name' => 'private-path', 'error' => UPLOAD_ERR_OK],
        ], [], ['avatar' => ['name' => 'private.png', 'tmp_name' => 'private-path', 'error' => UPLOAD_ERR_OK]]));
        self::assertSame(303, $failed->status());
        self::assertSame('/form', $failed->header('Location'));
        $this->sessions->store()->close();

        $returned = $this->kernel->handle(new Request('GET', '/form'));
        self::assertSame(200, $returned->status());
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $returned->content());
        self::assertStringNotContainsString('<img', $returned->content());
        self::assertStringContainsString('valid email address', $returned->content());
        self::assertSame('<img src=x onerror=alert(1)>', old('email'));
        self::assertNull(old('password'));
        self::assertNull(old('form_guard'));
        self::assertNull(old('avatar'));
        self::assertSame(['name' => 'Ada'], old('profile'));
        self::assertTrue(errors()->has('email'));
        self::assertSame(errors()->first('email'), errors()->first('email'));
        $this->sessions->store()->close();

        $expired = $this->kernel->handle(new Request('GET', '/form'));
        self::assertSame(200, $expired->status());
        self::assertFalse(errors()->any());
        self::assertSame([], old());
        self::assertStringNotContainsString('valid email address', $expired->content());
    }

    public function testJsonCsrfAndMissingDestinationKeepTheirExistingStatuses(): void
    {
        $token = csrf_token();
        $invalidCsrf = $this->kernel->handle(new Request('POST', '/submit', [], ['email' => 'bad']));
        self::assertSame(403, $invalidCsrf->status());
        self::assertFalse(errors()->any());
        self::assertSame([], old());

        $json = $this->kernel->handle(new Request('POST', '/submit', [], [], [], [],
            ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-CSRF-Token' => $token],
            [], '{"email":"bad"}'));
        self::assertSame(422, $json->status());
        self::assertSame('Validation failed.', json_decode($json->content(), true)['message']);
        self::assertFalse(errors()->any());

        $html = $this->kernel->handle(new Request('POST', '/submit', [], ['email' => 'bad', 'form_guard' => $token],
            [], [], ['Referer' => 'https://evil.example/form']));
        self::assertSame(422, $html->status());
        self::assertNull($html->header('Location'));
        self::assertFalse(errors()->any());
    }

    public function testValidatedSameOriginRefererSuppliesPathWithoutQuery(): void
    {
        $token = csrf_token();
        $request = new Request('POST', '/submit', [], ['email' => 'bad', 'form_guard' => $token],
            [], [], ['Referer' => 'https://app.example:8443/form?secret=hidden'],
            ['HTTP_HOST' => 'app.example:8443', 'HTTPS' => 'on']);
        $response = $this->kernel->handle($request);
        self::assertSame(303, $response->status());
        self::assertSame('/form', $response->header('Location'));
    }

    public function testFlashedCustomErrorMessageIsEscapedByDefault(): void
    {
        $this->sessions->store()->flash('_validation_errors', [
            'email' => ['<script>alert(1)</script>'],
        ]);
        $this->sessions->store()->close();
        $page = $this->kernel->handle(new Request('GET', '/form'));
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $page->content());
        self::assertStringNotContainsString('<script>', $page->content());

        // The helper resolves the active session each time; it must not retain
        // the previous visitor's bag in a static View cache.
        $otherSession = new SessionManager(new Repository(['session' => ['driver' => 'array']]));
        Session::setResolver(static fn (): SessionManager => $otherSession);
        self::assertFalse(errors()->any());
    }
}
