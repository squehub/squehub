<?php

declare(strict_types=1);

/** Finite, test-only OIDC issuer bound to IPv4 loopback. */
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Firebase\JWT\JWT;

$port = (int) ($argv[1] ?? 0);
if ($port < 1 || $port > 65535 || !extension_loaded('openssl')) exit(2);

// Windows PHP installations do not always have a default openssl.cnf.
$configuration = tempnam(sys_get_temp_dir(), 'oidc-loopback-openssl-');
if ($configuration === false) exit(2);
try {
    if (file_put_contents($configuration, "[req]\ndefault_bits = 2048\n") === false) exit(2);
    $privateKey = openssl_pkey_new([
        'config' => $configuration,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'private_key_bits' => 2048,
    ]);
} finally {
    @unlink($configuration);
}
if (!$privateKey instanceof OpenSSLAsymmetricKey) exit(2);
$details = openssl_pkey_get_details($privateKey);
if (!is_array($details) || !is_array($details['rsa'] ?? null)) exit(2);
$base64Url = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
$jwk = [
    'kty' => 'RSA', 'kid' => 'loopback-key', 'alg' => 'RS256', 'use' => 'sig',
    'n' => $base64Url($details['rsa']['n']),
    'e' => $base64Url($details['rsa']['e']),
];

$server = @stream_socket_server('tcp://127.0.0.1:' . $port, $errorNumber, $errorMessage);
if ($server === false) exit(2);
$issuer = 'http://127.0.0.1:' . $port;
$callback = $issuer . '/auth/oidc/callback';
$clientId = 'loopback:client';
$clientSecret = 'local+secret';
$expectedBasic = 'Basic ' . base64_encode(rawurlencode($clientId) . ':' . rawurlencode($clientSecret));
$metadata = [
    'issuer' => $issuer,
    'authorization_endpoint' => $issuer . '/authorize',
    'token_endpoint' => $issuer . '/token',
    'jwks_uri' => $issuer . '/keys',
    'response_types_supported' => ['code'],
    'id_token_signing_alg_values_supported' => ['RS256'],
    'code_challenge_methods_supported' => ['S256'],
    'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
];
$modes = ['discovery' => 'normal', 'token' => 'normal', 'jwks' => 'normal'];
$counts = ['discovery' => 0, 'authorize' => 0, 'token' => 0, 'jwks' => 0, 'followed' => 0];
$authorization = null;
$lastToken = null;
$deadline = microtime(true) + 90;

while (microtime(true) < $deadline) {
    $connection = @stream_socket_accept($server, 1);
    if ($connection === false) continue;
    stream_set_timeout($connection, 3);
    $requestLine = fgets($connection, 8192);
    if (!is_string($requestLine) || trim($requestLine) === '') {
        fclose($connection);
        continue;
    }
    [$method, $uri] = array_pad(explode(' ', trim($requestLine), 3), 2, '');
    $headers = [];
    for ($index = 0; $index < 64 && ($line = fgets($connection, 8192)) !== false; ++$index) {
        if (trim($line) === '') break;
        $colon = strpos($line, ':');
        if ($colon !== false) {
            $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
        }
    }
    $length = (int) ($headers['content-length'] ?? 0);
    $body = '';
    if ($length >= 0 && $length <= 8192) {
        while (strlen($body) < $length) {
            $chunk = fread($connection, $length - strlen($body));
            if ($chunk === false || $chunk === '') break;
            $body .= $chunk;
        }
    }
    $path = parse_url($uri, PHP_URL_PATH);
    parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
    $status = 200;
    $responseHeaders = ['Content-Type: application/json'];
    $response = '';
    $stop = false;
    $json = static fn (array $value): string => json_encode($value, JSON_THROW_ON_ERROR);

    switch ($path) {
        case '/shutdown':
            $response = $json(['ok' => true]);
            $stop = true;
            break;
        case '/configure':
            $valid = true;
            foreach ($modes as $endpoint => $mode) {
                $candidate = $query[$endpoint] ?? 'normal';
                if (!is_string($candidate) || !in_array($candidate, ['normal', 'redirect', 'oversize'], true)) {
                    $valid = false;
                    break;
                }
                $modes[$endpoint] = $candidate;
            }
            if (!$valid) {
                $status = 400;
                $response = $json(['error' => 'invalid fixture mode']);
            } else {
                $counts = array_fill_keys(array_keys($counts), 0);
                $authorization = null;
                $lastToken = null;
                $response = $json(['ok' => true]);
            }
            break;
        case '/audit':
            $response = $json(['counts' => $counts, 'last_token' => $lastToken]);
            break;
        case '/.well-known/openid-configuration':
            ++$counts['discovery'];
            if ($modes['discovery'] === 'redirect') {
                $status = 302;
                $responseHeaders[] = 'Location: /followed';
            } elseif ($modes['discovery'] === 'oversize') {
                $response = $json($metadata + ['padding' => str_repeat('x', 32768)]);
            } else {
                $response = $json($metadata);
            }
            break;
        case '/authorize':
            ++$counts['authorize'];
            if ($method !== 'GET' || ($query['response_type'] ?? null) !== 'code'
                || ($query['client_id'] ?? null) !== $clientId
                || ($query['redirect_uri'] ?? null) !== $callback
                || ($query['code_challenge_method'] ?? null) !== 'S256'
                || !is_string($query['state'] ?? null)
                || !is_string($query['nonce'] ?? null)
                || !is_string($query['code_challenge'] ?? null)) {
                $status = 400;
                $response = $json(['error' => 'invalid authorization request']);
                break;
            }
            $authorization = [
                'state' => $query['state'], 'nonce' => $query['nonce'],
                'challenge' => $query['code_challenge'], 'code' => 'one-time-code',
            ];
            $status = 302;
            $responseHeaders[] = 'Location: ' . $callback . '?'
                . http_build_query([
                    'state' => $authorization['state'], 'iss' => $issuer,
                    'code' => $authorization['code'],
                ], '', '&', PHP_QUERY_RFC3986);
            break;
        case '/token':
            ++$counts['token'];
            parse_str($body, $fields);
            $lastToken = ['method' => $method, 'headers' => $headers, 'fields' => $fields];
            if ($modes['token'] === 'redirect') {
                $status = 302;
                $responseHeaders[] = 'Location: /followed';
                break;
            }
            if ($modes['token'] === 'oversize') {
                $response = str_repeat('x', 65537);
                break;
            }
            if ($method !== 'POST' || $authorization === null
                || ($headers['authorization'] ?? null) !== $expectedBasic
                || !str_starts_with($headers['content-type'] ?? '', 'application/x-www-form-urlencoded')
                || ($fields['grant_type'] ?? null) !== 'authorization_code'
                || ($fields['code'] ?? null) !== $authorization['code']
                || ($fields['redirect_uri'] ?? null) !== $callback
                || !is_string($fields['code_verifier'] ?? null)
                || isset($fields['client_id']) || isset($fields['client_secret'])
                || $base64Url(hash('sha256', $fields['code_verifier'], true)) !== $authorization['challenge']) {
                $status = 400;
                $response = $json(['error' => 'invalid token request']);
                break;
            }
            $now = time();
            $token = JWT::encode([
                'iss' => $issuer, 'sub' => 'loopback-user', 'aud' => $clientId,
                'nonce' => $authorization['nonce'], 'iat' => $now, 'exp' => $now + 300,
                'email' => 'loopback@example.test', 'email_verified' => true,
            ], $privateKey, 'RS256', 'loopback-key');
            $response = $json(['id_token' => $token, 'access_token' => 'fixture-access-token']);
            $authorization = null;
            break;
        case '/keys':
            ++$counts['jwks'];
            if ($modes['jwks'] === 'redirect') {
                $status = 302;
                $responseHeaders[] = 'Location: /followed';
            } elseif ($modes['jwks'] === 'oversize') {
                $response = str_repeat('x', 65537);
            } else {
                $response = $json(['keys' => [$jwk]]);
            }
            break;
        case '/followed':
            ++$counts['followed'];
            $response = $json(['followed' => true]);
            break;
        default:
            $status = 404;
            $response = $json(['error' => 'missing']);
    }

    $reason = $status === 302 ? 'Found' : ($status === 200 ? 'OK' : 'Error');
    $output = "HTTP/1.1 {$status} {$reason}\r\nContent-Length: " . strlen($response)
        . "\r\nConnection: close\r\n" . implode("\r\n", $responseHeaders) . "\r\n\r\n" . $response;
    while ($output !== '') {
        $written = @fwrite($connection, $output);
        if ($written === false || $written === 0) break;
        $output = substr($output, $written);
    }
    fclose($connection);
    if ($stop) break;
}
fclose($server);
