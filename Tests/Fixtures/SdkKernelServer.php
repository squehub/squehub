<?php

declare(strict_types=1);

/** Finite HTTP/1.1 loopback server dispatching each request through SqueHub Kernel. */
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/SdkHttpApplication.php';

use App\Http\Kernel;
use App\Http\Request;
use SqueHub\Tests\Fixtures\SdkHttpApplication;

$port = (int) ($argv[1] ?? 0);
$basePath = getenv('SQUEHUB_SDK_FIXTURE_ROOT');
$token = getenv('SQUEHUB_SDK_FIXTURE_TOKEN');
if ($port < 1 || $port > 65535 || !is_string($basePath) || $basePath === ''
    || !is_string($token) || $token === '') exit(2);

$server = @stream_socket_server('tcp://127.0.0.1:' . $port, $errorNumber, $errorMessage);
if ($server === false) exit(2);
$app = SdkHttpApplication::create($basePath, $token);
$kernel = $app->container()->make(Kernel::class);
$deadline = microtime(true) + 90;

while (microtime(true) < $deadline) {
    $connection = @stream_socket_accept($server, 1);
    if ($connection === false) continue;
    stream_set_timeout($connection, 3);
    $line = fgets($connection, 8192);
    if (!is_string($line) || trim($line) === '') {
        fclose($connection);
        continue;
    }
    [$method, $uri] = array_pad(explode(' ', trim($line), 3), 2, '');
    $headers = [];
    for ($index = 0; $index < 64 && ($headerLine = fgets($connection, 8192)) !== false; ++$index) {
        if (trim($headerLine) === '') break;
        $colon = strpos($headerLine, ':');
        if ($colon !== false) {
            $headers[trim(substr($headerLine, 0, $colon))] = trim(substr($headerLine, $colon + 1));
        }
    }
    if ($uri === '/shutdown') {
        fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
        fclose($connection);
        break;
    }
    $length = 0;
    foreach ($headers as $name => $value) {
        if (strcasecmp($name, 'Content-Length') === 0) $length = (int) $value;
    }
    if ($length < 0 || $length > 65536) {
        fclose($connection);
        continue;
    }
    $body = '';
    while (strlen($body) < $length) {
        $chunk = fread($connection, $length - strlen($body));
        if (!is_string($chunk) || $chunk === '') break;
        $body .= $chunk;
    }
    if (strlen($body) !== $length) {
        fclose($connection);
        continue;
    }
    $query = [];
    parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
    $request = new Request($method, $uri, $query, headers: $headers, rawBody: $body);
    $response = $kernel->handle($request);
    $content = $method === 'HEAD' || in_array($response->status(), [204, 205, 304], true)
        ? '' : $response->content();
    $output = 'HTTP/1.1 ' . $response->status() . " SqueHub\r\n";
    foreach ($response->headers() as $name => $value) $output .= $name . ': ' . $value . "\r\n";
    $output .= 'Content-Length: ' . strlen($content) . "\r\nConnection: close\r\n\r\n" . $content;
    while ($output !== '') {
        $written = fwrite($connection, $output);
        if (!is_int($written) || $written < 1) break;
        $output = substr($output, $written);
    }
    fclose($connection);
}
fclose($server);
