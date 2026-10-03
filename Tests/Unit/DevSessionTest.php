<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Cache\CacheServiceProvider;
use App\Database\DatabaseServiceProvider;
use App\Dev\DevException;
use App\Dev\DevSession;
use App\Dev\DevSupervisor;
use App\Foundation\Application;
use App\Foundation\CliBootstrapMode;
use App\Health\HealthManager;
use App\Health\HealthServiceProvider;
use App\Packages\PackageServiceProvider;
use App\Queue\QueueServiceProvider;
use App\RateLimit\RateLimitServiceProvider;
use App\Session\SessionServiceProvider;
use App\Storage\StorageServiceProvider;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Console\Output\BufferedOutput;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Dev preflight is a one-time, read-only gate before any managed child exists. */
final class DevSessionTest extends TestCase
{
    public function testMissingEnvironmentBlocksBeforeServerAndPreservesSource(): void
    {
        $project = new TemporaryProject();
        try {
            $app = $this->application($project, false);
            $project->write('Project/Routes/Web.php', '<?php // preserved route');
            $project->write('Project/Packages/State.json', '{"version":1,"packages":{}}');
            $project->write('Database/Migrations/0000_example.php', '<?php // preserved migration');
            $preserved = [];
            foreach (['Project/Routes/Web.php', 'Project/Packages/State.json',
                'Database/Migrations/0000_example.php'] as $path) {
                $preserved[$path] = hash_file('sha256', $project->path($path));
            }

            $output = new BufferedOutput();
            self::assertSame(1, DevSession::run($app, $output, '127.0.0.1', 'invalid'));
            $text = $output->fetch();
            self::assertStringContainsString('SqueHub Dev', $text);
            self::assertStringContainsString('Application preflight failed', $text);
            self::assertStringContainsString('.env', $text);
            self::assertStringNotContainsString('Server:', $text);
            self::assertFileDoesNotExist($project->path('.env'));
            foreach ($preserved as $path => $digest) {
                self::assertSame($digest, hash_file('sha256', $project->path($path)), $path);
            }
        } finally {
            $project->remove();
        }
    }

    public function testOptionalDoctorWarningDoesNotBlockServerArgumentValidation(): void
    {
        $project = new TemporaryProject();
        try {
            $app = $this->application($project, true);
            self::assertTrue($app->container()->make(HealthManager::class)->ready()->healthy());
            $output = new BufferedOutput();
            try {
                DevSession::run($app, $output, '127.0.0.1', 'invalid');
                self::fail('Invalid port should reach the shared server validation.');
            } catch (DevException $exception) {
                self::assertStringContainsString('Invalid port', $exception->getMessage());
            }
            $text = $output->fetch();
            self::assertStringContainsString('Doctor:', $text);
            self::assertMatchesRegularExpression('/Doctor: \d+ pass, [1-9]\d* warning/', $text);
            self::assertStringContainsString('Readiness: pass.', $text);
            self::assertStringNotContainsString('Application preflight failed', $text);
        } finally {
            $project->remove();
        }
    }

    public function testSyncQueueRequiresNoWorkerAndExplicitSelectionFailsClearly(): void
    {
        $project = new TemporaryProject();
        try {
            $app = $this->application($project, true);
            self::assertTrue($app->container()->make(HealthManager::class)->ready()->healthy());
            $output = new BufferedOutput();
            $this->expectException(DevException::class);
            $this->expectExceptionMessage('sync Queue');
            DevSession::run($app, $output, '127.0.0.1', 'invalid', true);
        } finally {
            $project->remove();
        }
    }

    public function testPersistentQueueWorkerSpecificationUsesApplicationEntryAndArgumentVector(): void
    {
        $project = new TemporaryProject();
        $root = $project->path('app with spaces');
        self::assertTrue(mkdir($root));
        $project->write('app with spaces/squehub', '<?php // test entry');
        try {
            $app = new Application($root);
            $secret = 'SQUEHUB_DEV_SECRET_DO_NOT_LEAK';
            $app->config()->set('queue.test_secret', $secret);
            $worker = DevSession::queueProcess($app);
            self::assertSame([PHP_BINARY, $app->basePath('squehub'), 'queue:work'],
                $worker->command());
            self::assertSame($app->basePath(), $worker->workingDirectory());
            self::assertStringNotContainsString($secret,
                json_encode($worker->command(), JSON_THROW_ON_ERROR));
            self::assertNotContains('cmd.exe', $worker->command());
            self::assertNotContains('sh', $worker->command());
        } finally {
            $project->remove();
        }
    }

    public function testDevAndStartUseNormalPackageBootMode(): void
    {
        foreach (['dev' => true, 'start' => true, 'doctor' => false] as $command => $activatesPackages) {
            $project = new TemporaryProject();
            try {
                $project->write('Project/Routes/Web.php', '<?php // preserved route');
                $sourceDigest = hash_file('sha256', $project->path('Project/Routes/Web.php'));
                $app = new Application($project->path());

                CliBootstrapMode::configure($app, ['squehub', $command]);
                $app->bootstrap();

                self::assertSame($activatesPackages,
                    $app->hasProvider(PackageServiceProvider::class), $command);
                self::assertSame($activatesPackages,
                    $app->isProviderBooted(PackageServiceProvider::class), $command);
                self::assertSame($sourceDigest,
                    hash_file('sha256', $project->path('Project/Routes/Web.php')), $command);
                self::assertFileDoesNotExist($project->path('Project/Activation.json'));
            } finally {
                $project->remove();
            }
        }
    }

    public function testDefaultSessionStartsOnlyPhpServerAndStopsWithoutChangingSource(): void
    {
        $project = new TemporaryProject();
        try {
            $app = $this->application($project, true);
            $project->write('public/index.php', '<?php echo "unused";');
            $project->write('Bootstrap/DevelopmentServer.php', '<?php return false;');
            $project->write('Project/Routes/Web.php', '<?php // preserved');
            $source = [];
            foreach (['.env', 'public/index.php', 'Bootstrap/DevelopmentServer.php',
                'Project/Routes/Web.php'] as $path) {
                $source[$path] = hash_file('sha256', $project->path($path));
            }
            self::assertFileDoesNotExist($project->path('Project/Activation.json'));
            $reservation = @stream_socket_server('tcp://127.0.0.1:0', $number, $message);
            if ($reservation === false) {
                self::markTestSkipped('Loopback socket is required for SqueHub Dev startup qualification.');
            }
            $address = stream_socket_get_name($reservation, false);
            fclose($reservation);
            $port = (int) substr($address, strrpos($address, ':') + 1);
            $supervisor = new DevSupervisor();
            $output = new BufferedOutput();
            $accepted = false;
            $deadline = microtime(true) + 5.0;
            $exit = DevSession::run($app, $output, '127.0.0.1', (string) $port,
                false, $supervisor, static function () use ($supervisor, $port, &$accepted, $deadline): void {
                    $probe = @stream_socket_client('tcp://127.0.0.1:' . $port,
                        $number, $message, 0.1);
                    if ($probe !== false) {
                        fclose($probe);
                        $accepted = true;
                        $supervisor->stop();
                        return;
                    }
                    if (microtime(true) > $deadline) {
                        throw new \RuntimeException('SqueHub Dev PHP server did not bind in time.');
                    }
                });
            $text = $output->fetch();
            self::assertSame(0, $exit, $text);
            self::assertTrue($accepted, 'The PHP child must accept a TCP connection.');
            self::assertStringContainsString('SqueHub Dev', $text);
            self::assertStringContainsString('Readiness: pass.', $text);
            self::assertStringContainsString('Server: http://127.0.0.1:' . $port, $text);
            self::assertStringContainsString('Queue: not started', $text);
            self::assertStringContainsString('Scheduler: not started', $text);
            foreach ($source as $path => $digest) {
                self::assertSame($digest, hash_file('sha256', $project->path($path)), $path);
            }
            self::assertFileDoesNotExist($project->path('Project/Activation.json'));
            self::assertDirectoryDoesNotExist($project->path('Database/Migrations'));
            $after = @stream_socket_client('tcp://127.0.0.1:' . $port,
                $number, $message, 0.1);
            if ($after !== false) fclose($after);
            self::assertFalse($after, 'Dev must stop its PHP server child before returning.');
        } finally {
            $project->remove();
        }
    }

    private function application(TemporaryProject $project, bool $configured): Application
    {
        if ($configured) $project->write('.env', "APP_ENV=testing\nAPP_NAME=DevTest\n");
        $project->write('composer.json', '{"require":{"php":"^8.2"}}');
        $project->write('Config/App.php', '<?php return ["env"=>"testing","debug"=>false];');
        $project->write('Config/Database.php', '<?php return ["default"=>"sqlite","connections"=>'
            . '["sqlite"=>["driver"=>"sqlite","database"=>":memory:"]]];');
        $project->write('Config/Cache.php', '<?php return ["driver"=>"array"];');
        $project->write('Config/Session.php', '<?php return ["driver"=>"array"];');
        $project->write('Config/RateLimit.php', '<?php return ["store"=>"array","prefix"=>"dev-test"];');
        $project->write('Config/Queue.php', '<?php return ["default"=>"sync","connections"=>'
            . '["sync"=>["driver"=>"sync"]]];');
        $project->write('Config/Storage.php', '<?php return ["default"=>"memory","drives"=>'
            . '["memory"=>["driver"=>"array"]]];');
        $project->write('Config/Mail.php', '<?php return ["default"=>"smtp","transports"=>'
            . '["smtp"=>["driver"=>"smtp","host"=>""]],'
            . '"from"=>["address"=>"dev@example.test"]];');
        $app = new Application($project->path());
        foreach ([DatabaseServiceProvider::class, CacheServiceProvider::class,
            SessionServiceProvider::class, RateLimitServiceProvider::class,
            QueueServiceProvider::class, StorageServiceProvider::class,
            HealthServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        $app->bootstrap();
        return $app;
    }
}
