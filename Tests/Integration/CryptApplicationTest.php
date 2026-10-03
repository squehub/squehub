<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Cryptography\Crypt as CanonicalCrypt;
use App\Cryptography\CryptManager;
use App\Cryptography\CryptServiceProvider;
use App\Cryptography\DecryptException;
use App\Foundation\Application;
use App\Plugins\Crypt;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Application boot, Plugins resolution, and CLI key generation stay isolated. */
final class CryptApplicationTest extends TestCase
{
    public function testProviderIsLazyAndApplicationsOwnSeparateKeys(): void
    {
        $projects = [new TemporaryProject(), new TemporaryProject()];
        try {
            foreach ($projects as $index => $project) {
                $key = 'base64:' . base64_encode(str_repeat($index === 0 ? 'a' : 'b', 32));
                $project->write('Config/Crypt.php', '<?php return ["driver"=>"auto","current"=>"primary",'
                    . '"keys"=>["primary"=>' . var_export($key, true) . ']];');
            }
            $first = new Application($projects[0]->path());
            $first->register(CryptServiceProvider::class);
            $first->bootstrap();
            $firstManager = $first->container()->make(CryptManager::class);
            self::assertSame($firstManager, Crypt::manager());
            self::assertSame($firstManager, CanonicalCrypt::manager());
            self::assertTrue(class_exists(\App\Plugins\CryptException::class));
            self::assertTrue(is_a(DecryptException::class, \App\Plugins\CryptException::class, true));
            $payload = Crypt::encrypt('from first');
            self::assertSame('from first', Crypt::decrypt($payload));
            self::assertTrue(Crypt::verify('message', Crypt::sign('message')));
            self::assertSame(43, strlen(Crypt::randomToken()));

            $second = new Application($projects[1]->path());
            $second->register(CryptServiceProvider::class);
            $second->bootstrap();
            self::assertNotSame($firstManager, Crypt::manager());
            try { Crypt::decrypt($payload); self::fail('Other Application key decrypted value.'); }
            catch (DecryptException) {}
            self::assertSame('from first', $firstManager->decrypt($payload));
        } finally {
            CanonicalCrypt::setResolver(null);
            foreach ($projects as $project) $project->remove();
        }
    }

    public function testMissingKeyDoesNotBreakApplicationBootButFailsWhenUsed(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Config/Crypt.php', '<?php return ["driver"=>"auto","current"=>"primary",'
                . '"keys"=>["primary"=>""]];');
            $app = new Application($project->path());
            $app->register(CryptServiceProvider::class);
            $app->bootstrap();
            self::assertTrue($app->isBooted());
            $this->expectException(\App\Cryptography\CryptConfigurationException::class);
            Crypt::encrypt('value');
        } finally {
            CanonicalCrypt::setResolver(null);
            $project->remove();
        }
    }

    public function testKeyCommandPrintsFreshFormattedKeyWithoutChangingEnv(): void
    {
        $root = dirname(__DIR__, 2);
        $environmentFile = $root . '/.env';
        $before = is_file($environmentFile) ? file_get_contents($environmentFile) : null;
        $process = new Process([PHP_BINARY, 'squehub', 'key:generate'], $root);
        $process->run();
        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        self::assertMatchesRegularExpression('~\Abase64:[A-Za-z0-9+/]{43}=\R?\z~D', $process->getOutput());
        self::assertSame($before, is_file($environmentFile) ? file_get_contents($environmentFile) : null);
    }
}
