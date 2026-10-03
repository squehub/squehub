<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\AccountSecurity\AccountSecurity as SecurityGateway;
use App\AccountSecurity\AccountSecurityServiceProvider;
use App\Auth\Auth as AuthGateway;
use App\Auth\AuthServiceProvider;
use App\Authorization\Authorization as AuthorizationGateway;
use App\Authorization\AuthorizationServiceProvider;
use App\Cache\Cache as CacheGateway;
use App\Cache\CacheServiceProvider;
use App\Api\Contract\Contract as ContractGateway;
use App\Api\Contract\ContractManager;
use App\Api\Contract\ContractServiceProvider;
use App\Database\Database as DatabaseGateway;
use App\Database\DatabaseServiceProvider;
use App\Diagnostics\DiagnosticsServiceProvider;
use App\Events\Events as EventGateway;
use App\Events\EventServiceProvider;
use App\Foundation\Application;
use App\Logging\Drivers\ArrayLogger;
use App\Logging\Log as LoggingGateway;
use App\Logging\LoggingServiceProvider;
use App\Mail\Mail as MailGateway;
use App\Mail\MailServiceProvider;
use App\Notifications\Notifications as NotificationGateway;
use App\Notifications\NotificationServiceProvider;
use App\Plugins as Plugins;
use App\RateLimit\RateLimit as RateGateway;
use App\RateLimit\RateLimitServiceProvider;
use App\Routing\Route as RoutingRoute;
use App\Routing\RoutingServiceProvider;
use App\Security\Csrf\Csrf as CsrfGateway;
use App\Security\Csrf\CsrfServiceProvider;
use App\Session\Session as SessionGateway;
use App\Session\SessionServiceProvider;
use App\Storage\Storage as StorageGateway;
use App\Storage\StorageServiceProvider;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** Verifies that application imports share canonical services and observable behavior. */
final class PluginsGatewayTest extends TestCase
{
    /** @var list<TemporaryProject> */
    private array $projects = [];

    protected function tearDown(): void
    {
        DatabaseGateway::setResolver(null);
        RoutingRoute::setResolver(null);
        CacheGateway::setResolver(null);
        StorageGateway::setResolver(null);
        MailGateway::setResolver(null);
        NotificationGateway::setResolver(null);
        EventGateway::setResolver(null);
        LoggingGateway::setResolver(null);
        AuthGateway::setResolver(null);
        AuthorizationGateway::setResolver(null);
        SecurityGateway::setResolver(null);
        SessionGateway::setResolver(null);
        RateGateway::setResolver(null);
        CsrfGateway::setResolver(null);
        ContractGateway::setResolver(null);
        foreach ($this->projects as $project) $project->remove();
    }

    private function application(): Application
    {
        $project = new TemporaryProject();
        $this->projects[] = $project;
        $project->write('Config/Database.php', '<?php return ["default"=>"main","connections"=>["main"=>["driver"=>"sqlite","database"=>":memory:"]]];');
        $project->write('Config/Cache.php', '<?php return ["driver"=>"array"];');
        $project->write('Config/Storage.php', '<?php return ["default"=>"memory","drives"=>["memory"=>["driver"=>"array"]]];');
        $project->write('Config/Mail.php', '<?php return ["default"=>"array","from"=>["address"=>"sender@example.test"],"transports"=>["array"=>["driver"=>"array"]]];');
        $project->write('Config/Logging.php', '<?php return ["driver"=>"array","level"=>"debug"];');
        $project->write('Config/Session.php', '<?php return ["driver"=>"array"];');
        $project->write('Config/Auth.php', '<?php return ["default"=>null,"guards"=>[],"identities"=>[]];');
        $project->write('Config/RateLimit.php', '<?php return ["store"=>"array","prefix"=>"plugins-test"];');
        $project->write('Config/AccountSecurity.php', '<?php return ["tokens"=>["driver"=>"array"]];');
        $app = new Application($project->path());
        foreach ([
            DiagnosticsServiceProvider::class, DatabaseServiceProvider::class,
            CacheServiceProvider::class, StorageServiceProvider::class,
            SessionServiceProvider::class, CsrfServiceProvider::class,
            EventServiceProvider::class, LoggingServiceProvider::class,
            RoutingServiceProvider::class, AuthServiceProvider::class,
            ContractServiceProvider::class,
            AuthorizationServiceProvider::class, AccountSecurityServiceProvider::class,
            RateLimitServiceProvider::class, MailServiceProvider::class,
            NotificationServiceProvider::class,
        ] as $provider) $app->register($provider);
        $app->bootstrap();
        return $app;
    }

    public function testRouteAndViewShareExistingImplementations(): void
    {
        $app = $this->application();
        $registry = $app->container()->make(\App\Routing\RouteRegistry::class);
        self::assertSame($registry, Plugins\Route::registry());
        Plugins\Route::path('/plugins')->get(static fn (): string => 'plugin');
        \App\Core\Route::path('/canonical')->get(static fn (): string => 'canonical');
        self::assertCount(2, $registry->all());
        self::assertSame($registry, RoutingRoute::registry());

        $viewDir = $app->basePath('Project/Views/Plugins');
        if (!is_dir($viewDir)) mkdir($viewDir, 0777, true);
        file_put_contents($viewDir . '/Parity.squehub.php', 'Hello {{ $name }}');
        \App\Core\View::initViewPaths();
        ob_start();
        Plugins\View::render('Plugins.Parity', ['name' => '<Ada>']);
        $pluginOutput = ob_get_clean();
        ob_start();
        \App\Core\View::render('Plugins.Parity', ['name' => '<Ada>']);
        $canonicalOutput = ob_get_clean();
        self::assertSame($canonicalOutput, $pluginOutput);
        self::assertSame('Hello &lt;Ada&gt;', $pluginOutput);
    }

    public function testPluginRouteGatewayKeepsThePathFirstContract(): void
    {
        $this->application();
        $registry = Plugins\Route::registry();
        self::assertNotNull($registry);

        foreach (['get', 'post', 'put', 'patch', 'delete', 'options', 'name', 'middleware'] as $shortcut) {
            self::assertFalse(method_exists(Plugins\Route::class, $shortcut), $shortcut);
        }

        Plugins\Route::path('/users/{id}')
            ->get(static fn (): string => 'user')
            ->named('users.show')
            ->through('auth');
        Plugins\Route::group()->prefix('/admin')->through('auth')->routes(static function (): void {
            Plugins\Route::path('/users')->get(static fn (): string => 'admin');
        });

        self::assertSame('/users/42', $registry->url('users.show', ['id' => 42]));
        self::assertSame(['/users/{id}', '/admin/users'], array_map(
            static fn ($route): string => $route->uri(), $registry->all()));
        self::assertSame(['auth'], $registry->all()[0]->middlewares());
        self::assertSame(['auth'], $registry->all()[1]->middlewares());
    }

    public function testModelFactoryQueryAndRelationshipsRetainOrmState(): void
    {
        $this->application();
        Plugins\DB::schema()->create('plugin_users', static function (Plugins\Table $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('active');
        });
        Plugins\DB::schema()->create('plugin_posts', static function (Plugins\Table $table): void {
            $table->id();
            $table->integer('user_id');
            $table->string('title');
        });
        $user = PluginUserFactory::new()->make();
        self::assertInstanceOf(PluginUser::class, $user);
        self::assertFalse($user->exists());
        self::assertSame('Factory user', $user->name);
        self::assertTrue($user->save());
        $createdByFactory = PluginUserFactory::new()->create(['name' => 'Second']);
        self::assertInstanceOf(PluginUser::class, $createdByFactory);
        self::assertTrue($createdByFactory->exists());
        self::assertTrue($user->exists());
        self::assertFalse($user->isDirty());
        self::assertTrue($user->active);
        self::assertInstanceOf(\App\Database\Model::class, $user);
        $post = PluginPost::create(['user_id' => (int) $user->id, 'title' => 'First']);
        self::assertSame('First', $user->posts[0]->title);
        self::assertEquals($user->id, $post->author->id);
        $user->name = 'Changed';
        self::assertTrue($user->isDirty('name'));
        self::assertTrue($user->save());
        self::assertSame('Changed', PluginUser::find((int) $user->id)->name);
        self::assertSame(2, Plugins\DB::table('plugin_users')->count());
        self::assertSame(2, PluginUser::query()->count());
        self::assertTrue($post->delete());
        self::assertTrue($user->delete());
        self::assertTrue($createdByFactory->delete());
        self::assertSame(0, Plugins\DB::table('plugin_users')->count());
    }

    public function testServiceFacadesPreserveOwnershipAndDiagnostics(): void
    {
        $app = $this->application();
        self::assertSame(cache(), Plugins\Cache::store());
        self::assertSame(storage(), Plugins\Storage::manager());
        self::assertSame(mailer(), Plugins\Mail::mailer());
        self::assertSame(notifications(), Plugins\Notifications::manager());
        self::assertSame(events(), Plugins\Event::dispatcher());
        self::assertSame(logger(), Plugins\Log::logger());
        self::assertSame(database(), Plugins\DB::manager());
        self::assertSame(AuthGateway::manager(), Plugins\Auth::manager());
        self::assertSame(AuthorizationGateway::manager(), Plugins\Gate::manager());
        self::assertSame(SecurityGateway::manager(), Plugins\AccountSecurity::manager());
        self::assertSame(SessionGateway::manager(), Plugins\Session::manager());
        self::assertSame(RateGateway::manager(), Plugins\RateLimit::manager());
        self::assertSame(CsrfGateway::manager(), Plugins\Csrf::manager());

        Plugins\Cache::write('entry', 'value', 60);
        self::assertSame('value', cache()->read('entry'));
        Plugins\Storage::write('folder/a.txt', 'stored');
        self::assertSame('stored', storage()->read('folder/a.txt'));
        Plugins\Session::put('theme', 'dark');
        self::assertSame('dark', SessionGateway::manager()->store()->get('theme'));

        $fired = 0;
        Plugins\Event::listen(PluginEvent::class, static function (PluginEvent $event) use (&$fired): void { ++$fired; });
        Plugins\Event::emit(new PluginEvent());
        self::assertSame(1, $fired);
        Plugins\Log::info('plugin-log');
        self::assertCount(1, $app->container()->make(ArrayLogger::class)->records());
        self::assertTrue(Plugins\Validator::for(['name' => 'Ada'])->check(['name' => 'required|string'])->passes());
        self::assertSame(\App\Validation\UniqueRule::class, Plugins\Rule::unique('plugin_users', 'name')::class);
        self::assertTrue(Plugins\RateLimit::consume('plugins', 'user-1', 1, 60)->allowed());
        self::assertTrue(Plugins\RateLimit::consume('plugins', 'user-1', 1, 60)->denied());
        self::assertTrue(Plugins\RateLimit::clear('plugins', 'user-1'));
        Plugins\Gate::define('private', static fn (): bool => true);
        self::assertFalse(Plugins\Gate::allows('private')); // Guest remains denied.
    }

    public function testContractGatewayUsesTheCurrentApplicationWithoutSharingDefinitions(): void
    {
        $first = $this->application();
        $firstManager = $first->container()->make(ContractManager::class);
        self::assertSame($firstManager, Plugins\Contract::manager());
        Plugins\Contract::info('First API', '1.0.0');
        Plugins\Contract::tag('First', 'First application operations');
        Plugins\Contract::schema('FirstItem', Plugins\ContractSchema::object([
            'name' => Plugins\ContractSchema::string(),
        ])->required(['name']));
        self::assertArrayHasKey('FirstItem', $firstManager->application()['schemas']);
        self::assertSame('First application operations', $firstManager->application()['tags']['First']);

        $second = $this->application();
        $secondManager = $second->container()->make(ContractManager::class);
        self::assertSame($secondManager, Plugins\Contract::manager());
        self::assertNotSame($firstManager, $secondManager);
        self::assertArrayNotHasKey('FirstItem', Plugins\Contract::application()['schemas']);
        self::assertArrayNotHasKey('First', Plugins\Contract::application()['tags']);
        self::assertSame('First API', $firstManager->application()['application']['title']);
    }

    public function testMailNotificationsAndApplicationIsolation(): void
    {
        $first = $this->application();
        Plugins\Cache::write('same', 'first');
        Plugins\Storage::write('same', 'first');
        $recipient = new PluginRecipient();
        Plugins\Notifications::send($recipient, new PluginWelcome());
        $recipient->notify(new PluginWelcome());
        Plugins\Mail::send((new Plugins\MailMessage())->to('direct@example.test')->subject('Direct')->text('Direct'));
        $firstMailer = $first->container()->make(\App\Mail\Mailer::class);
        self::assertCount(3, $firstMailer->transport()->messages());
        self::assertSame('recipient@example.test', $firstMailer->transport()->messages()[0]->recipients()[0]->address());
        $firstNotifications = $first->container()->make(\App\Notifications\NotificationManager::class);
        self::assertSame([], $firstNotifications->channel('array')->notifications());

        $second = $this->application();
        self::assertSame('missing', Plugins\Cache::read('same', 'missing'));
        self::assertFalse(Plugins\Storage::exists('same'));
        self::assertCount(0, Plugins\Mail::mailer()->transport()->messages());
        self::assertNotSame($firstNotifications, Plugins\Notifications::manager());
        Plugins\Notifications::send(new PluginRecipient(), new PluginArrayNotice());
        self::assertCount(1, Plugins\Notifications::manager()->channel('array')->notifications());
        self::assertSame([], $firstNotifications->channel('array')->notifications());
        self::assertCount(3, $firstMailer->transport()->messages());
        self::assertNotSame($first->container()->make(\App\Events\EventDispatcher::class),
            $second->container()->make(\App\Events\EventDispatcher::class));
    }

    public function testCanonicalExceptionsAndDiagnosticsRemainAuthoritative(): void
    {
        $app = $this->application();
        $diagnostics = $app->container()->make(\App\Diagnostics\Diagnostics::class);
        $diagnostics->begin(new Plugins\Request('GET', '/plugins'));
        Plugins\Cache::write('item', 'cached');
        Plugins\Storage::write('item.txt', 'stored');
        Plugins\Mail::send((new Plugins\MailMessage())
            ->to('recipient@example.test')->subject('Subject')->text('Body'));
        $snapshot = $diagnostics->snapshot();
        self::assertSame(1, $snapshot['cache']['writes']);
        self::assertSame(1, $snapshot['storage']['writes']);
        self::assertSame(1, $snapshot['mail']['attempts']);
        self::assertArrayNotHasKey('plugins', $snapshot);

        try { Plugins\Storage::read('../outside'); self::fail('Expected Storage validation.'); }
        catch (\App\Storage\StorageException) { self::assertTrue(true); }
        try { Plugins\Cache::read(''); self::fail('Expected Cache validation.'); }
        catch (\InvalidArgumentException) { self::assertTrue(true); }
        try { Plugins\Mail::send(new Plugins\MailMessage()); self::fail('Expected Mail validation.'); }
        catch (\App\Mail\MailConfigurationException) { self::assertTrue(true); }
    }
}

/** ORM fixture using only the Plugins model import. */
final class PluginUser extends Plugins\Model
{
    protected string $table = 'plugin_users';
    protected array $fillable = ['name', 'active'];
    protected array $casts = ['active' => 'boolean'];

    public function posts(): \App\Database\Relations\HasMany
    {
        return $this->hasMany(PluginPost::class, 'user_id');
    }
}

/** Relationship fixture using the same canonical ORM contract. */
final class PluginPost extends Plugins\Model
{
    protected string $table = 'plugin_posts';
    protected array $fillable = ['user_id', 'title'];

    public function author(): \App\Database\Relations\BelongsTo
    {
        return $this->belongsTo(PluginUser::class, 'user_id');
    }
}

/** Factory fixture with no copied generation logic. */
final class PluginUserFactory extends Plugins\ModelFactory
{
    protected string $model = PluginUser::class;
    protected function definition(): array { return ['name' => 'Factory user', 'active' => true]; }
}

/** Plain application event with no framework-specific base class. */
final class PluginEvent
{
}

/** A trait-based recipient can be a plain object as well as a Model. */
final class PluginRecipient
{
    use Plugins\Notifiable;
    public function routeNotificationForMail(): string { return 'recipient@example.test'; }
}

/** A Plugins notification remains a canonical notification to Mail channel. */
final class PluginWelcome extends Plugins\Notification
{
    public function via(mixed $notifiable): array { return ['mail']; }
    public function toMail(mixed $notifiable): Plugins\MailMessage
    {
        return (new Plugins\MailMessage())->subject('Welcome')->text('Hello');
    }
}

/** Array delivery exercises the independent Notifications channel. */
final class PluginArrayNotice extends Plugins\Notification
{
    public function via(mixed $notifiable): array { return ['array']; }
}
