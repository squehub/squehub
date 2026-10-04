<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Foundation\Environment;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;
use UnexpectedValueException;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

final class EnvironmentTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
        unset($_ENV['SQUEHUB_TEST_NAME'], $_ENV['SQUEHUB_TEST_BOOLEAN'], $_ENV['SQUEHUB_TEST_BOOLEAN_FORMS']);
    }

    protected function tearDown(): void
    {
        unset($_ENV['SQUEHUB_TEST_NAME'], $_ENV['SQUEHUB_TEST_BOOLEAN'], $_ENV['SQUEHUB_TEST_BOOLEAN_FORMS']);
        $this->project->remove();
    }

    public function testLoadsDotenvOnceAndUsesDefaults(): void
    {
        $this->project->write('.env', "SQUEHUB_TEST_NAME=first\nSQUEHUB_TEST_BOOLEAN=yes\n");
        $environment = new Environment($this->project->path());
        self::assertSame('first', $environment->get('SQUEHUB_TEST_NAME'));
        self::assertSame('first', $_ENV['SQUEHUB_TEST_NAME']);
        self::assertTrue($environment->boolean('SQUEHUB_TEST_BOOLEAN'));
        self::assertSame('fallback', $environment->get('SQUEHUB_TEST_MISSING', 'fallback'));
        $this->project->write('.env', "SQUEHUB_TEST_NAME=second\n");
        $environment->load();
        self::assertSame('first', $environment->get('SQUEHUB_TEST_NAME'));
    }

    public function testBooleanFormsAndInvalidValue(): void
    {
        $key = 'SQUEHUB_TEST_BOOLEAN_FORMS';
        foreach (['true' => true, 'false' => false, '1' => true, '0' => false, 'yes' => true, 'no' => false] as $text => $expected) {
            $_ENV[$key] = (string) $text;
            self::assertSame($expected, (new Environment($this->project->path()))->boolean($key));
        }
        $_ENV[$key] = 'unclear';
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($key);
        (new Environment($this->project->path()))->boolean($key);
    }

    public function testAbsentDotenvIsAllowed(): void
    {
        $environment = new Environment($this->project->path());
        self::assertSame('fallback', $environment->get('SQUEHUB_TEST_MISSING', 'fallback'));
    }

    public function testNewApplicationReadsUpdatedDotenvWithoutInheritingPriorPublication(): void
    {
        $key = 'SQUEHUB_TEST_NAME';
        $previous = getenv($key);
        putenv($key);
        try {
            $this->project->write('.env', "$key=first\n");
            $first = new Environment($this->project->path());
            self::assertSame('first', $first->get($key));
            $this->project->write('.env', "$key=second\n");
            self::assertSame('second', (new Environment($this->project->path()))->get($key));
            self::assertSame('first', $first->get($key));
            self::assertSame('second', $_ENV[$key]);
        } finally {
            if ($previous === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $previous);
            }
        }
    }

    public function testDotenvPublicationDoesNotLeakIntoAnotherProjectWithoutDotenv(): void
    {
        $key = 'SQUEHUB_TEST_NAME';
        $previous = getenv($key);
        $otherProject = new TemporaryProject();
        putenv($key);
        try {
            $this->project->write('.env', "$key=first\n");
            self::assertSame('first', (new Environment($this->project->path()))->get($key));
            self::assertSame('first', $_ENV[$key]);

            self::assertSame('fallback', (new Environment($otherProject->path()))->get($key, 'fallback'));
            putenv($key . '=shell');
            self::assertSame('shell', (new Environment($otherProject->path()))->get($key, 'fallback'));

            $_ENV[$key] = 'explicit';
            self::assertSame('explicit', (new Environment($otherProject->path()))->get($key, 'fallback'));
        } finally {
            $previous === false ? putenv($key) : putenv($key . '=' . $previous);
            $otherProject->remove();
        }
    }

    public function testChildProcessReadsDotenvEditsAfterCliBootstrap(): void
    {
        $key = 'SQUEHUB_TEST_BOOLEAN';
        $previous = getenv($key);
        putenv($key);
        try {
            $this->project->write('.env', "$key=false\n");
            $environment = new Environment($this->project->path());
            self::assertFalse($environment->boolean($key));
            self::assertSame([$key], $environment->publishedDotenvKeys());

            // The CLI publishes .env to $_ENV, but the server child must not
            // inherit that snapshot as an external override.
            $overrides = array_fill_keys($environment->publishedDotenvKeys(), false);
            $root = dirname(__DIR__, 2);
            $probe = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; '
                . '$environment = new App\\Foundation\\Environment('
                . var_export($this->project->path(), true) . '); '
                . 'echo $environment->boolean("' . $key . '") ? "true" : "false";';
            $read = static function () use ($root, $probe, $overrides): string {
                $process = new Process([PHP_BINARY, '-r', $probe], $root, $overrides);
                $process->run();
                self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                return $process->getOutput();
            };

            self::assertSame('false', $read());
            $this->project->write('.env', "$key=true\n");
            self::assertSame('true', $read());
        } finally {
            if ($previous === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $previous);
            }
        }
    }

    public function testRealEnvironmentOverrideIsNotMarkedForChildRemoval(): void
    {
        $key = 'SQUEHUB_TEST_BOOLEAN';
        $previous = getenv($key);
        try {
            putenv($key . '=true');
            $this->project->write('.env', "$key=false\n");
            $environment = new Environment($this->project->path());

            self::assertTrue($environment->boolean($key));
            self::assertSame([], $environment->publishedDotenvKeys());
        } finally {
            if ($previous === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $previous);
            }
        }
    }

    public function testInvalidDotenvErrorDoesNotIncludeItsContents(): void
    {
        $this->project->write('.env', 'SQUEHUB TEST_NAME=secret-value');
        try {
            (new Environment($this->project->path()))->load();
            self::fail('Invalid dotenv was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Application .env file is invalid.', $exception->getMessage());
            self::assertStringNotContainsString('secret-value', $exception->getMessage());
        }
    }
}
