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

/** Verifies mounted form actions, CSRF, validation flash, and safe redirect-back. */
final class BasePathBrowserTest extends TestCase
{
    private TemporaryProject $project;
    private Application $app;
    private Kernel $kernel;
    private SessionManager $sessions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = new TemporaryProject();
        $this->project->write('Config/App.php', '<?php return ["env" => "testing", "debug" => false];');
        $this->project->write('Config/Http.php', '<?php return ["base_path" => "/app"];');
        $this->project->write('Config/Session.php', '<?php return ["driver" => "array"];');
        $this->project->write('Config/Csrf.php',
            '<?php return ["enabled" => true, "field" => "form_guard", "header" => "X-CSRF-Token", "except" => []];');
        $this->app = new Application($this->project->path());
        foreach ([SessionServiceProvider::class, ValidationServiceProvider::class,
            CsrfServiceProvider::class, HttpServiceProvider::class,
            BrowserFormsServiceProvider::class, RoutingServiceProvider::class] as $provider) {
            $this->app->register($provider);
        }
        $this->app->bootstrap();
        $this->kernel = $this->app->container()->make(Kernel::class);
        $this->sessions = $this->app->container()->make(SessionManager::class);

        $this->project->write('Project/Views/Mounted/Form.squehub.php',
            '<form method="POST" action="{{ route(\'form.submit\') }}">@csrf'
            . '<input name="email" value="{{ old(\'email\', \'\') }}">'
            . '<p>{{ $errors->first(\'email\') }}</p></form>');
        View::initViewPaths();
        $routes = $this->app->container()->make(RouteRegistry::class);
        $routes->get('/form', static function (): void {
            View::render('Mounted.Form');
        });
        $routes->post('/submit', static function (Request $request): string {
            $request->validate(['email' => 'required|email']);
            return 'saved';
        })->named('form.submit');
    }

    protected function tearDown(): void
    {
        try {
            Csrf::setResolver(null);
            Session::setResolver(null);
            Route::setResolver(null);
            $this->project->remove();
        } finally {
            parent::tearDown();
        }
    }

    public function testMountedFormValidationReturnsToMountedPageWithFlashState(): void
    {
        $form = $this->kernel->handle(new Request('GET', '/app/form'));
        self::assertSame(200, $form->status());
        self::assertStringContainsString('action="/app/submit"', $form->content());
        self::assertStringContainsString('name="form_guard"', $form->content());
        $token = \csrf_token();
        $this->sessions->store()->close();

        $invalid = $this->kernel->handle(new Request('POST', '/app/submit', form: [
            'email' => 'bad', 'form_guard' => $token,
        ]));
        self::assertSame(303, $invalid->status());
        self::assertSame('/app/form', $invalid->header('Location'));
        $this->sessions->store()->close();

        $returned = $this->kernel->handle(new Request('GET', '/app/form'));
        self::assertSame(200, $returned->status());
        self::assertStringContainsString('value="bad"', $returned->content());
        self::assertStringContainsString('valid email address', $returned->content());
        self::assertSame('bad', \old('email'));
        self::assertTrue(\errors()->has('email'));
    }

    public function testMountedSameOriginRefererIsAcceptedWithoutPreviousPage(): void
    {
        $token = \csrf_token();
        $request = new Request('POST', '/app/submit', form: [
            'email' => 'bad', 'form_guard' => $token,
        ], headers: ['Referer' => 'https://app.example.test/app/form?secret=hidden'],
            server: ['HTTP_HOST' => 'app.example.test', 'HTTPS' => 'on']);
        $redirect = $this->kernel->handle($request);
        self::assertSame(303, $redirect->status());
        self::assertSame('/app/form', $redirect->header('Location'));
        self::assertStringNotContainsString('secret=hidden', (string) $redirect->header('Location'));
    }

    public function testSameOriginRefererOutsideMountCannotBecomeRedirectDestination(): void
    {
        $token = \csrf_token();
        $request = new Request('POST', '/app/submit', form: [
            'email' => 'bad', 'form_guard' => $token,
        ], headers: ['Referer' => 'https://app.example.test/outside/form'],
            server: ['HTTP_HOST' => 'app.example.test', 'HTTPS' => 'on']);
        $response = $this->kernel->handle($request);
        self::assertSame(422, $response->status());
        self::assertNull($response->header('Location'));
    }

    public function testInvalidCsrfRemainsForbiddenBeforeValidation(): void
    {
        $response = $this->kernel->handle(new Request('POST', '/app/submit', form: ['email' => 'bad']));
        self::assertSame(403, $response->status());
        self::assertNull($response->header('Location'));
        self::assertFalse(\errors()->any());
    }
}
