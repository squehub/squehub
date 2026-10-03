<?php

declare(strict_types=1);

namespace App\Translation;

use App\Container\Container;
use App\Foundation\ServiceProvider;

/** Registers the optional catalog service without reading any catalog at boot. */
final class TranslationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $app = $this->app;
        $app->container()->singleton(TranslationManager::class,
            static function (Container $container) use ($app): TranslationManager {
                $settings = $app->config()->get('translation', []);
                if (!is_array($settings)) {
                    throw new TranslationException('Translation configuration must be a map.');
                }
                return new TranslationManager($app, $settings);
            });
    }

    public function boot(): void
    {
        $container = $this->app->container();
        $container->make(TranslationManager::class);
        Translation::setResolver(static fn (): TranslationManager =>
            $container->make(TranslationManager::class));
    }
}
