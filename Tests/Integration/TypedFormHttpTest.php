<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration\TypedForms;

use App\Core\View;
use App\Data\ValidatedData;
use App\Foundation\Application;
use App\Http\BrowserFormsServiceProvider;
use App\Http\HttpServiceProvider;
use App\Http\Kernel;
use App\Http\RedirectResponse;
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

/** Typed forms use the existing CSRF, validation, flash, and redirect pipeline. */
final class TypedFormHttpTest extends TestCase
{
    private TemporaryProject $project;
    private Kernel $kernel;
    private SessionManager $sessions;
    private ?CreateProfileData $created = null;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
        $this->project->write('Config/Session.php', '<?php return ["driver" => "array"];');
        $this->project->write('Config/Csrf.php', '<?php return ["field" => "form_guard", "enabled" => true, "header" => "X-CSRF-Token", "except" => []];');
        $app = new Application($this->project->path());
        foreach ([SessionServiceProvider::class, ValidationServiceProvider::class,
            CsrfServiceProvider::class, HttpServiceProvider::class,
            BrowserFormsServiceProvider::class, RoutingServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        $app->bootstrap();
        $this->kernel = $app->container()->make(Kernel::class);
        $this->sessions = $app->container()->make(SessionManager::class);

        $this->project->write('Project/Views/TypedProfile/Form.squehub.php', <<<'VIEW'
<form method="POST" action="/profiles">
    @csrf
    <input name="name" value="{{ old('name', '') }}">
    <input name="email" value="{{ old('email', '') }}">
    <input name="age" value="{{ old('age', '') }}">
    @error('email')<span class="field-error">{{ $message }}</span>@enderror
    <button type="submit">Create</button>
</form>
VIEW);
        View::initViewPaths();

        $routes = $app->container()->make(RouteRegistry::class);
        $routes->get('/profiles/new', static function (): void {
            View::render('TypedProfile.Form');
        });
        $routes->post('/profiles', function (Request $request): RedirectResponse {
            $this->created = $request->validatedAs(CreateProfileData::class);
            return new RedirectResponse('/profiles/complete', 303);
        });
        $routes->post('/array-form', static function (Request $request): array {
            return $request->validate(['name' => 'required|string']);
        });
        $routes->post('/strict-age', static function (Request $request): string {
            $request->validatedAs(StrictAgeData::class);
            return 'unexpected';
        });
    }

    protected function tearDown(): void
    {
        Csrf::setResolver(null);
        Session::setResolver(null);
        Route::setResolver(null);
        $this->project->remove();
    }

    public function testTypedFormRetainsCsrfValidationFlashAndSuccessRedirect(): void
    {
        $form = $this->kernel->handle(new Request('GET', '/profiles/new'));
        self::assertSame(200, $form->status());
        self::assertStringContainsString('name="form_guard"', $form->content());
        $token = \csrf_token();
        $this->sessions->store()->close();

        $rejected = $this->kernel->handle(new Request('POST', '/profiles', form: [
            'name' => 'Ada', 'email' => 'ada@example.test', 'age' => '28',
        ]));
        self::assertSame(403, $rejected->status());
        self::assertNull($this->created);
        self::assertSame([], \old());

        $invalid = $this->kernel->handle(new Request('POST', '/profiles', form: [
            'form_guard' => $token, 'name' => 'Ada', 'email' => '<script>secret</script>',
            'age' => '28', 'password' => 'private-password', 'is_admin' => '1',
        ]));
        self::assertSame(303, $invalid->status());
        self::assertSame('/profiles/new', $invalid->header('Location'));
        self::assertNull($this->created);
        $this->sessions->store()->close();

        $returned = $this->kernel->handle(new Request('GET', '/profiles/new'));
        self::assertSame(200, $returned->status());
        self::assertStringContainsString('&lt;script&gt;secret&lt;/script&gt;', $returned->content());
        self::assertStringNotContainsString('<script>', $returned->content());
        self::assertStringContainsString('field-error', $returned->content());
        self::assertTrue(\errors()->has('email'));
        self::assertNull(\old('password'));
        self::assertNull(\old('form_guard'));
        $token = \csrf_token();
        $this->sessions->store()->close();

        $valid = $this->kernel->handle(new Request('POST', '/profiles', form: [
            'form_guard' => $token, 'name' => 'Ada', 'email' => 'ada@example.test',
            'age' => '28', 'is_admin' => '1', 'password_hash' => 'private-hash',
        ]));
        self::assertSame(303, $valid->status());
        self::assertSame('/profiles/complete', $valid->header('Location'));
        self::assertInstanceOf(CreateProfileData::class, $this->created);
        self::assertSame('Ada', $this->created->name);
        self::assertSame('ada@example.test', $this->created->email);
        self::assertSame(28, $this->created->age);
        self::assertObjectNotHasProperty('is_admin', $this->created);
        self::assertObjectNotHasProperty('password_hash', $this->created);
    }

    public function testExistingArrayOnlyFormRemainsAvailable(): void
    {
        $this->kernel->handle(new Request('GET', '/profiles/new'));
        $token = \csrf_token();
        $this->sessions->store()->close();
        $response = $this->kernel->handle(new Request('POST', '/array-form', form: [
            'form_guard' => $token, 'name' => 'Grace', 'ignored' => 'private',
        ]));
        self::assertSame(200, $response->status());
        self::assertSame(['name' => 'Grace'], json_decode($response->content(), true));
    }

    public function testTypedConversionFailureUsesOrdinaryBrowserValidationFlow(): void
    {
        $this->kernel->handle(new Request('GET', '/profiles/new'));
        $token = \csrf_token();
        $this->sessions->store()->close();
        $response = $this->kernel->handle(new Request('POST', '/strict-age', form: [
            'form_guard' => $token, 'age' => '01', 'password' => 'private-password',
        ]));
        self::assertSame(303, $response->status());
        self::assertSame('/profiles/new', $response->header('Location'));
        $this->sessions->store()->close();
        $this->kernel->handle(new Request('GET', '/profiles/new'));
        self::assertTrue(\errors()->has('age'));
        self::assertSame('01', \old('age'));
        self::assertNull(\old('password'));
    }
}

/** An ordinary readonly class owns data while validation remains explicit. */
final readonly class CreateProfileData implements ValidatedData
{
    public function __construct(
        public string $name,
        public string $email,
        public int $age,
    ) {
    }

    public static function rules(): array
    {
        return ['name' => 'required|string', 'email' => 'required|email', 'age' => 'required|integer'];
    }
}

/** Loose validation rules cannot force ambiguous data through an integer constructor. */
final readonly class StrictAgeData implements ValidatedData
{
    public function __construct(public int $age)
    {
    }

    public static function rules(): array
    {
        return ['age' => 'required|string'];
    }
}
