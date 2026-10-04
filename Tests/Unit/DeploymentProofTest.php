<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Foundation\Application;
use App\Health\DeploymentProof;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Deployment evidence must not promote configured services to observed delivery. */
final class DeploymentProofTest extends TestCase
{
    /** @var list<TemporaryProject> */
    private array $projects = [];

    protected function tearDown(): void
    {
        foreach ($this->projects as $project) {
            $project->remove();
        }
    }

    private function app(string $queue = 'sync', string $mount = ''): Application
    {
        $project = new TemporaryProject();
        $this->projects[] = $project;
        $project->write('.env', "APP_ENV=testing\nAPP_KEY=base64:deployment-test-key\n");
        $project->write('composer.json', '{"require":{"php":"^8.2"}}');
        $project->write('public/index.php', '<?php');
        $project->write('squehub', '<?php');
        $project->write('Config/App.php', '<?php return ["env" => "testing", "debug" => false];');
        $project->write('Config/Http.php', '<?php return ["base_path" => ' . var_export($mount, true) . '];');
        $project->write('Config/Crypt.php', '<?php return ["current" => "primary", '
            . '"keys" => ["primary" => "base64:deployment-test-key"]];');
        $project->write('Config/Database.php', '<?php return ["default" => "sqlite", '
            . '"connections" => ["sqlite" => ["driver" => "sqlite", "database" => ":memory:"]]];');
        $project->write('Config/Session.php', '<?php return ["driver" => "native"];');
        $project->write('Config/Storage.php', '<?php return ["default" => "local", '
            . '"drives" => ["local" => ["driver" => "local"]]];');
        $project->write('Config/Queue.php', '<?php return ["default" => ' . var_export($queue, true)
            . ', "connections" => ["sync" => ["driver" => "sync"], '
            . '"database" => ["driver" => "database"], '
            . '"auto" => ["driver" => "auto"]]];');
        $app = new Application($project->path());
        $app->inspectPackagesOnly();
        $app->bootstrap();
        return $app;
    }

    public function testSharedHostingCanBeConfiguredWithoutRedisOrWorker(): void
    {
        $evidence = (new DeploymentProof($this->app()))->inspect('shared-hosting');
        self::assertSame('configured', $evidence->level());
        self::assertFalse($evidence->hasFailedObservation());
        self::assertTrue($evidence->checks['database']['configured']);
        self::assertNull($evidence->checks['database']['reachable']);
        self::assertArrayNotHasKey('queue', $evidence->checks);
        self::assertArrayNotHasKey('worker_running', $evidence->checks);
        self::assertNull($evidence->checks['web']['end_to_end_verified']);
    }

    public function testWorkerAndMultiServerKeepExternalEvidenceUnknown(): void
    {
        $sync = (new DeploymentProof($this->app()))->inspect('worker');
        self::assertSame('not_ready', $sync->level());
        self::assertFalse($sync->checks['queue']['configured']);
        $persistent = (new DeploymentProof($this->app('database')))->inspect('worker');
        self::assertSame('configured', $persistent->level());
        self::assertNull($persistent->checks['worker_running']['reachable']);
        $automatic = (new DeploymentProof($this->app('auto')))->inspect('worker');
        self::assertFalse($automatic->checks['queue']['configured'],
            'An auto driver may select a nonpersistent fallback.');
        $distributed = (new DeploymentProof($this->app('database')))->inspect('multi-server');
        self::assertFalse($distributed->checks['shared_session']['configured']);
        self::assertNull($distributed->checks['key_consistency']['reachable']);
        self::assertNull($distributed->checks['deployment_version']['end_to_end_verified']);
        self::assertFalse($distributed->checks['shared_storage']['configured']);
    }

    public function testExplicitHttpProofChecksBoundedPathsAndBaseMount(): void
    {
        $seen = [];
        $probe = static function (string $url, bool $json) use (&$seen): array {
            $seen[] = [$url, $json];
            $path = (string) parse_url($url, PHP_URL_PATH);
            $response = match ($path) {
                '/shop/' => ['status' => 200, 'content_type' => 'text/html'],
                '/shop/assets/default/favicon/site.webmanifest' => ['status' => 200, 'content_type' => 'application/manifest+json'],
                '/shop/squehub-phase21-missing' => ['status' => 404, 'content_type' => 'text/html'],
                '/shop/api/squehub-phase21-missing' => ['status' => 404, 'content_type' => $json ? 'application/json' : 'text/html'],
                '/shop/.env' => ['status' => 404, 'content_type' => 'text/html'],
                '/shop/squehub' => ['status' => 404, 'content_type' => 'text/html'],
                '/shop/App/Core/View.php' => ['status' => 404, 'content_type' => 'text/html'],
                default => ['status' => 0, 'content_type' => ''],
            };
            return $response + ['safe_prefix' => true];
        };
        $proof = new DeploymentProof($this->app(mount: '/shop'), $probe);
        $evidence = $proof->inspect('single-server', webBaseUrl: 'https://example.test/shop');
        self::assertTrue($evidence->checks['web']['reachable']);
        self::assertTrue($evidence->checks['web']['end_to_end_verified']);
        self::assertFalse($evidence->hasFailedObservation());
        self::assertCount(7, $seen);
        self::assertSame(['https://example.test/shop/api/squehub-phase21-missing', true], $seen[3]);
        self::assertSame('configured', $evidence->level(),
            'A single-server web deployment does not require a worker.');
    }

    public function testHttpProofRejectsMismatchAndCredentialsAndNeverLeaksProbeDetails(): void
    {
        $proof = new DeploymentProof($this->app(mount: '/shop'),
            static fn (string $_url, bool $_json): array => throw new \RuntimeException('SQUEHUB_DEPLOYMENT_SECRET'));
        foreach (['https://example.test/', 'https://user@example.test/shop',
            'https://example.test/shop?token=SQUEHUB_DEPLOYMENT_SECRET'] as $url) {
            try {
                $proof->inspect('shared-hosting', webBaseUrl: $url);
                self::fail('Unsafe or mismatched web URL was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringNotContainsString('SQUEHUB_DEPLOYMENT_SECRET', $exception->getMessage());
            }
        }
        $evidence = $proof->inspect('shared-hosting', webBaseUrl: 'https://example.test/shop');
        self::assertFalse($evidence->checks['web']['reachable']);
        self::assertTrue($evidence->hasFailedObservation());
        self::assertStringNotContainsString('SQUEHUB_DEPLOYMENT_SECRET',
            json_encode($evidence->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testHttpProofDoesNotCertifyAnUnsafeErrorPage(): void
    {
        $proof = new DeploymentProof($this->app(), static function (string $url, bool $_json): array {
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (str_contains($path, 'missing')) {
                return ['status' => 404, 'content_type' => str_contains($path, '/api/')
                    ? 'application/json' : 'text/html', 'safe_prefix' => false];
            }
            if ($path === '/.env') {
                return ['status' => 404, 'content_type' => 'text/html', 'safe_prefix' => true];
            }
            return ['status' => 200, 'content_type' => $path === '/' ? 'text/html' : 'text/css',
                'safe_prefix' => true];
        });
        $evidence = $proof->inspect('shared-hosting', webBaseUrl: 'https://example.test');
        self::assertTrue($evidence->checks['web']['reachable']);
        self::assertFalse($evidence->checks['web']['end_to_end_verified']);
        self::assertTrue($evidence->hasFailedObservation());
    }

    public function testHttpProofRejectsSourceExposureAndUnsafeSuccessfulResponses(): void
    {
        foreach (['/', '/assets/default/favicon/site.webmanifest',
            '/squehub', '/App/Core/View.php'] as $unsafePath) {
            $proof = new DeploymentProof($this->app(),
                static function (string $url, bool $json) use ($unsafePath): array {
                    $path = (string) parse_url($url, PHP_URL_PATH);
                    $status = match ($path) {
                        '/', '/assets/default/favicon/site.webmanifest' => 200,
                        default => 404,
                    };
                    if ($path === $unsafePath && ($path === '/squehub'
                        || $path === '/App/Core/View.php')) {
                        $status = 200;
                    }
                    return ['status' => $status,
                        'content_type' => str_starts_with($path, '/assets/') ? 'text/css'
                            : ($json ? 'application/json' : 'text/html'),
                        'safe_prefix' => !in_array($unsafePath,
                            ['/', '/assets/default/favicon/site.webmanifest'], true)
                            || $path !== $unsafePath];
                });
            $evidence = $proof->inspect('shared-hosting', webBaseUrl: 'https://example.test');
            self::assertFalse($evidence->checks['web']['end_to_end_verified'], $unsafePath);
            self::assertTrue($evidence->hasFailedObservation(), $unsafePath);
        }
    }

    public function testEvidenceFreshnessIsBounded(): void
    {
        $at = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $evidence = (new DeploymentProof($this->app()))->inspect('shared-hosting', at: $at);
        self::assertTrue($evidence->fresh(60, new DateTimeImmutable('2026-01-01T00:01:00+00:00')));
        self::assertFalse($evidence->fresh(60, new DateTimeImmutable('2026-01-01T00:01:01+00:00')));
        self::assertFalse($evidence->fresh(60, new DateTimeImmutable('2025-12-31T23:59:59+00:00')));
        self::assertSame('2026-01-01T00:00:00Z', $evidence->toArray()['checked_at']);
    }
}
