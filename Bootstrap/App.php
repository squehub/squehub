<?php

declare(strict_types=1);

/** Register core services before route loading; return one booted Application. */
use App\Foundation\Application;
use App\Foundation\CliBootstrapMode;
use App\Database\DatabaseServiceProvider;
use App\Http\HttpServiceProvider;
use App\Http\BrowserFormsServiceProvider;
use App\Routing\RoutingServiceProvider;
use App\Validation\ValidationServiceProvider;
use App\Session\SessionServiceProvider;
use App\Security\Csrf\CsrfServiceProvider;
use App\Diagnostics\DiagnosticsServiceProvider;
use App\Translation\TranslationServiceProvider;
use App\Observability\ObservabilityServiceProvider;
use App\Profiler\ProfilerServiceProvider;
use App\Logging\LoggingServiceProvider;
use App\Cache\CacheServiceProvider;
use App\Locks\LockServiceProvider;
use App\Events\EventServiceProvider;
use App\Storage\StorageServiceProvider;
use App\Auth\AuthServiceProvider;
use App\Authorization\AuthorizationServiceProvider;
use App\Authorization\Rbac\RbacServiceProvider;
use App\AccountSecurity\AccountSecurityServiceProvider;
use App\Mfa\MfaServiceProvider;
use App\RateLimit\RateLimitServiceProvider;
use App\Mail\MailServiceProvider;
use App\Notifications\NotificationServiceProvider;
use App\Queue\QueueServiceProvider;
use App\Broadcasting\BroadcastServiceProvider;
use App\Scheduler\SchedulerServiceProvider;
use App\Redis\RedisServiceProvider;
use App\Reliability\CircuitServiceProvider;
use App\HttpClient\HttpServiceProvider as OutgoingHttpServiceProvider;
use App\Cryptography\CryptServiceProvider;
use App\Security\SignedUrl\SignedUrlServiceProvider;
use App\Health\HealthServiceProvider;
use App\Idempotency\IdempotencyServiceProvider;
use App\OAuth\OAuthServiceProvider;
use App\Webhooks\WebhookServiceProvider;
use App\Api\Contract\ContractServiceProvider;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/App/Core/Helper.php';

$app = new Application(dirname(__DIR__));
// The private Studio router sets this marker before bootstrap. Inspection
// never executes Package providers, and it can report a corrupt config cache
// so an operator can clear it without running normal application routes.
if (defined('SQUEHUB_STUDIO_INSPECTION_BOOT') && SQUEHUB_STUDIO_INSPECTION_BOOT === true) {
    $app->inspectPackagesOnly();
    $app->ignoreConfigCache();
}
// Package and Kit management commands inspect source and state without
// executing enabled Package providers. Select this before providers register.
if (PHP_SAPI === 'cli') {
    CliBootstrapMode::configure($app, array_values(array_filter($_SERVER['argv'] ?? [], 'is_string')));
}
$app->register(DiagnosticsServiceProvider::class);
$app->register(TranslationServiceProvider::class);
$app->register(ObservabilityServiceProvider::class);
$app->register(ProfilerServiceProvider::class);
$app->register(EventServiceProvider::class);
$app->register(LoggingServiceProvider::class);
$app->register(CacheServiceProvider::class);
$app->register(StorageServiceProvider::class);
$app->register(DatabaseServiceProvider::class);
$app->register(ValidationServiceProvider::class);
$app->register(SessionServiceProvider::class);
$app->register(CsrfServiceProvider::class);
$app->register(HttpServiceProvider::class);
$app->register(BrowserFormsServiceProvider::class);
$app->register(RoutingServiceProvider::class);
$app->register(AuthServiceProvider::class);
$app->register(MfaServiceProvider::class);
$app->register(RbacServiceProvider::class);
$app->register(AuthorizationServiceProvider::class);
$app->register(AccountSecurityServiceProvider::class);
$app->register(RateLimitServiceProvider::class);
$app->register(IdempotencyServiceProvider::class);
$app->register(MailServiceProvider::class);
$app->register(NotificationServiceProvider::class);
$app->register(QueueServiceProvider::class);
$app->register(BroadcastServiceProvider::class);
$app->register(SchedulerServiceProvider::class);
$app->register(RedisServiceProvider::class);
$app->register(LockServiceProvider::class);
$app->register(CircuitServiceProvider::class);
$app->register(OutgoingHttpServiceProvider::class);
$app->register(OAuthServiceProvider::class);
$app->register(WebhookServiceProvider::class);
$app->register(ContractServiceProvider::class);
$app->register(CryptServiceProvider::class);
$app->register(SignedUrlServiceProvider::class);
$app->register(HealthServiceProvider::class);
$app->bootstrap();

return $app;
