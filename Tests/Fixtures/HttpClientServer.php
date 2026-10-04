<?php

declare(strict_types=1);

/** Finite loopback fixture: only tests use this minimal HTTP/1.1 responder. */
$port = (int) ($argv[1] ?? 0);
$server = stream_socket_server('tcp://127.0.0.1:' . $port, $code, $message);
if ($server === false) exit(2);
$deadline = microtime(true) + 40;
$retryOnceCount = 0;
while (microtime(true) < $deadline) {
    $connection = @stream_socket_accept($server, 1);
    if ($connection === false) continue;
    stream_set_timeout($connection, 5);
    $requestLine = fgets($connection);
    if (!is_string($requestLine) || trim($requestLine) === '') { fclose($connection); continue; }
    $requestParts = explode(' ', trim($requestLine));
    $method = $requestParts[0] ?? 'GET';
    $uri = $requestParts[1] ?? '/';
    $headers = [];
    while (($line = fgets($connection)) !== false) {
        if (trim($line) === '') break;
        $colon = strpos($line, ':');
        if ($colon !== false) {
            $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
        }
    }
    $length = (int) ($headers['content-length'] ?? 0);
    $body = '';
    while (strlen($body) < $length) {
        $chunk = fread($connection, min(8192, $length - strlen($body)));
        if ($chunk === false || $chunk === '') break;
        $body .= $chunk;
    }
    $path = parse_url($uri, PHP_URL_PATH);
    $status = 200;
    $response = '';
    $responseHeaders = [];
    $stop = false;
    switch ($path) {
        case '/shutdown': $response = 'bye'; $stop = true; break;
        case '/retry-once':
            ++$retryOnceCount;
            $status = $retryOnceCount === 1 ? 503 : 200;
            $responseHeaders[] = 'Retry-After: 0';
            $response = 'attempt-' . $retryOnceCount;
            break;
        case '/redirect': $status = 302; $responseHeaders[] = 'Location: /json'; break;
        case '/redirect-loop': $status = 302; $responseHeaders[] = 'Location: /redirect-loop'; break;
        case '/redirect-cross':
            $status = 302;
            $responseHeaders[] = 'Location: http://localhost:' . $port . '/echo';
            break;
        case '/redirect-file':
            $status = 302;
            $responseHeaders[] = 'Location: file:///etc/passwd';
            break;
        case '/status':
            parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
            $status = (int) ($query['code'] ?? 500);
            $response = 'safe status body';
            break;
        case '/delay': usleep(300000); $response = 'late'; break;
        case '/binary':
            $responseHeaders[] = 'Content-Type: application/octet-stream';
            $response = "\x00\xff\x01\x80";
            break;
        case '/large': $response = str_repeat('x', 65536); break;
        case '/null-json':
            $responseHeaders[] = 'Content-Type: application/json';
            $response = 'null';
            break;
        case '/invalid-json': $response = '{broken'; break;
        case '/json':
            $responseHeaders[] = 'Content-Type: application/json';
            $responseHeaders[] = 'X-Fixture: one';
            $responseHeaders[] = 'X-Fixture: two';
            $response = '{"ok":true}';
            break;
        case '/echo':
        case '/multipart':
            $fields = [];
            if (str_starts_with($headers['content-type'] ?? '', 'application/x-www-form-urlencoded')) {
                parse_str($body, $fields);
            }
            $responseHeaders[] = 'Content-Type: application/json';
            $response = json_encode(['method' => $method, 'uri' => $uri,
                'headers' => $headers, 'body' => $body, 'fields' => $fields], JSON_THROW_ON_ERROR);
            break;
        default: $status = 404; $response = 'missing';
    }
    $reason = $status === 302 ? 'Found' : ($status === 200 ? 'OK' : 'Error');
    $head = "HTTP/1.1 {$status} {$reason}\r\nContent-Length: " . strlen($response)
        . "\r\nConnection: close\r\n" . implode("\r\n", $responseHeaders) . "\r\n\r\n";
    $out = $head . ($method === 'HEAD' ? '' : $response);
    while ($out !== '') {
        $written = fwrite($connection, $out);
        if ($written === false || $written === 0) break;
        $out = substr($out, $written);
    }
    fclose($connection);
    if ($stop) break;
}
fclose($server);
