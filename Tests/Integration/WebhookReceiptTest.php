<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Database\DatabaseManager;
use App\Database\Exception\QueryException;
use App\Webhooks\Receipts\ArrayReceiptStore;
use App\Webhooks\Receipts\DatabaseReceiptStore;
use App\Webhooks\WebhookException;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Real isolated SQLite checks for durable and atomic incoming event claims. */
final class WebhookReceiptTest extends TestCase
{
    private TemporaryProject $project;
    private DatabaseManager $databases;
    private DatabaseReceiptStore $store;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for Webhook receipts.');
        }
        $this->project = new TemporaryProject();
        $this->databases = new DatabaseManager(new Repository(['database' => [
            'default' => 'test', 'connections' => ['test' => [
                'driver' => 'sqlite', 'database' => $this->project->path('receipts.sqlite'),
            ]],
        ]]));
        $connection = $this->databases->connection();
        require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_26_create_webhook_receipts.php';
        (new \CreateWebhookReceipts())->up($connection->pdo(), $connection->schema());
        $this->store = new DatabaseReceiptStore($connection);
    }

    protected function tearDown(): void
    {
        if (isset($this->databases)) $this->databases->disconnect();
        if (isset($this->project)) $this->project->remove();
    }

    public function testUniqueClaimAndProcessedReceiptStayDeduplicated(): void
    {
        $id = self::event(1);
        $token = $this->store->claim('payments', $id, 1000, 60);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', (string) $token);
        self::assertNull($this->store->claim('payments', $id, 1001, 60));
        self::assertSame(1, $this->countReceipts());
        $this->store->processed('payments', $id, $token, 1002);
        self::assertNull($this->store->claim('payments', $id, 9999, 60));
        $row = $this->row('payments', $id);
        self::assertSame('processed', $row['state']);
        self::assertNull($row['claim_token']);
        self::assertNull($row['lease_expires_at']);
        self::assertSame('1970-01-01 00:16:42', $row['updated_at']);
        self::assertNotNull($this->store->claim('other', $id, 1000, 60));
        self::assertSame(2, $this->countReceipts());

        $names = array_column($this->databases->raw('PRAGMA table_info(`webhook_receipts`)')->fetchAll(), 'name');
        foreach (['payload', 'signature', 'signing_secret', 'body', 'headers'] as $sensitive) {
            self::assertNotContains($sensitive, $names);
        }
        $indexes = $this->databases->raw('PRAGMA index_list(`webhook_receipts`)')->fetchAll();
        self::assertContains('webhook_receipts_prune', array_column($indexes, 'name'));
        $unique = array_values(array_filter($indexes, static fn (array $index): bool => (int) $index['unique'] === 1));
        self::assertCount(1, $unique);
        self::assertSame(['identity_fingerprint'], array_column($this->databases
            ->raw('PRAGMA index_info(`' . $unique[0]['name'] . '`)')->fetchAll(), 'name'));
        self::assertSame(hash('sha256', 'payments' . "\0" . $id), $row['identity_fingerprint']);
        $caseVariant = 'evt_A' . str_repeat('a', 31);
        self::assertNotNull($this->store->claim('payments', $caseVariant, 1000, 60));
        self::assertNotNull($this->store->claim('payments', strtolower($caseVariant), 1000, 60));
        self::assertNotNull($this->store->claim('Payments', $id, 1000, 60));
        self::assertSame(5, $this->countReceipts());
    }

    public function testFailureAndExpiredLeaseCanBeReclaimedButOldTokenCannotFinish(): void
    {
        $failedId = self::event(2);
        $first = $this->store->claim('payments', $failedId, 1000, 60);
        self::assertNotNull($first);
        $this->store->failed('payments', $failedId, $first, 1001);
        self::assertSame('failed', $this->row('payments', $failedId)['state']);
        $second = $this->store->claim('payments', $failedId, 1001, 60);
        self::assertNotNull($second);
        self::assertNotSame($first, $second);
        $this->assertStaleClaim(fn () => $this->store->processed('payments', $failedId, $first, 1002));
        $this->store->processed('payments', $failedId, $second, 1002);

        $expiredId = self::event(3);
        $old = $this->store->claim('payments', $expiredId, 1000, 60);
        self::assertNotNull($old);
        self::assertNull($this->store->claim('payments', $expiredId, 1059, 60));
        $this->assertStaleClaim(fn () => $this->store->processed('payments', $expiredId, $old, 1060));
        $current = $this->store->claim('payments', $expiredId, 1060, 60);
        self::assertNotNull($current);
        self::assertNotSame($old, $current);
        $this->assertStaleClaim(fn () => $this->store->failed('payments', $expiredId, $old, 1061));
        $this->store->failed('payments', $expiredId, $current, 1061);
        self::assertSame('failed', $this->row('payments', $expiredId)['state']);
    }

    public function testPruneOnlyRemovesOldTerminalReceiptsAndDatetimesSurvive2038(): void
    {
        $oldProcessed = self::event(4);
        $oldFailed = self::event(5);
        $active = self::event(6);
        $recent = self::event(7);
        $token = $this->store->claim('payments', $oldProcessed, 1000, 60);
        $this->store->processed('payments', $oldProcessed, $token, 1001);
        $token = $this->store->claim('payments', $oldFailed, 1000, 60);
        $this->store->failed('payments', $oldFailed, $token, 1001);
        $this->store->claim('payments', $active, 1000, 60);
        $token = $this->store->claim('payments', $recent, 1001, 60);
        $this->store->processed('payments', $recent, $token, 1002);

        self::assertSame(2, $this->store->prune(1002));
        self::assertSame(2, $this->countReceipts());
        self::assertSame('processing', $this->row('payments', $active)['state']);
        self::assertSame('processed', $this->row('payments', $recent)['state']);
        self::assertSame(0, $this->store->prune(1002));
        self::assertNotNull($this->store->claim('payments', $oldProcessed, 1003, 60));

        $futureId = self::event(8);
        self::assertNotNull($this->store->claim('payments', $futureId, 2208988800, 60));
        self::assertSame('2040-01-01 00:01:00', $this->row('payments', $futureId)['lease_expires_at']);
    }

    public function testArrayStoreHasMatchingClaimAndPruneSemanticsWithoutSharedState(): void
    {
        $first = new ArrayReceiptStore();
        $other = new ArrayReceiptStore();
        $id = self::event(9);
        $token = $first->claim('payments', $id, 1000, 10);
        self::assertNotNull($token);
        self::assertNull($first->claim('payments', $id, 1009, 10));
        self::assertNotNull($other->claim('payments', $id, 1000, 10));
        $next = $first->claim('payments', $id, 1010, 10);
        self::assertNotNull($next);
        $this->assertStaleClaim(static fn () => $first->processed('payments', $id, $token, 1011));
        $first->failed('payments', $id, $next, 1011);
        self::assertSame(0, $first->prune(1011));
        self::assertSame(1, $first->prune(1012));
        self::assertNotNull($first->claim('payments', $id, 1012, 10));
    }

    public function testInfrastructureFailureIsNotMistakenForDuplicate(): void
    {
        $this->databases->connection()->schema()->drop('webhook_receipts');
        $this->expectException(QueryException::class);
        $this->store->claim('payments', self::event(10), 1000, 60);
    }

    public function testStoresRejectInvalidIdentityAndLeaseWithoutPersistingRows(): void
    {
        foreach ([$this->store, new ArrayReceiptStore()] as $store) {
            foreach ([['', self::event(10), 60], ['payments', '', 60],
                ['payments', "bad\nidentifier", 60], ['payments', self::event(10), 0]]
                as [$source, $eventId, $lease]) {
                try {
                    $store->claim($source, $eventId, 1000, $lease);
                    self::fail('Invalid receipt claim was accepted.');
                } catch (WebhookException $exception) {
                    self::assertContains($exception->getMessage(), [
                        'Webhook receipt identity is invalid.',
                        'Webhook receipt lease is invalid.',
                    ]);
                }
            }
        }
        self::assertSame(0, $this->countReceipts());
    }

    public function testTwoProcessesCannotBothClaimOneEvent(): void
    {
        $root = dirname(__DIR__, 2);
        $barrier = $this->project->path('claim');
        $database = $this->project->path('receipts.sqlite');
        $id = self::event(11);
        $this->project->write('Claim.php', <<<'PHP'
<?php
require $argv[1];
$db = new \App\Database\DatabaseManager(new \App\Config\Repository(['database' => [
    'default' => 'test', 'connections' => ['test' => ['driver' => 'sqlite', 'database' => $argv[2]]],
]]));
$store = new \App\Webhooks\Receipts\DatabaseReceiptStore($db->connection());
file_put_contents($argv[3] . '.' . $argv[4], 'ready');
$deadline = microtime(true) + 10;
while (!is_file($argv[3] . '.go')) {
    if (microtime(true) > $deadline) exit(3);
    usleep(10000);
}
try {
    echo $store->claim('payments', $argv[5], 1000, 60) === null ? 'DUPLICATE' : 'CLAIM';
} catch (Throwable) {
    echo 'ERROR';
    exit(2);
}
PHP);
        $processes = [];
        try {
            foreach (['a', 'b'] as $worker) {
                $process = new Process([PHP_BINARY, $this->project->path('Claim.php'),
                    $root . '/vendor/autoload.php', $database, $barrier, $worker, $id], $root);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            while (!is_file($barrier . '.a') || !is_file($barrier . '.b')) {
                if (microtime(true) > $deadline) self::fail('Receipt claim workers did not reach the barrier.');
                usleep(10000);
            }
            file_put_contents($barrier . '.go', 'go');
            $outputs = [];
            foreach ($processes as $process) {
                $process->wait();
                self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $outputs[] = trim($process->getOutput());
            }
            sort($outputs);
            self::assertSame(['CLAIM', 'DUPLICATE'], $outputs);
            self::assertSame(1, $this->countReceipts());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) $process->stop(1);
            }
        }
    }

    /** @return array<string, mixed> */
    private function row(string $source, string $eventId): array
    {
        return $this->databases->raw('SELECT * FROM `webhook_receipts` '
            . 'WHERE `identity_fingerprint` = ?', [hash('sha256', $source . "\0" . $eventId)])->fetch();
    }

    private function countReceipts(): int
    {
        return (int) $this->databases->raw('SELECT COUNT(*) FROM `webhook_receipts`')->fetchColumn();
    }

    private function assertStaleClaim(callable $operation): void
    {
        try {
            $operation();
            self::fail('A stale receipt claim was accepted.');
        } catch (WebhookException $exception) {
            self::assertSame('Webhook receipt claim is no longer active.', $exception->getMessage());
        }
    }

    private static function event(int $number): string
    {
        return 'evt_' . str_pad((string) $number, 32, 'a');
    }
}
