<?php

declare(strict_types=1);

/**
 * One-message loopback SMTP fixture for the mail integration suite. Its
 * transcript is confined to the temporary test directory, never production
 * logging. The caller terminates the process if a send does not complete.
 */
$ready = $argv[1];
$capture = $argv[2];
$mode = $argv[3] ?? 'accept';
/** Publish a complete fixture record before its final name becomes visible. */
$publish = static function (string $path, string $bytes): void {
    $temporary = $path . '.tmp';
    if (file_put_contents($temporary, $bytes) !== strlen($bytes)
        || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('SMTP fixture output could not be published.');
    }
};
$server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if ($server === false) exit(2);
$address = stream_socket_get_name($server, false);
$publish($ready, (string) substr($address, strrpos($address, ':') + 1));
$client = @stream_socket_accept($server, 8);
if ($client === false) exit(3);
stream_set_timeout($client, 8);
if ($mode === 'reject') {
    fwrite($client, "554 secret-recipient@example.test rejected\r\n");
    fclose($client);
    fclose($server);
    exit(0);
}
fwrite($client, "220 localhost SqueHub test SMTP\r\n");
$envelope = [];
$data = '';
$readingData = false;
$authStage = null;
$authenticated = false;
while (($line = fgets($client)) !== false) {
    $line = rtrim($line, "\r\n");
    if ($authStage === 'username') {
        if (base64_decode($line, true) !== 'mail-user') { fwrite($client, "535 Invalid credentials\r\n"); break; }
        $authStage = 'password';
        fwrite($client, "334 UGFzc3dvcmQ6\r\n");
        continue;
    }
    if ($authStage === 'password') {
        $authenticated = base64_decode($line, true) === 'mail-pass';
        $authStage = null;
        fwrite($client, $authenticated ? "235 Authenticated\r\n" : "535 Invalid credentials\r\n");
        continue;
    }
    if ($readingData) {
        if ($line === '.') {
            $readingData = false;
            fwrite($client, "250 queued\r\n");
        } else {
            $data .= (str_starts_with($line, '..') ? substr($line, 1) : $line) . "\r\n";
        }
        continue;
    }
    if (str_starts_with($line, 'EHLO') || str_starts_with($line, 'HELO')) {
        fwrite($client, $mode === 'auth'
            ? "250-localhost\r\n250-AUTH PLAIN LOGIN\r\n250 8BITMIME\r\n"
            : ($mode === 'starttls' ? "250-localhost\r\n250 STARTTLS\r\n"
                : "250-localhost\r\n250 8BITMIME\r\n"));
    } elseif ($mode === 'starttls' && $line === 'STARTTLS') {
        $envelope[] = 'STARTTLS';
        fwrite($client, "454 TLS unavailable in this fixture\r\n");
        break;
    } elseif ($mode === 'auth' && $line === 'AUTH LOGIN') {
        $authStage = 'username';
        fwrite($client, "334 VXNlcm5hbWU6\r\n");
    } elseif ($mode === 'auth' && str_starts_with($line, 'AUTH PLAIN ')) {
        $authenticated = base64_decode(substr($line, 11), true) === "\0mail-user\0mail-pass";
        fwrite($client, $authenticated ? "235 Authenticated\r\n" : "535 Invalid credentials\r\n");
    } elseif (str_starts_with($line, 'MAIL FROM:') || str_starts_with($line, 'RCPT TO:')) {
        $envelope[] = $line;
        fwrite($client, "250 OK\r\n");
    } elseif ($line === 'DATA') {
        $readingData = true;
        fwrite($client, "354 End data with .\r\n");
    } elseif ($line === 'QUIT') {
        fwrite($client, "221 Bye\r\n");
        break;
    } else {
        fwrite($client, "500 Unsupported command\r\n");
    }
}
$publish($capture, json_encode(['envelope' => $envelope, 'data' => $data,
    'authenticated' => $authenticated], JSON_THROW_ON_ERROR));
fclose($client);
fclose($server);
