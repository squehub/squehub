<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Cache\Cache;
use App\Cache\CacheServiceProvider;
use App\Cache\CacheStore;
use App\Foundation\Application;
use App\HttpClient\HttpClient;
use App\HttpClient\HttpResponse;
use App\HttpClient\HttpServiceProvider;
use App\Mail\Mail;
use App\Mail\Mailer;
use App\Mail\MailMessage;
use App\Mail\MailServiceProvider;
use App\Storage\Storage;
use App\Storage\StorageManager;
use App\Storage\StorageServiceProvider;
use App\Translation\Translation;
use App\Translation\TranslationManager;
use App\Translation\TranslationServiceProvider;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Optional providers retain their own configuration and data across Applications. */
final class ProviderApplicationIsolationTest extends TestCase
{
    public function testTwoApplicationsDoNotShareLocaleStorageMailOrCacheState(): void
    {
        $firstRoot = new TemporaryProject();
        $secondRoot = new TemporaryProject();
        try {
            $first = $this->application($firstRoot, 'en', 'first-provider-token');
            $second = $this->application($secondRoot, 'fr', 'second-provider-token');

            $firstTranslation = $first->container()->make(TranslationManager::class);
            $secondTranslation = $second->container()->make(TranslationManager::class);
            self::assertNotSame($firstTranslation, $secondTranslation);
            self::assertSame('en', $firstTranslation->locale());
            self::assertSame('fr', $secondTranslation->locale());
            self::assertSame('One', $firstTranslation->get('messages.label'));
            self::assertSame('Deux', $secondTranslation->get('messages.label'));

            $firstStorage = $first->container()->make(StorageManager::class);
            $secondStorage = $second->container()->make(StorageManager::class);
            $firstStorage->write('shared/name.txt', 'one');
            self::assertFalse($secondStorage->exists('shared/name.txt'));
            $secondStorage->write('shared/name.txt', 'two');
            self::assertSame('one', $firstStorage->read('shared/name.txt'));
            self::assertSame('two', $secondStorage->read('shared/name.txt'));

            $firstCache = $first->container()->make(CacheStore::class);
            $secondCache = $second->container()->make(CacheStore::class);
            $firstCache->store('shared-key', 'one');
            self::assertFalse($secondCache->has('shared-key'));
            $secondCache->store('shared-key', 'two');
            self::assertSame('one', $firstCache->read('shared-key'));
            self::assertSame('two', $secondCache->read('shared-key'));

            $firstHttp = $first->container()->make(HttpClient::class);
            $secondHttp = $second->container()->make(HttpClient::class);
            $firstHttp->fake(['POST https://api.resend.com/emails' =>
                new HttpResponse(200, '{"id":"first-accepted"}')]);
            $secondHttp->fake(['POST https://api.resend.com/emails' =>
                new HttpResponse(200, '{"id":"second-accepted"}')]);
            $message = static fn (): MailMessage => (new MailMessage())
                ->to('recipient@example.test')->subject('Application isolation')->text('Hello');
            $first->container()->make(Mailer::class)->send($message());
            $second->container()->make(Mailer::class)->send($message());
            self::assertCount(1, $firstHttp->captured());
            self::assertCount(1, $secondHttp->captured());
            self::assertSame('Bearer first-provider-token',
                $firstHttp->captured()[0]->headers['authorization']);
            self::assertSame('Bearer second-provider-token',
                $secondHttp->captured()[0]->headers['authorization']);
        } finally {
            Translation::setResolver(null);
            Storage::setResolver(null);
            Cache::setResolver(null);
            Mail::setResolver(null);
            $firstRoot->remove();
            $secondRoot->remove();
        }
    }

    private function application(TemporaryProject $project, string $locale, string $token): Application
    {
        $label = $locale === 'en' ? 'One' : 'Deux';
        $project->write('Config/Translation.php', '<?php return ' . var_export([
            'default' => $locale, 'fallback' => $locale, 'supported' => [$locale],
        ], true) . ';');
        $project->write('Project/Translations/' . $locale . '/messages.json',
            json_encode(['label' => $label], JSON_THROW_ON_ERROR));
        $project->write('Config/Storage.php', '<?php return ' . var_export([
            'default' => 'memory', 'drives' => ['memory' => ['driver' => 'array']],
        ], true) . ';');
        $project->write('Config/Cache.php', '<?php return ' . var_export(['driver' => 'array'], true) . ';');
        $project->write('Config/Mail.php', '<?php return ' . var_export([
            'default' => 'resend', 'from' => ['address' => 'sender@example.test'],
            'transports' => ['resend' => ['driver' => 'resend', 'api_key' => $token]],
        ], true) . ';');
        $app = new Application($project->path());
        $app->register(TranslationServiceProvider::class);
        $app->register(StorageServiceProvider::class);
        $app->register(CacheServiceProvider::class);
        $app->register(HttpServiceProvider::class);
        $app->register(MailServiceProvider::class);
        $app->bootstrap();
        return $app;
    }
}
