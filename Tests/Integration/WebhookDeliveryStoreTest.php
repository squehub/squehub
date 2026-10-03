<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Database\DatabaseManager;
use App\Database\Exception\QueryException;
use App\Webhooks\Deliveries\ArrayDeliveryStore;
use App\Webhooks\Deliveries\DatabaseDeliveryStore;
use App\Webhooks\WebhookDeliveryResult;
use App\Webhooks\WebhookException;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** SQLite-backed delivery metadata, retry state, retention, and privacy. */
final class WebhookDeliveryStoreTest extends TestCase
{
    private TemporaryProject $project;
    private DatabaseManager $databases;
    private DatabaseDeliveryStore $store;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for Webhook delivery metadata.');
        }
        $this->project = new TemporaryProject();
        $this->databases = new DatabaseManager(new Repository(['database' => [
            'default' => 'test', 'connections' => ['test' => [
                'driver' => 'sqlite', 'database' => $this->project->path('deliveries.sqlite'),
            ]],
        ]]));
        $connection = $this->databases->connection();
        require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_26_create_webhook_deliveries.php';
        (new \CreateWebhookDeliveries())->up($connection->pdo(), $connection->schema());
        $this->store = new DatabaseDeliveryStore($connection);
    }

    protected function tearDown(): void
    {
        if (isset($this->databases)) $this->databases->disconnect();
        if (isset($this->project)) $this->project->remove();
    }

    public function testSuccessfulDeliveryTracksOnlySafeMetadataAndTerminalReplaySkipsSend(): void
    {
        $event = self::event(1);
        $delivery = self::delivery(1);
        self::assertTrue($this->store->begin($event, $delivery, 'billing', 1000));
        self::assertTrue($this->store->begin($event, $delivery, 'billing', 1000));
        self::assertSame(1, $this->countDeliveries());
        $this->store->record($delivery,
            new WebhookDeliveryResult($event, $delivery, 202, true, 2, null, false), 1001);
        self::assertFalse($this->store->begin($event, $delivery, 'billing', 1002));
        $row = $this->row($delivery);
        self::assertSame('succeeded', $row['state']);
        self::assertSame(2, (int) $row['attempt_count']);
        self::assertSame(202, (int) $row['last_http_status']);
        self::assertNull($row['last_failure_category']);
        self::assertNull($row['next_attempt_at']);
        self::assertSame('1970-01-01 00:16:41', $row['completed_at']);
        self::assertSame(hash('sha256', $delivery), $row['delivery_fingerprint']);

        $names = array_column($this->databases->raw('PRAGMA table_info(`webhook_deliveries`)')->fetchAll(), 'name');
        foreach (['body', 'payload', 'url', 'secret', 'signature', 'authorization', 'response_body'] as $sensitive) {
            self::assertNotContains($sensitive, $names);
        }
        $indexes = $this->databases->raw('PRAGMA index_list(`webhook_deliveries`)')->fetchAll();
        $unique = array_values(array_filter($indexes, static fn (array $index): bool => (int) $index['unique'] === 1));
        self::assertCount(1, $unique);
        self::assertSame(['delivery_fingerprint'], array_column($this->databases
            ->raw('PRAGMA index_info(`' . $unique[0]['name'] . '`)')->fetchAll(), 'name'));
    }

    public function testQueueRetryMayHaveUnknownScheduleAndAccumulatesAttempts(): void
    {
        $event = self::event(2);
        $delivery = self::delivery(2);
        $this->store->begin($event, $delivery, 'billing', 1000);
        $this->store->record($delivery,
            new WebhookDeliveryResult($event, $delivery, 503, false, 3, 'http_status', true),
            1001, mayRetry: true);
        $row = $this->row($delivery);
        self::assertSame('retrying', $row['state']);
        self::assertSame(3, (int) $row['attempt_count']);
        self::assertNull($row['next_attempt_at']);
        self::assertNull($row['completed_at']);
        self::assertTrue($this->store->begin($event, $delivery, 'billing', 1010));
        $this->store->record($delivery,
            new WebhookDeliveryResult($event, $delivery, 200, true, 1, null, false), 1011);
        self::assertFalse($this->store->begin($event, $delivery, 'billing', 1012));
        self::assertSame(4, (int) $this->row($delivery)['attempt_count']);
        self::assertSame('succeeded', $this->row($delivery)['state']);
    }

    public function testDirectFailureIsTerminalAndExplicitRetryTimeIsMetadataOnly(): void
    {
        $event = self::event(3);
        $terminal = self::delivery(3);
        $this->store->begin($event, $terminal, 'billing', 1000);
        $this->store->record($terminal,
            new WebhookDeliveryResult($event, $terminal, 503, false, 1, 'http_status', true), 1001);
        self::assertSame('failed', $this->row($terminal)['state']);
        self::assertFalse($this->store->begin($event, $terminal, 'billing', 1002));

        $retrying = self::delivery(4);
        $this->store->begin($event, $retrying, 'billing', 1000);
        $this->store->record($retrying,
            new WebhookDeliveryResult($event, $retrying, null, false, 1, 'timeout', true),
            1001, mayRetry: true, nextAttemptAt: 1061);
        self::assertSame('1970-01-01 00:17:41', $this->row($retrying)['next_attempt_at']);
        self::assertSame('retrying', $this->row($retrying)['state']);
        // This store does not run scheduled work; Queue remains the authority.
        self::assertTrue($this->store->begin($event, $retrying, 'billing', 1002));
    }

    public function testIdentityMismatchAndInvalidResultFailWithoutChangingRow(): void
    {
        $event = self::event(5);
        $delivery = self::delivery(5);
        $this->store->begin($event, $delivery, 'billing', 1000);
        $this->assertWebhookFailure(fn () => $this->store->begin(self::event(6), $delivery, 'billing', 1000));
        $this->assertWebhookFailure(fn () => $this->store->begin($event, $delivery, 'other', 1000));
        $this->assertWebhookFailure(fn () => $this->store->record($delivery,
            new WebhookDeliveryResult(self::event(6), $delivery, 200, true, 1, null, false), 1001));
        $this->assertWebhookFailure(fn () => $this->store->record($delivery,
            new WebhookDeliveryResult($event, $delivery, 503, false, 1, 'http_status', true),
            1001, nextAttemptAt: 1061));
        self::assertSame('pending', $this->row($delivery)['state']);
        self::assertSame(0, (int) $this->row($delivery)['attempt_count']);
    }

    public function testPruningPreservesPendingRetryingAndCutoffBoundary(): void
    {
        $event = self::event(7);
        $succeeded = self::delivery(7);
        $failed = self::delivery(8);
        $pending = self::delivery(9);
        $retrying = self::delivery(10);
        $recent = self::delivery(11);
        foreach ([$succeeded, $failed, $pending, $retrying, $recent] as $delivery) {
            $this->store->begin($event, $delivery, 'billing', 1000);
        }
        $this->store->record($succeeded,
            new WebhookDeliveryResult($event, $succeeded, 200, true, 1, null, false), 1001);
        $this->store->record($failed,
            new WebhookDeliveryResult($event, $failed, 400, false, 1, 'http_status', false), 1001);
        $this->store->record($retrying,
            new WebhookDeliveryResult($event, $retrying, 503, false, 1, 'http_status', true),
            1001, mayRetry: true);
        $this->store->record($recent,
            new WebhookDeliveryResult($event, $recent, 201, true, 1, null, false), 1002);
        self::assertSame(2, $this->store->prune(1002));
        self::assertSame(3, $this->countDeliveries());
        self::assertSame(0, $this->store->prune(1002));
        self::assertSame('pending', $this->row($pending)['state']);
        self::assertSame('retrying', $this->row($retrying)['state']);
        self::assertSame('succeeded', $this->row($recent)['state']);
    }

    public function testCaseVariantsAndArrayStoresRemainIndependent(): void
    {
        $event = self::event(12);
        $upper = 'whd_A' . str_repeat('a', 31);
        $lower = strtolower($upper);
        self::assertTrue($this->store->begin($event, $upper, 'billing', 1000));
        self::assertTrue($this->store->begin($event, $lower, 'billing', 1000));
        self::assertSame(2, $this->countDeliveries());

        $a = new ArrayDeliveryStore();
        $b = new ArrayDeliveryStore();
        self::assertTrue($a->begin($event, $upper, 'billing', 1000));
        self::assertTrue($b->begin($event, $upper, 'billing', 1000));
        self::assertTrue($a->begin($event, $upper, 'billing', 1000));
        $a->record($upper,
            new WebhookDeliveryResult($event, $upper, 200, true, 1, null, false), 1001);
        self::assertFalse($a->begin($event, $upper, 'billing', 1002));
        self::assertTrue($b->begin($event, $upper, 'billing', 1002));
        self::assertSame(1, $a->prune(1002));
        self::assertTrue($a->begin($event, $upper, 'billing', 1002));
    }

    public function testBackendFailureIsNotAConflictingDelivery(): void
    {
        $this->databases->connection()->schema()->drop('webhook_deliveries');
        $this->expectException(QueryException::class);
        $this->store->begin(self::event(13), self::delivery(13), 'billing', 1000);
    }

    private function assertWebhookFailure(callable $operation): void
    {
        try {
            $operation();
            self::fail('Invalid delivery operation was accepted.');
        } catch (WebhookException $exception) {
            self::assertStringStartsWith('Webhook delivery', $exception->getMessage());
        }
    }

    /** @return array<string,mixed> */
    private function row(string $deliveryId): array
    {
        return $this->databases->raw('SELECT * FROM `webhook_deliveries` '
            . 'WHERE `delivery_fingerprint` = ?', [hash('sha256', $deliveryId)])->fetch();
    }

    private function countDeliveries(): int
    {
        return (int) $this->databases->raw('SELECT COUNT(*) FROM `webhook_deliveries`')->fetchColumn();
    }

    private static function event(int $number): string
    {
        return 'evt_' . str_pad((string) $number, 32, 'a');
    }

    private static function delivery(int $number): string
    {
        return 'whd_' . str_pad((string) $number, 32, 'b');
    }
}
