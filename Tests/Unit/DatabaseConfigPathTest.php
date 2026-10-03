<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Config\ConfigurationException;
use PHPUnit\Framework\TestCase;

/** Keeps file-backed SQLite configuration stable across CLI working directories. */
final class DatabaseConfigPathTest extends TestCase
{
    public function testRelativeSqliteFileIsAnchoredToApplicationRoot(): void
    {
        $configuration = $this->configuration('Storage/Database.sqlite');
        self::assertSame(dirname(__DIR__, 2) . '/Storage/Database.sqlite',
            $configuration['connections']['sqlite']['database']);
    }

    public function testMemoryAndAbsolutePathsRemainUnchanged(): void
    {
        foreach ([':memory:', '/tmp/squehub.sqlite', 'C:\\Data\\squehub.sqlite'] as $path) {
            self::assertSame($path, $this->configuration($path)['connections']['sqlite']['database']);
        }
    }

    public function testRelativeTraversalIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('SQLite database path is invalid');
        $this->configuration('Storage/../elsewhere.sqlite');
    }

    /** @return array<string,mixed> */
    private function configuration(string $sqlitePath): array
    {
        $environment = new class($sqlitePath) {
            public function __construct(private string $sqlitePath)
            {
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $key === 'DB_SQLITE_DATABASE' ? $this->sqlitePath : $default;
            }
        };

        return require dirname(__DIR__, 2) . '/Config/Database.php';
    }
}
