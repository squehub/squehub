<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Foundation\Application;
use App\Foundation\UrlBasePath;
use App\Http\BrowserFormsServiceProvider;
use App\Http\HttpServiceProvider;
use App\Http\RedirectResponse;
use App\Http\Request;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use App\Session\SessionServiceProvider;
use App\Support\RuntimeContext;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;
use Throwable;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** One inventory and bootstrap probe for the existing v2 global helper surface. */
final class GlobalHelpersTest extends TestCase
{
    private const AVAILABLE_V2_HELPERS = [
        'session', 'auth', 'authorize', 'accountSecurity', 'crypto',
        'health', 'rateLimiter', 'mailer', 'notifications', 'queue',
        'redis', 'lock', 'schedule', 'old', 'route', 'asset', 'csrf_token',
        'csrf_field', 'method_field', 'checked', 'selected', 'json',
        'classes', 'environment', 'debugging', 'errors', 'response',
        'database', 'db', 'schema', 'validator', 'logger', 'cache',
        'storage', 'events', 'diagnostics', 'httpClient', 'oauth',
        'webhooks', 'config', 'request', 'redirect', 'redirectBack',
    ];

    public function testAvailableV2HelpersComeFromTheCanonicalBootstrapFile(): void
    {
        $source = realpath(dirname(__DIR__, 2) . '/App/Core/Helper.php');
        self::assertIsString($source);
        require_once $source;
        foreach (self::AVAILABLE_V2_HELPERS as $name) {
            self::assertTrue(function_exists($name), $name);
            self::assertSame($source, (new ReflectionFunction($name))->getFileName(), $name);
        }
    }

    public function testCliApplicationAndRouteBootstrapsShareTheHelperDefinitions(): void
    {
        $root = dirname(__DIR__, 2);
        $code = '$squehubApp = require ' . var_export($root . '/Bootstrap/App.php', true) . '; '
            . 'require ' . var_export($root . '/Bootstrap/Routes.php', true) . '; '
            . 'require_once ' . var_export($root . '/App/Core/Helper.php', true) . '; '
            . 'echo (int) function_exists("asset") . "|" . '
            . 'basename((string) (new ReflectionFunction("asset"))->getFileName());';
        $process = new Process([PHP_BINARY, '-r', $code], $root);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('1|Helper.php', $process->getOutput());
    }

    public function testContextualHelpersSwitchBetweenBootedApplications(): void
    {
        $alphaProject = new TemporaryProject();
        $betaProject = new TemporaryProject();
        try {
            $alpha = $this->application($alphaProject, '/alpha', 'alpha', true);
            $beta = $this->application($betaProject, '/beta', 'beta', false);
            $alpha->container()->make(RouteRegistry::class)
                ->get('/dashboard', static fn (): string => 'alpha')->named('dashboard');
            $beta->container()->make(RouteRegistry::class)
                ->get('/dashboard', static fn (): string => 'beta')->named('dashboard');

            RuntimeContext::select($alpha);
            self::assertSame('/alpha/dashboard', \route('dashboard'));
            self::assertSame('/alpha/assets/app.css', \asset('/assets/app.css'));
            self::assertSame('alpha', \config('app.env'));
            self::assertSame('fallback', \config('missing.value', 'fallback'));
            self::assertTrue(\environment('alpha'));
            self::assertTrue(\debugging());

            RuntimeContext::select($beta);
            self::assertSame('/beta/dashboard', \route('dashboard'));
            self::assertSame('/beta/assets/app.css', \asset('/assets/app.css'));
            self::assertSame('beta', \config('app.env'));
            self::assertSame('other', \config('missing.value', 'other'));
            self::assertTrue(\environment('beta'));
            self::assertFalse(\debugging());

            RuntimeContext::select($alpha);
            self::assertSame('/alpha/dashboard', \route('dashboard'));
            self::assertSame('alpha', \config('app.env'));
            self::assertTrue(\environment('alpha'));
        } finally {
            $alphaProject->remove();
            $betaProject->remove();
        }
    }

    public function testRequestHelperTracksTheSelectedApplicationsActiveRequestOnly(): void
    {
        $alphaProject = new TemporaryProject();
        $betaProject = new TemporaryProject();
        try {
            $alpha = $this->application($alphaProject, '/alpha', 'alpha', false);
            $beta = $this->application($betaProject, '/beta', 'beta', false);
            $alphaRequest = new Request('GET', '/alpha/one');
            $betaRequest = new Request('POST', '/beta/two');

            RuntimeContext::select($alpha);
            self::assertThrows(LogicException::class, static fn (): Request => \request());
            $alpha->views()->beginRequest($alphaRequest);
            try {
                self::assertSame($alphaRequest, \request());
                RuntimeContext::select($beta);
                self::assertThrows(LogicException::class, static fn (): Request => \request());
                $beta->views()->beginRequest($betaRequest);
                try {
                    self::assertSame($betaRequest, \request());
                } finally {
                    $beta->views()->endRequest();
                }
                self::assertThrows(LogicException::class, static fn (): Request => \request());
                RuntimeContext::select($alpha);
                self::assertSame($alphaRequest, \request());
            } finally {
                $alpha->views()->endRequest();
            }
            self::assertThrows(LogicException::class, static fn (): Request => \request());
        } finally {
            $alphaProject->remove();
            $betaProject->remove();
        }
    }

    public function testRedirectUsesApplicationRootPathsAtRootSingleAndNestedMounts(): void
    {
        $projects = [];
        try {
            foreach (['' => '/login', '/app' => '/app/login',
                '/clients/acme' => '/clients/acme/login'] as $mount => $location) {
                $project = new TemporaryProject();
                $projects[] = $project;
                RuntimeContext::select($this->application($project, $mount, 'testing', false));
                $response = \redirect('/login');
                self::assertInstanceOf(RedirectResponse::class, $response);
                self::assertSame(302, $response->status());
                self::assertSame($location, $response->header('Location'));
                self::assertSame($location, \redirect('/login', 307)->header('Location'));
                self::assertSame(307, \redirect('/login', 307)->status());
            }
            RuntimeContext::select($this->application($projects[1], '/app', 'testing', false));
            self::assertSame('/app/app/foo', \redirect('/app/foo')->header('Location'));
            $legacy = \redirect();
            self::assertIsObject($legacy);
            self::assertTrue(method_exists($legacy, 'to'));
            self::assertTrue(method_exists($legacy, 'back'));

            foreach (['https://evil.example/login', '//evil.example/login',
                '/login/../admin', "/login\r\nLocation: /outside", '/login%2Fadmin',
                '/login\\admin'] as $invalid) {
                self::assertThrows(InvalidArgumentException::class,
                    static fn (): RedirectResponse => \redirect($invalid));
            }
        } finally {
            foreach ($projects as $project) {
                $project->remove();
            }
        }
    }

    public function testRedirectBackUsesSafeRefererOrValidatedFallback(): void
    {
        $project = new TemporaryProject();
        $withoutNavigation = new TemporaryProject();
        $rootProject = new TemporaryProject();
        $nestedProject = new TemporaryProject();
        try {
            $app = $this->application($project, '/squehub-v2', 'testing', false, true);
            RuntimeContext::select($app);
            $server = ['HTTP_HOST' => 'app.example.test', 'HTTPS' => 'on'];
            $fromForm = new Request('POST', '/squehub-v2/submit',
                headers: ['Referer' => 'https://app.example.test/squehub-v2/form?secret=hidden'],
                server: $server);
            self::applyMount($app, $fromForm);
            $sameOrigin = \redirectBack($fromForm, '/fallback');
            self::assertInstanceOf(RedirectResponse::class, $sameOrigin);
            self::assertSame(303, $sameOrigin->status());
            self::assertSame('/squehub-v2/form', $sameOrigin->header('Location'));

            foreach ([
                'https://evil.example.test/squehub-v2/form',
                'https://app.example.test/outside/form',
                "https://app.example.test/squehub-v2/form\r\nX-Injected: yes",
            ] as $referer) {
                $unsafe = new Request('POST', '/squehub-v2/submit',
                    headers: ['Referer' => $referer], server: $server);
                self::applyMount($app, $unsafe);
                self::assertSame('/squehub-v2/fallback',
                    \redirectBack($unsafe, '/fallback')->header('Location'));
            }
            $statusRequest = self::applyMount($app,
                new Request('POST', '/squehub-v2/submit'));
            self::assertSame('/squehub-v2/',
                \redirectBack($statusRequest)->header('Location'));
            self::assertSame(307,
                \redirectBack($statusRequest, '/fallback', 307)->status());
            foreach (['https://evil.example.test/', '//evil.example.test/',
                '/safe/../outside', "/safe\r\nLocation: /outside"] as $fallback) {
                self::assertThrows(InvalidArgumentException::class,
                    static fn (): RedirectResponse => \redirectBack(
                        $statusRequest, $fallback));
            }

            $fallbackApp = $this->application($withoutNavigation, '/alpha', 'testing', false);
            RuntimeContext::select($fallbackApp);
            self::assertThrows(LogicException::class,
                static fn (): RedirectResponse => \redirectBack($statusRequest, '/fallback'));
            $noProviderRequest = self::applyMount($fallbackApp,
                new Request('POST', '/alpha/submit',
                    headers: ['Referer' => 'https://app.example.test/alpha/form'],
                    server: $server));
            self::assertSame('/alpha/fallback', \redirectBack(
                $noProviderRequest, '/fallback')->header('Location'));
            self::assertSame('/alpha/alpha/form', \redirectBack(
                $noProviderRequest, '/alpha/form')->header('Location'));

            foreach ([
                [$rootProject, '', '/submit', '/fallback'],
                [$nestedProject, '/clients/acme', '/clients/acme/submit',
                    '/clients/acme/fallback'],
            ] as [$otherProject, $mount, $requestPath, $location]) {
                $otherApp = $this->application($otherProject, $mount, 'testing', false);
                RuntimeContext::select($otherApp);
                $otherRequest = self::applyMount($otherApp,
                    new Request('POST', $requestPath));
                self::assertSame($location, \redirectBack(
                    $otherRequest, '/fallback')->header('Location'));
            }
        } finally {
            $project->remove();
            $withoutNavigation->remove();
            $rootProject->remove();
            $nestedProject->remove();
        }
    }

    public function testLegacyMaskEmailRetainsOrdinaryOutputAndHandlesMissingDomain(): void
    {
        self::assertSame('al***@example.test', \maskEmail('alice@example.test'));
        self::assertSame('al***@', \maskEmail('alice'));
    }

    private function application(TemporaryProject $project, string $mount,
        string $environment, bool $debug, bool $browserNavigation = false): Application
    {
        $project->write('Config/App.php', '<?php return ' . var_export([
            'env' => $environment, 'debug' => $debug,
        ], true) . ';');
        $project->write('Config/Http.php',
            '<?php return [\'base_path\' => ' . var_export($mount, true) . '];');
        if ($browserNavigation) {
            $project->write('Config/Session.php', '<?php return [\'driver\' => \'array\'];');
        }
        $app = new Application($project->path());
        if ($browserNavigation) {
            $app->register(SessionServiceProvider::class);
            $app->register(HttpServiceProvider::class);
            $app->register(BrowserFormsServiceProvider::class);
        }
        $app->register(RoutingServiceProvider::class);
        $app->bootstrap();
        return $app;
    }

    private static function applyMount(Application $app, Request $request): Request
    {
        self::assertTrue($request->applyUrlBasePath(
            $app->container()->make(UrlBasePath::class)));
        return $request;
    }

    /** @param class-string<Throwable> $type */
    private static function assertThrows(string $type, callable $operation): void
    {
        try {
            $operation();
        } catch (Throwable $error) {
            self::assertInstanceOf($type, $error);
            return;
        }
        self::fail('Expected ' . $type . ' to be thrown.');
    }
}
