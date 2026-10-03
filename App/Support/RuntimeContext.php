<?php

declare(strict_types=1);

namespace App\Support;

use App\AccountSecurity\AccountSecurity;
use App\AccountSecurity\AccountSecurityManager;
use App\Api\Contract\Contract;
use App\Api\Contract\ContractManager;
use App\Auth\Auth;
use App\Auth\AuthManager;
use App\Authorization\Authorization;
use App\Authorization\AuthorizationManager;
use App\Authorization\Rbac\Rbac;
use App\Authorization\Rbac\RbacManager;
use App\Broadcasting\Broadcast;
use App\Broadcasting\BroadcastManager;
use App\Cache\Cache;
use App\Cache\CacheStore;
use App\Container\Container;
use App\Core\View;
use App\Cryptography\Crypt;
use App\Cryptography\CryptManager;
use App\Database\Database;
use App\Database\DatabaseManager;
use App\Diagnostics\Diagnostic;
use App\Diagnostics\Diagnostics;
use App\Events\EventDispatcher;
use App\Events\Events;
use App\Foundation\Application;
use App\Health\Health;
use App\Health\HealthManager;
use App\Locks\Lock;
use App\Locks\LockManager;
use App\HttpClient\Http;
use App\HttpClient\HttpClient;
use App\Idempotency\Idempotency;
use App\Idempotency\IdempotencyManager;
use App\Logging\Log;
use App\Logging\Logger;
use App\Mail\Mail;
use App\Mfa\Mfa;
use App\Mfa\MfaManager;
use App\Mail\Mailer;
use App\Notifications\NotificationManager;
use App\Notifications\Notifications;
use App\OAuth\OAuth;
use App\OAuth\OAuthManager;
use App\Packages\PackageManager;
use App\Queue\Queue;
use App\Queue\QueueManager;
use App\RateLimit\RateLimit;
use App\RateLimit\RateLimiter;
use App\Redis\Redis;
use App\Redis\RedisManager;
use App\Routing\Route;
use App\Routing\RouteRegistry;
use App\Scheduler\Schedule;
use App\Scheduler\Scheduler;
use App\Security\Csrf\Csrf;
use App\Security\Csrf\CsrfTokenManager;
use App\Security\SignedUrl\SignedUrl;
use App\Security\SignedUrl\SignedUrlManager;
use App\Session\Session;
use App\Session\SessionManager;
use App\Storage\Storage;
use App\Storage\StorageManager;
use App\Translation\Translation;
use App\Translation\TranslationManager;
use App\Webhooks\Webhook;
use App\Webhooks\WebhookManager;
use Closure;

/** Selects the Application behind static developer APIs for one operation. */
final class RuntimeContext
{
    public static function select(Application $app): void
    {
        $container = $app->container();
        View::selectApplicationContext($app->contributions(), $app->basePath(),
            $container->has(PackageManager::class) ? $container->make(PackageManager::class) : null);
        View::selectContextManager($app->views());

        // These bridges predate Application-owned services. Rebind all of them
        // when requests or route files alternate between Applications in one
        // PHP process. An absent service must not fall through to another app.
        Route::setResolver(self::resolver($container, RouteRegistry::class));
        AccountSecurity::setResolver(self::resolver($container, AccountSecurityManager::class));
        Mfa::setResolver(self::resolver($container, MfaManager::class));
        Contract::setResolver(self::resolver($container, ContractManager::class));
        Auth::setResolver(self::resolver($container, AuthManager::class));
        Authorization::setResolver(self::resolver($container, AuthorizationManager::class));
        Rbac::setResolver(self::resolver($container, RbacManager::class));
        Cache::setResolver(self::resolver($container, CacheStore::class));
        Lock::setResolver(self::resolver($container, LockManager::class));
        Idempotency::setResolver(self::resolver($container, IdempotencyManager::class));
        Crypt::setResolver(self::resolver($container, CryptManager::class));
        SignedUrl::setResolver(self::resolver($container, SignedUrlManager::class));
        Database::setResolver(self::resolver($container, DatabaseManager::class));
        Diagnostic::setResolver(self::resolver($container, Diagnostics::class));
        Events::setResolver(self::resolver($container, EventDispatcher::class));
        Health::setResolver(self::resolver($container, HealthManager::class));
        Http::setResolver(self::resolver($container, HttpClient::class));
        Log::setResolver(self::resolver($container, Logger::class));
        Mail::setResolver(self::resolver($container, Mailer::class));
        Notifications::setResolver(self::resolver($container, NotificationManager::class));
        OAuth::setResolver(self::resolver($container, OAuthManager::class));
        Queue::setResolver(self::resolver($container, QueueManager::class));
        Broadcast::setResolver(self::resolver($container, BroadcastManager::class));
        RateLimit::setResolver(self::resolver($container, RateLimiter::class));
        Redis::setResolver(self::resolver($container, RedisManager::class));
        Schedule::setResolver(self::resolver($container, Scheduler::class));
        Csrf::setResolver(self::resolver($container, CsrfTokenManager::class));
        Session::setResolver(self::resolver($container, SessionManager::class));
        Storage::setResolver(self::resolver($container, StorageManager::class));
        Translation::setResolver(self::resolver($container, TranslationManager::class));
        Webhook::setResolver(self::resolver($container, WebhookManager::class));
    }

    /** @param class-string $service */
    private static function resolver(Container $container, string $service): ?Closure
    {
        return $container->has($service)
            ? static fn (): object => $container->make($service) : null;
    }
}
