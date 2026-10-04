<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Dev\DevProcess;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Real loopback HTTP proves Session-cookie transport around the frontend shell. */
final class FrontendSessionCookieHttpTest extends TestCase
{
    public function testNativeCookieCarriesCsrfLoginIdentityAndLogoutAcrossRequests(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is required for frontend cookie qualification.');
        }
        $listener = @stream_socket_server('tcp://127.0.0.1:0', $number, $message);
        if ($listener === false) {
            self::markTestSkipped('A loopback socket is required for frontend cookie qualification.');
        }
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $port = (int) substr($address, strrpos($address, ':') + 1);

        $project = new TemporaryProject();
        $process = null;
        try {
            $sessionRoot = $project->path('Storage/Sessions');
            self::assertTrue(mkdir($sessionRoot, 0777, true));
            self::assertTrue(mkdir($project->path('public'), 0777, true));
            $database = $project->path('frontend.sqlite');
            $pdo = new PDO('sqlite:' . $database);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('CREATE TABLE frontend_cookie_users '
                . '(id INTEGER PRIMARY KEY, email TEXT NOT NULL, password TEXT NOT NULL)');
            $insert = $pdo->prepare('INSERT INTO frontend_cookie_users '
                . '(id, email, password) VALUES (?, ?, ?)');
            $insert->execute([17, 'ada@example.test', password_hash('correct', PASSWORD_BCRYPT,
                ['cost' => 4])]);
            $insert = null;
            $pdo = null;

            $project->write('Config/App.php', '<?php return ["env"=>"testing","debug"=>false];');
            $project->write('Config/Database.php', '<?php return '
                . var_export(['default' => 'main', 'connections' => ['main' => [
                    'driver' => 'sqlite', 'database' => $database,
                ]]], true) . ';');
            $project->write('Config/Http.php', '<?php return ["base_path"=>"/app"];');
            $project->write('Config/Session.php', '<?php return '
                . var_export(['driver' => 'native', 'name' => 'squehub_frontend_test',
                    'path' => '/app', 'http_only' => true, 'same_site' => 'Lax',
                    'secure' => false, 'strict_mode' => true], true) . ';');
            $project->write('Config/Csrf.php', '<?php return '
                . var_export(['enabled' => true, 'field' => '_csrf',
                    'header' => 'X-CSRF-Token', 'except' => []], true) . ';');
            $project->write('Config/Api.php', '<?php return '
                . var_export(['enabled' => true, 'paths' => ['/api'],
                    'cors' => ['enabled' => false]], true) . ';');
            $project->write('Config/Frontend.php', '<?php return '
                . var_export(['spa' => ['enabled' => true, 'prefix' => '/frontend',
                    'view' => 'Frontend.App', 'except' => []]], true) . ';');
            $project->write('Config/Auth.php', '<?php return '
                . var_export([
                    'default' => 'web',
                    'guards' => ['web' => ['driver' => 'session', 'identity' => 'users']],
                    'identities' => ['users' => ['driver' => 'model',
                        'model' => 'FrontendSessionCookieIdentity',
                        'identifier' => 'id', 'password' => 'password',
                        'credentials' => ['email']]],
                    'passwords' => ['algorithm' => 'bcrypt', 'options' => ['cost' => 4],
                        'rehash_on_login' => true, 'max_bytes' => 4096],
                    'browser' => ['login_path' => '/frontend/login',
                        'authenticated_path' => '/frontend'],
                ], true) . ';');
            $project->write('Project/Views/Frontend/App.squehub.php',
                '<!doctype html><html><head><meta name="csrf-token" '
                . 'content="{{ csrf_token() }}"></head><body>Frontend shell</body></html>');

            $process = new DevProcess([PHP_BINARY, '-S', '127.0.0.1:' . $port,
                '-t', $project->path('public'),
                dirname(__DIR__) . '/Fixtures/FrontendSessionCookieRouter.php'],
                $project->path(), [
                    'SQUEHUB_FRONTEND_COOKIE_PROJECT' => $project->path(),
                    'SQUEHUB_FRONTEND_COOKIE_SESSIONS' => $sessionRoot,
                ]);
            $process->start();
            $this->waitForServer($process, $port);

            $shell = $this->request($port, 'GET', '/app/frontend/dashboard',
                ['Accept' => 'text/html']);
            self::assertSame(200, $shell['status'], $shell['body']);
            self::assertSame('private, no-store', $shell['headers']['cache-control'][0] ?? null);
            self::assertMatchesRegularExpression('/<meta name="csrf-token" content="([a-f0-9]{64})">/',
                $shell['body']);
            preg_match('/<meta name="csrf-token" content="([a-f0-9]{64})">/',
                $shell['body'], $tokenMatch);
            $token = $tokenMatch[1];
            $firstCookie = $this->sessionCookie($shell);
            self::assertStringContainsString('path=/app', $shell['headers']['set-cookie'][0]);
            self::assertStringContainsString('HttpOnly', $shell['headers']['set-cookie'][0]);
            self::assertStringContainsString('SameSite=Lax', $shell['headers']['set-cookie'][0]);

            $loginBody = '{"email":"ada@example.test","password":"correct"}';
            $withoutCookie = $this->request($port, 'POST', '/app/api/login', [
                'Accept' => 'application/json', 'Content-Type' => 'application/json',
                'X-CSRF-Token' => $token,
            ], $loginBody);
            self::assertSame(403, $withoutCookie['status']);
            $withoutToken = $this->request($port, 'POST', '/app/api/login', [
                'Accept' => 'application/json', 'Content-Type' => 'application/json',
                'Cookie' => $firstCookie,
            ], $loginBody);
            self::assertSame(403, $withoutToken['status']);

            $login = $this->request($port, 'POST', '/app/api/login', [
                'Accept' => 'application/json', 'Content-Type' => 'application/json',
                'Cookie' => $firstCookie, 'X-CSRF-Token' => $token,
            ], $loginBody);
            self::assertSame(200, $login['status'], $login['body']);
            self::assertSame(['authenticated' => true], json_decode($login['body'], true));
            $authCookie = $this->sessionCookie($login);
            self::assertNotSame($firstCookie, $authCookie);

            $me = $this->request($port, 'GET', '/app/api/me', [
                'Accept' => 'application/json', 'Cookie' => $authCookie,
            ]);
            self::assertSame(200, $me['status'], $me['body']);
            self::assertSame(['id' => 17], json_decode($me['body'], true));
            self::assertSame(401, $this->request($port, 'GET', '/app/api/me', [
                'Accept' => 'application/json', 'Cookie' => $firstCookie,
            ])['status']);

            $logout = $this->request($port, 'POST', '/app/api/logout', [
                'Accept' => 'application/json', 'Cookie' => $authCookie,
                'X-CSRF-Token' => $token,
            ]);
            self::assertSame(200, $logout['status'], $logout['body']);
            self::assertSame(['logged_out' => true], json_decode($logout['body'], true));
            $guestCookie = $this->sessionCookie($logout);
            self::assertNotSame($authCookie, $guestCookie);
            self::assertSame(401, $this->request($port, 'GET', '/app/api/me', [
                'Accept' => 'application/json', 'Cookie' => $guestCookie,
            ])['status']);
        } finally {
            if ($process !== null) {
                self::assertTrue($process->terminate(static function (string $type, string $data): void {}, 1.0));
            }
            // Windows may release the stopped server's SQLite handle just after
            // proc_close; retry only this disposable fixture directory.
            for ($attempt = 0; $attempt < 10; ++$attempt) {
                try {
                    $project->remove();
                    break;
                } catch (\Throwable $exception) {
                    if ($attempt === 9) throw $exception;
                    usleep(50_000);
                }
            }
        }
    }

    /** @return array{status:int,headers:array<string,list<string>>,body:string} */
    private function request(int $port, string $method, string $path,
        array $headers = [], string $body = ''): array
    {
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $number, $message, 2);
        self::assertIsResource($socket, 'Frontend loopback connection failed.');
        stream_set_timeout($socket, 3);
        $request = $method . ' ' . $path . " HTTP/1.0\r\nHost: 127.0.0.1:" . $port
            . "\r\nConnection: close\r\n";
        if ($body !== '') $headers['Content-Length'] = (string) strlen($body);
        foreach ($headers as $name => $value) $request .= $name . ': ' . $value . "\r\n";
        $request .= "\r\n" . $body;
        while ($request !== '') {
            $written = fwrite($socket, $request);
            self::assertIsInt($written);
            self::assertGreaterThan(0, $written);
            $request = substr($request, $written);
        }
        $raw = stream_get_contents($socket);
        fclose($socket);
        self::assertIsString($raw);
        $parts = explode("\r\n\r\n", $raw, 2);
        self::assertCount(2, $parts, $raw);
        $lines = explode("\r\n", $parts[0]);
        self::assertMatchesRegularExpression('~^HTTP/1\.[01] [0-9]{3}~', $lines[0]);
        $status = (int) substr($lines[0], 9, 3);
        $resultHeaders = [];
        foreach (array_slice($lines, 1) as $line) {
            if (!str_contains($line, ':')) continue;
            [$name, $value] = explode(':', $line, 2);
            $resultHeaders[strtolower($name)][] = trim($value);
        }
        return ['status' => $status, 'headers' => $resultHeaders, 'body' => $parts[1]];
    }

    /** @param array{status:int,headers:array<string,list<string>>,body:string} $response */
    private function sessionCookie(array $response): string
    {
        foreach ($response['headers']['set-cookie'] ?? [] as $field) {
            if (preg_match('/\A(squehub_frontend_test=[A-Za-z0-9,-]+)/', $field, $matches) === 1) {
                return $matches[1];
            }
        }
        self::fail('Native Session did not emit a session cookie.');
    }

    private function waitForServer(DevProcess $process, int $port): void
    {
        for ($attempt = 0; $attempt < 60; ++$attempt) {
            if (!$process->poll(static function (string $type, string $data): void {})) {
                self::fail('Frontend loopback server exited before its first request.');
            }
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $port,
                $number, $message, 0.05);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(50_000);
        }
        self::fail('Frontend loopback server did not start within three seconds.');
    }
}
