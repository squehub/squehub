<?php

declare(strict_types=1);

namespace App\Logging;

use App\Container\Container;
use App\Diagnostics\Diagnostics;
use App\Foundation\ServiceProvider;
use App\Logging\Drivers\ArrayLogger;
use App\Logging\Drivers\FileLogger;
use App\Support\SecretRedactor;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/** Builds the configured logger once without opening a file during bootstrap. */
final class LoggingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $container = $this->app->container();
        $container->singleton(SecretRedactor::class);
        $container->singleton(LogContextNormalizer::class);
        $container->singleton(ArrayLogger::class);
        $app = $this->app;
        $container->singleton(Logger::class, static function (Container $container) use ($app): Logger {
            $config = $app->config();
            $driver = $config->get('logging.driver', 'file');
            $level = $config->get('logging.level', 'info');
            $failFast = $config->get('logging.fail_fast', false);
            $path = $config->get('logging.path');
            if (!is_string($level) || !is_bool($failFast) || ($path !== null && !is_string($path))) {
                throw new InvalidArgumentException('Invalid logging configuration.');
            }
            if ($driver === 'array') {
                $sink = $container->make(ArrayLogger::class);
            } elseif ($driver === 'file') {
                $path ??= $app->basePath('Storage/Logs/squehub.log');
                // Use host-native absolute paths. A Windows drive or UNC path
                // cannot safely name a log file on a POSIX host.
                $drivePath = strlen($path) >= 3 && ctype_alpha($path[0])
                    && $path[1] === ':' && ($path[2] === '/' || $path[2] === '\\');
                $uncPath = str_starts_with($path, '\\\\');
                $absolute = str_starts_with($path, '/')
                    || (PHP_OS_FAMILY === 'Windows' && ($drivePath || $uncPath));
                if ($path === '' || !$absolute) {
                    throw new InvalidArgumentException('Logging path must be absolute.');
                }
                $sink = new FileLogger($path);
            } else {
                throw new InvalidArgumentException('Unsupported logging driver.');
            }
            $diagnostics = $container->has(Diagnostics::class)
                ? $container->make(Diagnostics::class) : null;
            return new Logger($sink, $container->make(LogContextNormalizer::class),
                $level, $failFast, $diagnostics);
        });
        $container->singleton(LoggerInterface::class,
            static fn (Container $container): Logger => $container->make(Logger::class));
    }

    public function boot(): void
    {
        $container = $this->app->container();
        $container->make(Logger::class); // Validate configuration before requests.
        Log::setResolver(static fn (): Logger => $container->make(Logger::class));
    }
}
