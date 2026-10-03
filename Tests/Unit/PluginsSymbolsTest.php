<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Plugins as Plugins;
use PHPUnit\Framework\TestCase;

/**
 * The gateway inventory is explicit: application imports stay discoverable
 * without runtime namespace guessing or accidental exposure of internals.
 */
final class PluginsSymbolsTest extends TestCase
{
    public function testEveryPluginsFileExportsItsMatchingPublicSymbol(): void
    {
        $files = glob(dirname(__DIR__, 2) . '/App/Plugins/*.php');
        self::assertIsArray($files);
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            $name = 'App\\Plugins\\' . basename($file, '.php');
            self::assertTrue(class_exists($name) || interface_exists($name) || trait_exists($name), $name);
        }
    }

    public function testEveryDeclaredGatewaySymbolAutoloadsWithCanonicalCasing(): void
    {
        $classes = [
            'Route', 'View', 'Model', 'ModelFactory', 'Seeder', 'ServiceProvider', 'OAuth',
            'DB', 'Cache', 'Storage', 'Auth', 'Gate', 'Rbac', 'Mfa', 'Event', 'Log', 'Mail',
            'MailMessage', 'MailAddress', 'Notifications', 'Notification',
            'Queue', 'QueueException', 'Redis', 'Schedule', 'ScheduledTask',
            'SchedulerException', 'SchedulerRunResult',
            'Validator', 'Rule', 'Session', 'RateLimit', 'RateLimitRequests',
            'RequireAbility', 'AccountSecurity', 'Csrf',
            'Request', 'Response', 'JsonResponse', 'RedirectResponse', 'Cookie',
            'ApiResource', 'ResourceCollection', 'ResourceException', 'ApiError',
            'Contract', 'ContractSchema',
            'ModelQuery', 'ModelCollection', 'Page', 'CursorPage', 'QueryBuilder',
            'Schema', 'Table', 'TransactionIsolation', 'MorphMap', 'MorphTo',
            'MorphOne', 'MorphMany', 'ModelObserverRegistry', 'ModelLifecycleEvent',
            'RateLimitRule', 'RateLimitResult', 'ValidationResult', 'ErrorBag',
            'UniqueRule', 'UploadedFile', 'SecurityToken', 'AuthorizationDecision',
            'ExternalIdentity',
        ];
        $interfaces = ['Authenticatable', 'ValidationRule', 'StoppableEvent',
            'NotificationChannel', 'QueueJob', 'ShouldQueue', 'QueueNotifiable'];
        $traits = ['Notifiable', 'StopsEventPropagation'];

        foreach ($classes as $name) {
            self::assertTrue(class_exists('App\\Plugins\\' . $name), $name);
        }
        foreach ($interfaces as $name) {
            self::assertTrue(interface_exists('App\\Plugins\\' . $name), $name);
        }
        foreach ($traits as $name) {
            self::assertTrue(trait_exists('App\\Plugins\\' . $name), $name);
        }
        foreach ([...$classes, ...$interfaces, ...$traits] as $name) {
            $path = dirname(__DIR__, 2) . '/App/Plugins/' . $name . '.php';
            self::assertSame($name . '.php', basename((string) realpath($path)), $name);
        }
    }

    public function testGatewayTypeAdaptersRetainCanonicalIdentityAndInheritance(): void
    {
        self::assertTrue(is_subclass_of(Plugins\Model::class, \App\Database\Model::class));
        self::assertTrue(is_subclass_of(Plugins\ModelFactory::class, \App\Database\Factories\ModelFactory::class));
        self::assertTrue(is_subclass_of(Plugins\Notification::class, \App\Notifications\Notification::class));
        self::assertTrue(is_subclass_of(Plugins\ServiceProvider::class, \App\Foundation\ServiceProvider::class));
        self::assertTrue(is_subclass_of(Plugins\ApiResource::class, \App\Api\ApiResource::class));
        self::assertTrue((new \ReflectionClass(Plugins\ApiResource::class))->isAbstract());
        self::assertTrue(is_subclass_of(Plugins\View::class, \App\Core\View::class));
        self::assertSame(\App\Auth\Contracts\Authenticatable::class,
            (new \ReflectionClass(Plugins\Authenticatable::class))->getName());
        self::assertSame(\App\Notifications\NotificationChannel::class,
            (new \ReflectionClass(Plugins\NotificationChannel::class))->getName());

        $aliases = [
            'MailMessage' => \App\Mail\MailMessage::class,
            'MailAddress' => \App\Mail\MailAddress::class,
            'Request' => \App\Http\Request::class,
            'Response' => \App\Http\Response::class,
            'JsonResponse' => \App\Http\JsonResponse::class,
            'RedirectResponse' => \App\Http\RedirectResponse::class,
            'Cookie' => \App\Http\Cookie::class,
            'ResourceCollection' => \App\Api\ResourceCollection::class,
            'ResourceException' => \App\Api\ResourceException::class,
            'ApiError' => \App\Api\ApiError::class,
            'ContractSchema' => \App\Api\Contract\Schema::class,
            'ModelQuery' => \App\Database\ModelQuery::class,
            'ModelCollection' => \App\Database\Collections\ModelCollection::class,
            'Page' => \App\Database\Pagination\Page::class,
            'CursorPage' => \App\Database\Pagination\CursorPage::class,
            'QueryBuilder' => \App\Database\QueryBuilder::class,
            'TransactionIsolation' => \App\Database\TransactionIsolation::class,
            'MorphMap' => \App\Database\Relations\MorphMap::class,
            'MorphTo' => \App\Database\Relations\MorphTo::class,
            'MorphOne' => \App\Database\Relations\MorphOne::class,
            'MorphMany' => \App\Database\Relations\MorphMany::class,
            'ModelObserverRegistry' => \App\Database\Lifecycle\ModelObserverRegistry::class,
            'ModelLifecycleEvent' => \App\Database\Lifecycle\ModelLifecycleEvent::class,
            'Schema' => \App\Database\Schema\Schema::class,
            'Table' => \App\Database\Schema\Table::class,
            'RateLimitRule' => \App\RateLimit\RateLimitRule::class,
            'RateLimitResult' => \App\RateLimit\RateLimitResult::class,
            'ValidationResult' => \App\Validation\ValidationResult::class,
            'ErrorBag' => \App\Validation\ErrorBag::class,
            'UniqueRule' => \App\Validation\UniqueRule::class,
            'UploadedFile' => \App\Validation\UploadedFile::class,
            'SecurityToken' => \App\AccountSecurity\SecurityToken::class,
            'AuthorizationDecision' => \App\Authorization\AuthorizationDecision::class,
            'ExternalIdentity' => \App\OAuth\ExternalIdentity::class,
            'RateLimitRequests' => \App\RateLimit\Middleware\RateLimitRequests::class,
            'RequireAbility' => \App\Authorization\Middleware\RequireAbility::class,
            'Authenticatable' => \App\Auth\Contracts\Authenticatable::class,
            'ValidationRule' => \App\Validation\ValidationRule::class,
            'StoppableEvent' => \App\Events\StoppableEvent::class,
            'NotificationChannel' => \App\Notifications\NotificationChannel::class,
            'QueueJob' => \App\Queue\QueueJob::class,
            'ShouldQueue' => \App\Notifications\ShouldQueue::class,
            'QueueNotifiable' => \App\Notifications\QueueNotifiable::class,
            'QueueException' => \App\Queue\QueueException::class,
            'ScheduledTask' => \App\Scheduler\ScheduledTask::class,
            'SchedulerException' => \App\Scheduler\SchedulerException::class,
            'SchedulerRunResult' => \App\Scheduler\SchedulerRunResult::class,
        ];
        foreach ($aliases as $name => $canonical) {
            self::assertSame($canonical,
                (new \ReflectionClass('App\\Plugins\\' . $name))->getName(), $name);
        }
        self::assertInstanceOf(\App\Mail\MailMessage::class, new Plugins\MailMessage());
        self::assertInstanceOf(Plugins\MailMessage::class, new \App\Mail\MailMessage());
    }

    public function testApiInternalsHaveNoGatewaySymbols(): void
    {
        self::assertFalse(class_exists('App\\Plugins\\ResourceResult'));
        self::assertFalse(class_exists('App\\Plugins\\OmittedValue'));
        self::assertFalse(class_exists('App\\Plugins\\ApiRequestPolicy'));
        self::assertFalse(class_exists('App\\Plugins\\ApiErrorRenderer'));
        self::assertFalse(class_exists('App\\Plugins\\ApiErrorData'));
        self::assertFalse(class_exists('App\\Plugins\\ContractManager'));
        self::assertFalse(class_exists('App\\Plugins\\OpenApiCompiler'));
        self::assertFalse(class_exists('App\\Plugins\\IdTokenVerifier'));
        self::assertFalse(class_exists('App\\Plugins\\OAuthTransactionStore'));
    }
}
