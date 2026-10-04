<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Components\Notification as ComponentNotification;
use App\Config\Repository;
use App\Core\Notification as CoreNotification;
use App\Foundation\Application;
use App\Http\HttpServiceProvider;
use App\Http\JsonResponse;
use App\Http\Kernel;
use App\Http\RedirectResponse;
use App\Http\Request;
use App\Routing\Route;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use App\Session\Session;
use App\Session\SessionException;
use App\Session\SessionManager;
use App\Session\SessionServiceProvider;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

final class SessionIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Session::setResolver(null);
        Route::setResolver(null);
    }

    public function testApplicationHelperAndBothLegacyNotificationsShareOneStore(): void
    {
        $manager = new SessionManager(new Repository(['session' => ['driver' => 'array']]));
        Session::setResolver(static fn (): SessionManager => $manager);
        $store = session();
        self::assertSame($store, $manager->store());
        $store->put('theme', 'dark');
        CoreNotification::flash('success', 'saved');
        ComponentNotification::set('warning', 'review');
        flash('notice', 'legacy');
        $store->flashInput(['email' => 'v@example.com']);
        self::assertSame('saved', $store->get('notification_success'));
        self::assertSame(['warning' => 'review'], $store->get('_notification'));
        self::assertSame(['notice' => 'legacy'], $store->get('_flash'));

        $store->close();
        self::assertSame('v@example.com', old('email'));
        self::assertSame('saved', CoreNotification::get('success'));
        self::assertSame('review', ComponentNotification::get('warning'));
        self::assertSame('legacy', flash('notice'));
        self::assertSame('dark', $store->get('theme'));
        $store->close();
        self::assertNull(CoreNotification::get('success'));
        self::assertNull(ComponentNotification::get('warning'));
        self::assertNull(flash('notice'));
        self::assertSame([], old());
        self::assertSame('dark', $store->get('theme'));
    }

    public function testLegacyNotificationMapDoesNotExtendSiblingLifetime(): void
    {
        $manager = new SessionManager(new Repository(['session' => ['driver' => 'array']]));
        Session::setResolver(static fn (): SessionManager => $manager);
        ComponentNotification::set('success', 'yes');
        ComponentNotification::set('error', 'no');
        session()->close();
        self::assertSame('yes', ComponentNotification::get('success'));
        session()->close();
        self::assertNull(ComponentNotification::get('error'));
    }

    public function testCoreNotificationAllAndLegacyFlashMapRetainTheirPublicContracts(): void
    {
        $manager = new SessionManager(new Repository(['session' => ['driver' => 'array']]));
        Session::setResolver(static fn (): SessionManager => $manager);
        CoreNotification::flash('success', 'saved');
        CoreNotification::flash('warning', 'review');
        flash('success', 'legacy saved');
        flash('warning', 'legacy review');
        session()->close();
        self::assertSame(['success' => 'saved', 'warning' => 'review'], CoreNotification::all());
        self::assertSame('legacy saved', flash('success'));
        session()->close();
        self::assertNull(CoreNotification::get('success'));
        self::assertNull(flash('warning'));
    }

    public function testInvalidConfigurationFailsWithoutStartingSession(): void
    {
        foreach ([
            ['driver' => 'unknown'],
            ['driver' => 'native', 'name' => 'bad name'],
            ['driver' => 'native', 'same_site' => 'Unknown'],
            ['driver' => 'native', 'same_site' => 'None', 'secure' => false],
        ] as $settings) {
            try {
                (new SessionManager(new Repository(['session' => $settings])))->store();
                self::fail('Invalid session configuration was accepted.');
            } catch (SessionException) {
                self::assertSame(PHP_SESSION_NONE, session_status());
            }
        }
    }

    public function testNativeDriverUsesIsolatedStorageAndSecureConfiguration(): void
    {
        $project = new TemporaryProject();
        $directory = $project->path('sessions');
        mkdir($directory);
        $root = dirname(__DIR__, 2);
        $code = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; '
            . 'session_save_path(' . var_export($directory, true) . '); '
            . '$config = new \\App\\Config\\Repository(["session" => ["driver" => "native", "name" => "squehub_test", "lifetime" => 5, "path" => "/app", "domain" => "example.test", "secure" => true, "http_only" => true, "same_site" => "Strict", "strict_mode" => true]]); '
            . '$store = (new \\App\\Session\\SessionManager($config))->store(); '
            . 'session_id("predictable_id"); $store->start(); $first = $store->id(); '
            . '$store->put("theme", "dark"); $store->flash("status", "saved"); $store->flash("notification_success", "legacy"); $store->flashInput(["email" => "v@example.com", "password" => "secret"]); '
            . '$legacyVisible = $_SESSION["notification_success"] ?? null; '
            . '$cookie = session_get_cookie_params(); $strict = ini_get("session.use_strict_mode"); '
            . '$store->regenerate(); $second = $store->id(); $store->close(); '
            . '$next = new \\App\\Session\\SessionStore(new \\App\\Session\\Drivers\\NativeSessionDriver(["name" => "squehub_test", "lifetime" => 5, "path" => "/app", "domain" => "example.test", "secure" => true, "http_only" => true, "same_site" => "Strict", "strict_mode" => true])); '
            . '$next->start(); $available = [$next->get("theme"), $next->get("status"), $next->old("email"), $next->old("password")]; $next->close(); '
            . '$next->start(); $expired = [$next->has("status"), $next->old()]; $next->invalidate(); $empty = $next->all(); $third = $next->id(); $next->close(); '
            . 'echo json_encode(compact("first", "second", "third", "cookie", "strict", "available", "expired", "empty", "legacyVisible"));';
        try {
            $process = new Process([PHP_BINARY, '-r', $code], $root);
            $process->run();
            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            self::assertNotSame('predictable_id', $result['first']);
            self::assertNotSame($result['first'], $result['second']);
            self::assertNotSame($result['second'], $result['third']);
            self::assertSame(["dark", "saved", "v@example.com", null], $result['available']);
            self::assertSame([false, []], $result['expired']);
            self::assertSame('legacy', $result['legacyVisible']);
            self::assertSame([], $result['empty']);
            self::assertSame('1', $result['strict']);
            self::assertSame(300, $result['cookie']['lifetime']);
            self::assertSame('/app', $result['cookie']['path']);
            self::assertSame('example.test', $result['cookie']['domain']);
            self::assertTrue($result['cookie']['secure']);
            self::assertTrue($result['cookie']['httponly']);
            self::assertSame('Strict', $result['cookie']['samesite']);
        } finally {
            $project->remove();
        }
    }

    public function testCliApplicationBootstrapRegistersSessionWithoutOpeningIt(): void
    {
        $root = dirname(__DIR__, 2);
        $code = '$app = require ' . var_export($root . '/Bootstrap/App.php', true) . '; '
            . 'echo session_status() . "|" . $app->config()->get("session.driver") . "|" '
            . '. (int) ($app->container()->make(\\App\\Session\\SessionManager::class) === \\App\\Session\\Session::manager());';
        $process = new Process([PHP_BINARY, '-r', $code], $root, ['SESSION_DRIVER' => 'native']);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame(PHP_SESSION_NONE . '|native|1', $process->getOutput());
    }

    public function testNativeStartAfterOutputFailsClearly(): void
    {
        $root = dirname(__DIR__, 2);
        $code = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; '
            . 'echo "body|"; '
            . 'try { (new \\App\\Session\\SessionManager(new \\App\\Config\\Repository(["session" => ["driver" => "native"]])))->store()->start(); echo "started"; } '
            . 'catch (\\App\\Session\\SessionException $exception) { echo "blocked"; }';
        $process = new Process([PHP_BINARY, '-r', $code], $root);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('body|blocked', $process->getOutput());
    }

    public function testKernelRoutesShareSessionAcrossSimulatedRequestsAndResponseTypes(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Config/Session.php', '<?php return ["driver" => "array"];');
            $app = new Application($project->path());
            $app->register(SessionServiceProvider::class);
            $app->register(HttpServiceProvider::class);
            $app->register(RoutingServiceProvider::class);
            $app->bootstrap();
            $routes = $app->container()->make(RouteRegistry::class);
            $routes->post('/save', static function (): RedirectResponse {
                \session()->put('theme', 'dark');
                \session()->flash('status', 'saved');
                return new RedirectResponse('/show');
            });
            $routes->get('/show', static fn (): JsonResponse => new JsonResponse([
                'theme' => \session()->get('theme'), 'status' => \session()->get('status'),
            ]));
            $routes->get('/html', static fn (): string => '<p>' . (\session()->get('status') ?? 'expired') . '</p>');
            $kernel = $app->container()->make(Kernel::class);
            $store = $app->container()->make(SessionManager::class)->store();

            $redirect = $kernel->handle(new Request('POST', '/save'));
            self::assertSame(302, $redirect->status());
            self::assertSame('/show', $redirect->header('Location'));
            $store->close();
            $json = $kernel->handle(new Request('GET', '/show', headers: ['Accept' => 'application/json']));
            self::assertSame(['theme' => 'dark', 'status' => 'saved'], json_decode($json->content(), true));
            $store->close();
            self::assertSame('<p>expired</p>', $kernel->handle(new Request('GET', '/html'))->content());
        } finally {
            $project->remove();
        }
    }
}
