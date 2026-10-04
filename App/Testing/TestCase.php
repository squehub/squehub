<?php

declare(strict_types=1);

namespace App\Testing;

use App\Auth\AuthManager;
use App\Auth\Contracts\Authenticatable;
use App\Auth\Contracts\StatefulAuthGuard;
use App\Authorization\Authorization;
use App\Cache\Cache;
use App\Core\Route as CoreRoute;
use App\Core\View;
use App\Database\Database;
use App\Database\DatabaseManager;
use App\Database\Migrations\Migrator;
use App\Database\Seeding\SeederRunner;
use App\Diagnostics\Diagnostic;
use App\Events\Events;
use App\Foundation\Application;
use App\Http\Request;
use App\Logging\Log;
use App\Locks\Lock;
use App\Routing\Route;
use App\Security\Csrf\Csrf;
use App\Security\Csrf\CsrfTokenManager;
use App\Session\Session;
use App\Session\SessionManager;
use App\Session\SessionStore;
use App\Storage\Storage;
use App\Support\RuntimeContext;
use App\Validation\ErrorBag;
use LogicException;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * PHPUnit base for application tests that need the real SqueHub HTTP stack.
 *
 * Each test owns a private project root and Application. Configuration and
 * fixtures are prepared before bootstrap; no developer .env or database
 * credentials are imported. Lower-level unit tests may use plain PHPUnit.
 */
abstract class TestCase extends PHPUnitTestCase
{
    private ?TestApplication $testing = null;
    private ?TestClient $client = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testing = TestApplication::temporary($this->testingConfig());
    }

    protected function tearDown(): void
    {
        try {
            $this->testing?->cleanup();
        } finally {
            $this->testing = null;
            $this->client = null;
            // These bridges hold an Application resolver in process memory.
            // Clearing them prevents a later test from reaching a deleted root.
            Route::setResolver(null);
            CoreRoute::setResolver(null);
            Database::setResolver(null);
            Session::setResolver(null);
            Csrf::setResolver(null);
            Cache::setResolver(null);
            Lock::setResolver(null);
            Storage::setResolver(null);
            Log::setResolver(null);
            Events::setResolver(null);
            Diagnostic::setResolver(null);
            \App\Auth\Auth::setResolver(null);
            Authorization::setResolver(null);
            \App\Authorization\Rbac\Rbac::setResolver(null);
            \App\AccountSecurity\AccountSecurity::setResolver(null);
            \App\Mfa\Mfa::setResolver(null);
            \App\Api\Contract\Contract::setResolver(null);
            \App\Cryptography\Crypt::setResolver(null);
            \App\Security\SignedUrl\SignedUrl::setResolver(null);
            \App\Health\Health::setResolver(null);
            \App\HttpClient\Http::setResolver(null);
            \App\Mail\Mail::setResolver(null);
            \App\Notifications\Notifications::setResolver(null);
            \App\OAuth\OAuth::setResolver(null);
            \App\Queue\Queue::setResolver(null);
            \App\RateLimit\RateLimit::setResolver(null);
            \App\Redis\Redis::setResolver(null);
            \App\Scheduler\Schedule::setResolver(null);
            \App\Webhooks\Webhook::setResolver(null);
            \App\Translation\Translation::setResolver(null);
            View::setContributionRegistry(null);
            parent::tearDown();
        }
    }

    /**
     * Override to set project configuration before any provider boots.
     * The fixture still enforces local SQLite and inert state drivers.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function testingConfig(): array
    {
        return [];
    }

    protected function testApplication(): TestApplication
    {
        return $this->testing ?? throw new LogicException('The PHPUnit test fixture is not active.');
    }

    protected function app(): Application
    {
        return $this->testApplication()->application();
    }

    /**
     * Render one logical View through the same Application-owned compiler,
     * context, layout and asset pipeline used by an HTTP response. Each call
     * receives a fresh minimal Request scope for request-bound providers.
     *
     * @param array<array-key, mixed> $data
     */
    protected function view(string $name, array $data = []): ViewTestResult
    {
        return $this->renderViewForTest($name, null, $data);
    }

    /**
     * Select exactly one 14L Fragment without rendering siblings or layouts.
     * Both entrypoints return the same assertion surface.
     *
     * @param array<array-key, mixed> $data
     */
    protected function fragment(string $view, string $name, array $data = []): ViewTestResult
    {
        return $this->renderViewForTest($view, $name, $data);
    }

    /** Populate the real flashed validation bag for direct View presentation. */
    protected function withErrors(array $errors): static
    {
        $this->session()->flash('_validation_errors', $errors);
        return $this;
    }

    /** Old input uses SessionStore's normal filtering and flash lifetime. */
    protected function withOldInput(array $input): static
    {
        $this->session()->flashInput($input);
        return $this;
    }

    /** One client keeps cookies and the array session between its requests. */
    protected function client(): TestClient
    {
        return $this->client ??= new TestClient($this->testApplication());
    }

    protected function get(string $uri, array $headers = []): TestResponse
    {
        return $this->client()->get($uri, $headers);
    }

    protected function post(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->client()->post($uri, $data, $headers);
    }

    protected function put(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->client()->put($uri, $data, $headers);
    }

    protected function patch(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->client()->patch($uri, $data, $headers);
    }

    protected function delete(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->client()->delete($uri, $data, $headers);
    }

    protected function getJson(string $uri, array $headers = []): TestResponse
    {
        return $this->client()->getJson($uri, $headers);
    }

    protected function postJson(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->client()->postJson($uri, $data, $headers);
    }

    /** CSRF is enabled by default; disabling it must precede Application boot. */
    protected function withoutCsrf(): static
    {
        $this->testApplication()->configure(['csrf' => ['enabled' => false]]);
        return $this;
    }

    /** Use the real session-bound token, with no test-only CSRF bypass. */
    protected function withCsrfToken(): static
    {
        $app = $this->app();
        $token = $app->container()->make(CsrfTokenManager::class)->token();
        $header = $app->config()->get('csrf.header', 'X-CSRF-Token');
        if (!is_string($header)) {
            throw new LogicException('The configured CSRF header must be a string.');
        }
        $this->client()->withHeader($header, $token);
        return $this;
    }

    /** Log into a configured session guard using normal Auth behavior. */
    protected function actingAs(Authenticatable $identity, ?string $guard = null): static
    {
        $selected = $this->app()->container()->make(AuthManager::class)->guard($guard);
        if (!$selected instanceof StatefulAuthGuard) {
            throw new LogicException('The selected test guard cannot establish a session identity.');
        }
        $selected->login($identity);
        return $this;
    }

    protected function guest(?string $guard = null): static
    {
        $selected = $this->app()->container()->make(AuthManager::class)->guard($guard);
        if (!$selected instanceof StatefulAuthGuard) {
            throw new LogicException('The selected test guard cannot clear a session identity.');
        }
        $selected->logout();
        return $this;
    }

    protected function session(): SessionStore
    {
        return $this->app()->container()->make(SessionManager::class)->store();
    }

    /** Optional value comparison is strict and keeps values out of failures. */
    protected function assertSessionHas(string $key, mixed $expected = null): void
    {
        self::assertTrue($this->session()->has($key), 'Expected session key is missing.');
        if (func_num_args() > 1) {
            self::assertTrue($this->session()->get($key) === $expected,
                'Expected session value to match the supplied value.');
        }
    }

    protected function assertSessionMissing(string $key): void
    {
        self::assertFalse($this->session()->has($key), 'Expected session key to be absent.');
    }

    /** Browser validation flashes a field-indexed ErrorBag for the next request. */
    protected function assertSessionHasErrors(array $fields = []): void
    {
        $errors = $this->validationErrors();
        self::assertTrue($errors->any(), 'Expected validation errors in the session.');
        foreach ($fields as $field) {
            self::assertTrue($errors->has($field), 'Expected validation error for a supplied field.');
        }
    }

    protected function assertSessionHasNoErrors(): void
    {
        self::assertFalse($this->validationErrors()->any(), 'Expected no validation errors in the session.');
    }

    protected function assertOldInput(string $key, mixed $expected): void
    {
        self::assertTrue($this->session()->old($key) === $expected,
            'Expected old input to match the supplied value.');
    }

    /** Migrations and Seeders remain explicit; neither runs during setUp(). */
    protected function migrate(): array
    {
        $app = $this->app();
        $databases = $app->container()->make(DatabaseManager::class);
        return (new Migrator($databases, $app->basePath(), $databases->defaultName()))->run();
    }

    protected function seed(string $name = 'DatabaseSeeder'): array
    {
        return (new SeederRunner($this->app()))->run($name);
    }

    private function validationErrors(): ErrorBag
    {
        $value = $this->session()->get('_validation_errors', []);
        return new ErrorBag(is_array($value) ? $value : []);
    }

    /** @param array<array-key, mixed> $data */
    private function renderViewForTest(string $view, ?string $fragment, array $data): ViewTestResult
    {
        $app = $this->app();
        RuntimeContext::select($app);
        $this->testApplication()->loadRoutes();
        $context = $app->views();
        $context->beginRequest(new Request());
        try {
            $result = $fragment === null
                ? View::renderResult($view, $data)
                : View::fragment($view, $fragment, $data);
        } finally {
            $context->endRequest();
        }
        return new ViewTestResult($view, $fragment, $result, $app);
    }
}
