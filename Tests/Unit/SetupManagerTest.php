<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Changes\ChangeRenderer;
use App\Setup\SetupEnvironmentEditor;
use App\Setup\SetupException;
use App\Setup\SetupManager;
use App\Support\SecureRandom;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Exercises content-free setup plans against isolated application roots. */
final class SetupManagerTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($root);
        }
        parent::tearDown();
    }

    public function testFreshPlanRequiresChoicesAndOnlyApplyCreatesKeyEnvironmentAndSqliteFile(): void
    {
        $root = $this->root();
        file_put_contents($root . '/.example.env', "APP_ENV=development\nAPP_DEBUG=true\nDB_CONNECTION=mysql\nAPP_KEY=\n");
        $manager = new SetupManager($root);

        self::assertSame('missing', $manager->inspect()['environment_file']);
        self::assertSame('missing', $manager->inspect()['environment']);
        self::assertSame('missing', $manager->inspect()['database']);
        try {
            $manager->plan();
            self::fail('An absent environment must require explicit choices.');
        } catch (SetupException $exception) {
            self::assertStringContainsString('Choose an environment and database', $exception->getMessage());
        }

        $plan = $manager->plan('local', 'sqlite');
        $rendered = ChangeRenderer::render($plan);
        self::assertCount(2, $plan->actions);
        self::assertStringContainsString('Storage/Database.sqlite', $rendered);
        self::assertStringContainsString('.env', $rendered);
        self::assertFileDoesNotExist($root . '/.env');
        self::assertFileDoesNotExist($root . '/Storage/Database.sqlite');
        self::assertStringNotContainsString('base64:', json_encode($plan->toArray(), JSON_THROW_ON_ERROR));

        $result = $manager->apply($plan);
        self::assertTrue($result->complete());
        self::assertFileExists($root . '/Storage/Database.sqlite');
        $values = SetupEnvironmentEditor::parse((string) file_get_contents($root . '/.env'));
        self::assertSame('local', $values['APP_ENV']);
        self::assertSame('sqlite', $values['DB_CONNECTION']);
        self::assertSame('Storage/Database.sqlite', $values['DB_SQLITE_DATABASE']);
        self::assertMatchesRegularExpression('~\Abase64:[A-Za-z0-9+/]{43}=\z~D', (string) $values['APP_KEY']);
        self::assertStringNotContainsString((string) $values['APP_KEY'],
            json_encode($plan->toArray(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString((string) $values['APP_KEY'],
            ChangeRenderer::render($plan));
        self::assertSame('configured', $manager->inspect()['app_key']);
        self::assertSame([], $manager->plan()->actions);
    }

    public function testFreshMysqlPlanKeepsTemplateCredentialsOutsidePlanAndCreatesNoDatabase(): void
    {
        $root = $this->root();
        $secret = 'SQUEHUB_PRIVATE_DB_PASSWORD';
        file_put_contents($root . '/.example.env', "APP_ENV=development\nDB_CONNECTION=mysql\n"
            . "DB_HOST=localhost\nDB_USER=root\nDB_PASSWORD={$secret}\nAPP_KEY=\n");
        $manager = new SetupManager($root);
        $plan = $manager->plan('staging', 'mysql');

        self::assertCount(1, $plan->actions);
        self::assertSame('.env', $plan->actions[0]->subject);
        self::assertStringNotContainsString($secret, json_encode($plan->toArray(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($secret, ChangeRenderer::render($plan));
        self::assertTrue($manager->apply($plan)->complete());
        self::assertFileDoesNotExist($root . '/Storage/Database.sqlite');
        $values = SetupEnvironmentEditor::parse((string) file_get_contents($root . '/.env'));
        self::assertSame($secret, $values['DB_PASSWORD']);
        self::assertSame('staging', $values['APP_ENV']);
    }

    public function testAbsentStorageIsReviewableAndCreatedOnlyWhenPlanIsApplied(): void
    {
        $root = $this->root(false);
        file_put_contents($root . '/.example.env', "APP_ENV=development\nDB_CONNECTION=mysql\nAPP_KEY=\n");
        $manager = new SetupManager($root);
        self::assertSame('missing', $manager->inspect()['storage']);

        $plan = $manager->plan('local', 'sqlite');
        self::assertSame(['Storage', 'Storage/Database.sqlite', '.env'],
            array_map(static fn ($action): string => $action->subject, $plan->actions));
        self::assertDirectoryDoesNotExist($root . '/Storage');
        self::assertTrue($manager->apply($plan)->complete());
        self::assertDirectoryExists($root . '/Storage');
        self::assertFileExists($root . '/Storage/Database.sqlite');
        self::assertSame('writable', $manager->inspect()['storage']);
        self::assertSame([], $manager->plan()->actions);
    }

    public function testExistingEnvironmentKeepsKeySecretsUnknownKeysAndComments(): void
    {
        $root = $this->root();
        $key = SecureRandom::applicationKey();
        $password = 'with spaces # = "quotes" \\ slashes';
        $source = "# Application choice\r\nAPP_ENV=local\r\nAPP_DEBUG=true\r\nDB_CONNECTION=mysql\r\n"
            . 'DB_PASSWORD="with spaces # = \\"quotes\\" \\\\ slashes"' . "\r\n"
            . "CUSTOM_SETTING=keep-me\r\nAPP_KEY={$key}\r\n";
        file_put_contents($root . '/.env', $source);
        $manager = new SetupManager($root);

        $plan = $manager->plan('production');
        self::assertCount(1, $plan->actions);
        $serialized = json_encode($plan->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($key, $serialized);
        self::assertStringNotContainsString($password, $serialized);
        self::assertStringNotContainsString($key, ChangeRenderer::render($plan));
        self::assertTrue($manager->apply($plan)->complete());

        $updated = (string) file_get_contents($root . '/.env');
        $values = SetupEnvironmentEditor::parse($updated);
        self::assertSame($key, $values['APP_KEY']);
        self::assertSame('production', $values['APP_ENV']);
        self::assertSame('false', $values['APP_DEBUG']);
        self::assertSame('mysql', $values['DB_CONNECTION']);
        self::assertSame($password, $values['DB_PASSWORD']);
        self::assertStringContainsString("# Application choice\r\n", $updated);
        self::assertStringContainsString("CUSTOM_SETTING=keep-me\r\n", $updated);
        self::assertSame([], $manager->plan()->actions);
    }

    public function testChangedSourceInvalidatesPlanBeforeAnyTargetMutation(): void
    {
        $root = $this->root();
        file_put_contents($root . '/.example.env', "APP_ENV=development\nDB_CONNECTION=mysql\nAPP_KEY=\n");
        $manager = new SetupManager($root);
        $plan = $manager->plan('local', 'sqlite');
        file_put_contents($root . '/.example.env', "APP_ENV=development\nDB_CONNECTION=mysql\nAPP_KEY=\n# changed\n");

        $this->expectException(SetupException::class);
        $this->expectExceptionMessage('stale');
        try {
            $manager->apply($plan);
        } finally {
            self::assertFileDoesNotExist($root . '/.env');
            self::assertFileDoesNotExist($root . '/Storage/Database.sqlite');
        }
    }

    public function testExistingEnvironmentChangeInvalidatesOldPlanWithoutOverwritingDeveloperEdit(): void
    {
        $root = $this->root();
        $key = SecureRandom::applicationKey();
        $original = "APP_ENV=local\nAPP_DEBUG=true\nDB_CONNECTION=mysql\nAPP_KEY={$key}\n";
        file_put_contents($root . '/.env', $original);
        $manager = new SetupManager($root);
        $plan = $manager->plan('production');
        $new = $original . "CUSTOM_SETTING=recent\n";
        file_put_contents($root . '/.env', $new);

        $this->expectException(SetupException::class);
        $this->expectExceptionMessage('stale');
        try {
            $manager->apply($plan);
        } finally {
            self::assertSame($new, file_get_contents($root . '/.env'));
        }
    }

    public function testEditorRejectsInvalidSourceAndPreservesSpecialValuesAndInlineComments(): void
    {
        $password = 's p # = "quoted" \\';
        $source = 'DB_PASSWORD="s p # = \\"quoted\\" \\\\"' . "\n"
            . 'APP_ENV=local # environment comment' . "\n"
            . "CUSTOM=value\n";
        $updated = SetupEnvironmentEditor::update($source, ['APP_ENV' => 'production']);
        $values = SetupEnvironmentEditor::parse($updated);
        self::assertSame($password, $values['DB_PASSWORD']);
        self::assertSame('production', $values['APP_ENV']);
        self::assertStringContainsString('# environment comment', $updated);
        self::assertStringContainsString("CUSTOM=value\n", $updated);

        $this->expectException(SetupException::class);
        $this->expectExceptionMessage('invalid');
        SetupEnvironmentEditor::update("APP_ENV=\"unterminated\n", ['APP_ENV' => 'production']);
    }

    public function testMissingTemplateAndInvalidExistingKeyAreSafeFailures(): void
    {
        $root = $this->root();
        $manager = new SetupManager($root);
        try {
            $manager->plan('local', 'sqlite');
            self::fail('Missing template should fail.');
        } catch (SetupException $exception) {
            self::assertStringContainsString('.example.env', $exception->getMessage());
        }

        file_put_contents($root . '/.env', "APP_ENV=local\nDB_CONNECTION=mysql\nAPP_KEY=PRIVATE_BAD_KEY\n");
        self::assertSame('invalid', $manager->inspect()['app_key']);
        $this->expectException(SetupException::class);
        $this->expectExceptionMessage('Existing APP_KEY is invalid');
        $manager->plan();
    }

    public function testInvalidDebugAndStorageFileFailWithoutMutatingEnvironment(): void
    {
        $root = $this->root(false);
        $key = SecureRandom::applicationKey();
        $source = "APP_ENV=local\nAPP_DEBUG=perhaps\nDB_CONNECTION=mysql\nAPP_KEY={$key}\n";
        file_put_contents($root . '/.env', $source);
        file_put_contents($root . '/Storage', 'not a directory');
        $manager = new SetupManager($root);
        self::assertSame('invalid', $manager->inspect()['debug']);
        self::assertSame('unwritable', $manager->inspect()['storage']);
        try {
            $manager->plan();
            self::fail('Storage file must be rejected.');
        } catch (SetupException $exception) {
            self::assertStringContainsString('Storage directory', $exception->getMessage());
        }
        unlink($root . '/Storage');
        try {
            $manager->plan();
            self::fail('Invalid debug value must be rejected.');
        } catch (SetupException $exception) {
            self::assertStringContainsString('APP_DEBUG is invalid', $exception->getMessage());
        }
        self::assertSame($source, file_get_contents($root . '/.env'));
    }

    public function testDuplicateAssignmentFailsSafelyBeforeEditing(): void
    {
        $this->expectException(SetupException::class);
        $this->expectExceptionMessage('duplicate settings');
        SetupEnvironmentEditor::update("APP_ENV=local\nAPP_ENV=production\n", ['APP_ENV' => 'staging']);
    }

    public function testCustomSqlitePathMustExistToAvoidAnUnplannedDoctorWrite(): void
    {
        $root = $this->root();
        $key = SecureRandom::applicationKey();
        file_put_contents($root . '/.env', "APP_ENV=local\nAPP_DEBUG=false\nDB_CONNECTION=sqlite\n"
            . "DB_SQLITE_DATABASE=Storage/Custom.sqlite\nAPP_KEY={$key}\n");
        $manager = new SetupManager($root);
        try {
            $manager->plan();
            self::fail('A missing custom SQLite path must not be created by Doctor.');
        } catch (SetupException $exception) {
            self::assertStringContainsString('SQLite database file is unavailable', $exception->getMessage());
        }
        self::assertFileDoesNotExist($root . '/Storage/Custom.sqlite');

        file_put_contents($root . '/Storage/Custom.sqlite', '');
        self::assertSame([], $manager->plan()->actions);
        self::assertFileExists($root . '/Storage/Custom.sqlite');
    }

    private function root(bool $createStorage = true): string
    {
        $root = sys_get_temp_dir() . '/squehub-setup-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($createStorage ? $root . '/Storage' : $root, 0777, true));
        $this->roots[] = $root;
        return $root;
    }
}
