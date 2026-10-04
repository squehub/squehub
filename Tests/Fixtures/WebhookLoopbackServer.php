<?php

declare(strict_types=1);

/**
 * One-shot loopback receiver for a real outbound webhook exchange. The only
 * accepted connection is local, and the fixture never forwards request data.
 */
$port = (int) ($argv[1] ?? 0);
$capture = $argv[2] ?? '';
if ($port < 1 || $port > 65535 || !is_string($capture) || $capture === '') exit(2);
$server = @stream_socket_server('tcp://127.0.0.1:' . $port, $number, $message);
if ($server === false) exit(2);

$deadline = microtime(true) + 15;
while (microtime(true) < $deadline) {
    $connection = @stream_socket_accept($server, 1);
    if ($connection === false) continue;
    stream_set_timeout($connection, 5);
    $line = fgets($connection);
    if (!is_string($line) || !str_starts_with($line, 'POST /webhook ')) {
        fclose($connection);
        continue;
    }
    $requestLine = trim($line);
    $headers = [];
    while (($line = fgets($connection)) !== false) {
        if (trim($line) === '') break;
        $colon = strpos($line, ':');
        if ($colon !== false) {
            $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
        }
    }
    $length = (int) ($headers['content-length'] ?? -1);
    if ($length < 0 || $length > 32768) { fclose($connection); break; }
    $body = '';
    while (strlen($body) < $length) {
        $chunk = fread($connection, min(8192, $length - strlen($body)));
        if (!is_string($chunk) || $chunk === '') break;
        $body .= $chunk;
    }
    if (strlen($body) !== $length) { fclose($connection); break; }
    file_put_contents($capture, json_encode([
        'request_line' => $requestLine,
        'headers' => $headers,
        'body' => $body,
    ], JSON_THROW_ON_ERROR));
    fwrite($connection, "HTTP/1.1 204 No Content\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    fclose($connection);
    break;
}
fclose($server);
