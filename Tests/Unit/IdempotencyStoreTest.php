<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Database\Connection;
use App\Database\ConnectionFactory;
use App\Idempotency\IdempotencyException;
use App\Idempotency\IdempotencyStore;
use App\Idempotency\ResponseSnapshot;
use App\Idempotency\Stores\ArrayIdempotencyStore;
use App\Idempotency\Stores\DatabaseIdempotencyStore;
use App\Idempotency\Stores\FileIdempotencyStore;
use App\Idempotency\Stores\RedisIdempotencyStore;
use App\Redis\RedisClient;
use App\Redis\RedisConnection;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Identical ownership, replay, conflict, lease, and retention for local stores. */
final class IdempotencyStoreTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void { $this->project = new TemporaryProject(); }
    protected function tearDown(): void { $this->project->remove(); }

    /** @return array<string, IdempotencyStore> */
    private function stores(): array
    {
        $connection = new Connection('testing', ['driver' => 'sqlite', 'database' => ':memory:'],
            new ConnectionFactory());
        require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_10_02_create_idempotency_records.php';
        (new \CreateIdempotencyRecords())->up($connection->pdo(), $connection->schema());
        return [
            'array' => new ArrayIdempotencyStore(),
            'file' => new FileIdempotencyStore($this->project->path('Storage/Idempotency'), 'unit-test'),
            'database' => new DatabaseIdempotencyStore($connection),
        ];
    }

    public function testOwnershipReplayConflictExpiryAndPrune(): void
    {
        $scope = hash('sha256', 'scope');
        $request = hash('sha256', 'request');
        $other = hash('sha256', 'other');
        $owner = str_repeat('a', 64);
        $second = str_repeat('b', 64);
        foreach ($this->stores() as $name => $store) {
            self::assertSame('claimed', $store->claim($scope, $request, $owner, 1000, 10, 60)->state, $name);
            self::assertSame('in_progress', $store->claim($scope, $request, $second, 1001, 10, 60)->state, $name);
            self::assertSame('conflict', $store->claim($scope, $other, $second, 1001, 10, 60)->state, $name);
            self::assertFalse($store->complete($scope, $second, 1002, new ResponseSnapshot(201, 'bad', null)), $name);
            self::assertSame('claimed', $store->claim($scope, $request, $second, 1010, 10, 60)->state, $name);
            self::assertFalse($store->complete($scope, $owner, 1011, null), $name);
            self::assertFalse($store->abandon($scope, $owner), $name);
            self::assertTrue($store->complete($scope, $second, 1011,
                new ResponseSnapshot(201, '{"ok":true}', 'application/json')), $name);
            $replay = $store->claim($scope, $request, $owner, 1012, 10, 60);
            self::assertSame('replay', $replay->state, $name);
            self::assertNotNull($replay->snapshot);
            self::assertSame(201, $replay->snapshot->response()->status(), $name);
            self::assertSame('{"ok":true}', $replay->snapshot->response()->content(), $name);
            self::assertSame('conflict', $store->claim($scope, $other, $owner, 1012, 10, 60)->state, $name);
            self::assertSame('claimed', $store->claim($scope, $other, $owner, 1070, 10, 60)->state, $name);
            self::assertTrue($store->complete($scope, $owner, 1071, null), $name);
            self::assertSame('unreplayable', $store->claim($scope, $other, $second, 1072, 10, 60)->state, $name);
            self::assertSame(1, $store->prune(1130), $name);
            self::assertSame('claimed', $store->claim($scope, $request, $second, 1131, 10, 60)->state, $name);
            self::assertTrue($store->abandon($scope, $second), $name);
            self::assertSame('claimed', $store->claim($scope, $other, $owner, 1132, 10, 60)->state, $name);
        }
    }

    public function testFilePersistenceContainsOnlyHashesAndFailsClosedOnCorruption(): void
    {
        $root = $this->project->path('Storage/Idempotency');
        $scope = hash('sha256', 'private-account@example.test');
        $fingerprint = hash('sha256', 'secret-body');
        $owner = str_repeat('c', 64);
        $first = new FileIdempotencyStore($root, 'app');
        self::assertSame('claimed', $first->claim($scope, $fingerprint, $owner, 1000, 10, 60)->state);
        self::assertTrue($first->complete($scope, $owner, 1001,
            new ResponseSnapshot(200, 'safe result', 'text/plain')));
        $second = new FileIdempotencyStore($root, 'app');
        self::assertSame('replay', $second->claim($scope, $fingerprint, str_repeat('d', 64), 1002, 10, 60)->state);
        $path = $root . '/' . hash('sha256', 'app') . '/' . $scope . '.json';
        $text = (string) file_get_contents($path);
        self::assertStringNotContainsString('private-account@example.test', $text);
        self::assertStringNotContainsString('secret-body', $text);
        file_put_contents($path, '{broken');
        $this->expectException(IdempotencyException::class);
        $second->claim($scope, $fingerprint, str_repeat('e', 64), 1003, 10, 60);
    }

    public function testFileMutexCountRemainsBoundedAcrossDistinctScopesAndPrune(): void
    {
        $root = $this->project->path('Storage/Idempotency');
        $namespace = 'bounded-locks';
        $directory = $root . '/' . hash('sha256', $namespace);
        $store = new FileIdempotencyStore($root, $namespace);
        $fingerprint = hash('sha256', 'request');
        $owner = str_repeat('a', 64);

        // Visit every two-hex-character stripe twice with distinct scopes.
        for ($index = 0; $index < 512; ++$index) {
            $scope = sprintf('%02x', $index % 256) . str_pad(dechex($index), 62, '0', STR_PAD_LEFT);
            self::assertSame('claimed', $store->claim($scope, $fingerprint, $owner, 1000, 10, 60)->state);
        }

        self::assertCount(512, glob($directory . '/*.json') ?: []);
        self::assertCount(256, glob($directory . '/*.lock') ?: []);
        self::assertSame(512, $store->prune(1061, 1000));
        self::assertCount(0, glob($directory . '/*.json') ?: []);
        self::assertCount(256, glob($directory . '/*.lock') ?: []);

        $scope = str_repeat('0', 64);
        self::assertSame('claimed', $store->claim($scope, $fingerprint, $owner, 1062, 10, 60)->state);
        self::assertCount(256, glob($directory . '/*.lock') ?: []);
    }

    public function testFilePruneCursorProgressesAcrossInstancesAndChangedDirectory(): void
    {
        $root = $this->project->path('Storage/Idempotency');
        $namespace = 'prune-progress';
        $directory = $root . '/' . hash('sha256', $namespace);
        $store = new FileIdempotencyStore($root, $namespace);
        $fingerprint = hash('sha256', 'request');
        $owner = str_repeat('a', 64);
        $first = str_repeat('0', 64);
        $inserted = str_repeat('4', 64);
        $expired = str_repeat('8', 64);
        $last = str_repeat('f', 64);

        self::assertSame('claimed', $store->claim($first, $fingerprint, $owner, 1000, 10, 60)->state);
        self::assertSame('claimed', $store->claim($expired, $fingerprint, $owner, 900, 10, 60)->state);
        self::assertSame('claimed', $store->claim($last, $fingerprint, $owner, 900, 10, 60)->state);
        self::assertSame(0, $store->prune(1000, 1)); // First filename is still live.
        self::assertSame(1, (new FileIdempotencyStore($root, $namespace))->prune(1000, 1));
        self::assertFileDoesNotExist($directory . '/' . $expired . '.json');

        // Inserting before the saved cursor and deleting its prior target must
        // not prevent a later call in another store instance from finding it.
        self::assertSame('claimed', $store->claim($inserted, $fingerprint, $owner, 900, 10, 60)->state);
        self::assertSame(1, (new FileIdempotencyStore($root, $namespace))->prune(1000, 1));
        self::assertFileDoesNotExist($directory . '/' . $last . '.json');
        self::assertSame(0, (new FileIdempotencyStore($root, $namespace))->prune(1000, 1));
        self::assertSame(1, (new FileIdempotencyStore($root, $namespace))->prune(1000, 1));
        self::assertFileDoesNotExist($directory . '/' . $inserted . '.json');
        self::assertFileExists($directory . '/' . $first . '.json');

        $cursorPath = $directory . '/.prune.cursor';
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\.json\z/D', (string) file_get_contents($cursorPath));
        file_put_contents($cursorPath, 'torn');
        self::assertSame(0, (new FileIdempotencyStore($root, $namespace))->prune(1000, 1));
        self::assertSame('in_progress', $store->claim($first, $fingerprint, str_repeat('b', 64), 1001, 10, 60)->state);
    }

    public function testInvalidLeaseAndScopeAreRejected(): void
    {
        $store = new ArrayIdempotencyStore();
        $this->expectException(IdempotencyException::class);
        $store->claim('bad', hash('sha256', 'request'), str_repeat('a', 64), 1000, 10, 60);
    }

    public function testRedisTransitionsUseOneServerSideScriptPerDecision(): void
    {
        $client = new class implements RedisClient {
            public array $calls = [];
            private array $replies = [['claimed'], 1, 0];
            public function execute(array $arguments): mixed
            {
                $this->calls[] = $arguments;
                return array_shift($this->replies);
            }
            public function close(): void {}
        };
        $connection = new RedisConnection(['prefix' => 'phase24-test:'],
            static fn (): RedisClient => $client);
        $store = new RedisIdempotencyStore($connection, 'private-application');
        $scope = hash('sha256', 'private-user@example.test/key');
        $fingerprint = hash('sha256', 'sensitive body');
        $owner = str_repeat('e', 64);
        self::assertSame('claimed', $store->claim($scope, $fingerprint, $owner, 1000, 10, 60)->state);
        self::assertTrue($store->complete($scope, $owner, 1001, new ResponseSnapshot(200, 'ok', null)));
        self::assertFalse($store->abandon($scope, $owner));
        self::assertCount(3, $client->calls);
        foreach ($client->calls as $arguments) {
            self::assertSame('EVAL', $arguments[0]);
            self::assertSame('1', $arguments[2]);
            self::assertSame('phase24-test:idempotency:' . hash('sha256', 'private-application') . ':' . $scope,
                $arguments[3]);
            self::assertStringNotContainsString('private-user@example.test', json_encode($arguments));
            self::assertStringNotContainsString('sensitive body', json_encode($arguments));
        }
    }
}
