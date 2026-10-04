<?php

declare(strict_types=1);

/**
 * One-shot local provider receiver for real HTTP transport tests. The fixture
 * captures wire data only inside a temporary test file and never forwards it.
 */
$port = (int) ($argv[1] ?? 0);
$capturePath = $argv[2] ?? '';
$mode = $argv[3] ?? '';
if ($port < 1 || $port > 65535 || !is_string($capturePath) || $capturePath === ''
    || !in_array($mode, ['resend-success', 'postmark-success', 'resend-reject'], true)) {
    exit(2);
}
$server = @stream_socket_server('tcp://127.0.0.1:' . $port, $errorNumber, $errorMessage);
if ($server === false) exit(2);

$deadline = microtime(true) + 12;
while (microtime(true) < $deadline) {
    $connection = @stream_socket_accept($server, 1);
    if ($connection === false) continue;
    stream_set_timeout($connection, 3);
    $requestLine = fgets($connection);
    if (!is_string($requestLine) || !str_starts_with($requestLine, 'POST /')) {
        fclose($connection); // The readiness probe does not consume the response.
        continue;
    }
    $headers = [];
    for ($count = 0; $count < 40 && ($line = fgets($connection)) !== false; ++$count) {
        if (trim($line) === '') break;
        $colon = strpos($line, ':');
        if ($colon !== false) {
            $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
        }
    }
    $length = (int) ($headers['content-length'] ?? -1);
    if ($length < 0 || $length > 131072) { fclose($connection); break; }
    $body = '';
    while (strlen($body) < $length) {
        $chunk = fread($connection, min(8192, $length - strlen($body)));
        if (!is_string($chunk) || $chunk === '') break;
        $body .= $chunk;
    }
    if (strlen($body) !== $length) { fclose($connection); break; }
    file_put_contents($capturePath, json_encode([
        'request_line' => trim($requestLine),
        'headers' => $headers,
        'body' => $body,
    ], JSON_THROW_ON_ERROR));

    [$status, $reason, $response] = match ($mode) {
        'resend-success' => [200, 'OK', '{"id":"resend-wire-1"}'],
        'postmark-success' => [200, 'OK', '{"ErrorCode":0,"MessageID":"postmark-wire-1"}'],
        'resend-reject' => [422, 'Unprocessable Entity',
            '{"message":"SQUEHUB_MAIL_WIRE_SECRET recipient@example.test private body"}'],
    };
    fwrite($connection, "HTTP/1.1 {$status} {$reason}\r\nContent-Type: application/json\r\n"
        . 'Content-Length: ' . strlen($response) . "\r\nConnection: close\r\n\r\n" . $response);
    fclose($connection);
    break;
}
fclose($server);
