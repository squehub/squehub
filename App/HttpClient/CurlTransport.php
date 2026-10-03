<?php

declare(strict_types=1);

namespace App\HttpClient;

/** Fresh cURL handle per attempt prevents credentials or body state crossing calls. */
final class CurlTransport implements HttpTransport
{
    public function send(OutgoingRequest $request): HttpResponse
    {
        if (!extension_loaded('curl')) {
            throw new HttpConfigurationException('The cURL extension is required for outbound HTTP.');
        }
        $handle = curl_init($request->url);
        if ($handle === false) throw new HttpConnectionException('HTTP request could not start.');
        $headers = [];
        $status = 0;
        $body = '';
        $tooLarge = false;
        $writeFailed = false;
        $temporary = null;
        $temporaryUploads = [];
        $output = $request->sinkStream;
        try {
            if ($request->sinkPath !== null) {
                if (file_exists($request->sinkPath)) {
                    throw new HttpConfigurationException('Download destination already exists.');
                }
                $temporary = tempnam(dirname($request->sinkPath), '.squehub-http-');
                if ($temporary === false || ($output = fopen($temporary, 'wb')) === false) {
                    throw new HttpConnectionException('Download destination could not be opened.');
                }
            }
            $options = [
                CURLOPT_CUSTOMREQUEST => $request->method,
                CURLOPT_RETURNTRANSFER => false,
                // HttpClient resolves redirects so cross-origin credentials
                // are removed before another cURL handle is created.
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) round($request->connectTimeout * 1000)),
                CURLOPT_TIMEOUT_MS => max(1, (int) round($request->timeout * 1000)),
                CURLOPT_SSL_VERIFYPEER => $request->verifyPeer,
                CURLOPT_SSL_VERIFYHOST => $request->verifyPeer ? 2 : 0,
                CURLOPT_ENCODING => '',
                CURLOPT_HEADERFUNCTION => static function (\CurlHandle $curl, string $line) use (&$headers, &$status): int {
                    if (preg_match('~^HTTP/[^ ]+ ([0-9]{3})~', $line, $match)) {
                        $status = (int) $match[1];
                        $headers = []; // Discard informational response headers.
                    } elseif (($colon = strpos($line, ':')) !== false) {
                        $name = strtolower(trim(substr($line, 0, $colon)));
                        $value = trim(substr($line, $colon + 1));
                        if ($name !== '' && !preg_match('/[\x00-\x1f\x7f]/', $value)) {
                            $headers[$name][] = $value;
                        }
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function (\CurlHandle $curl, string $chunk) use
                    (&$body, &$tooLarge, &$writeFailed, $request, &$output): int {
                    $length = strlen($chunk);
                    if (is_resource($output)) {
                        $written = fwrite($output, $chunk);
                        if ($written !== $length) { $writeFailed = true; return 0; }
                    } else {
                        if (strlen($body) + $length > $request->maxBodyBytes) {
                            $tooLarge = true;
                            return 0;
                        }
                        $body .= $chunk;
                    }
                    return $length;
                },
            ];
            if ($request->method === 'HEAD') $options[CURLOPT_NOBODY] = true;
            if ($request->caBundle !== null) $options[CURLOPT_CAINFO] = $request->caBundle;
            $headerLines = [];
            foreach ($request->headers as $name => $value) {
                $headerLines[] = $name . ': ' . $value;
            }
            if ($headerLines !== []) $options[CURLOPT_HTTPHEADER] = $headerLines;
            if ($request->parts !== []) {
                $fields = [];
                foreach ($request->parts as $part) {
                    if ($part['kind'] === 'stream') {
                        $spool = tempnam(sys_get_temp_dir(), 'squehub-http-upload-');
                        if ($spool === false || ($target = fopen($spool, 'wb')) === false) {
                            throw new HttpConnectionException('Multipart stream could not be prepared.');
                        }
                        $temporaryUploads[] = $spool;
                        $total = 0;
                        try {
                            while (!feof($part['stream'])) {
                                $chunk = fread($part['stream'], 8192);
                                if ($chunk === false) throw new HttpConnectionException('Multipart stream read failed.');
                                if ($chunk === '') break;
                                $total += strlen($chunk);
                                if ($total > $request->maxRequestBytes || fwrite($target, $chunk) !== strlen($chunk)) {
                                    throw new HttpConfigurationException('Buffered multipart stream exceeds its size limit.');
                                }
                            }
                        } finally { fclose($target); }
                        $fields[$part['name']] = new \CURLFile($spool, $part['mime'], $part['filename']);
                        continue;
                    }
                    $fields[$part['name']] = match ($part['kind']) {
                        'file' => new \CURLFile($part['path'], $part['mime'], $part['filename']),
                        'bytes' => new \CURLStringFile($part['bytes'], $part['filename'], $part['mime']),
                        default => $part['value'],
                    };
                }
                $options[CURLOPT_POSTFIELDS] = $fields;
            } elseif ($request->body !== null) {
                $options[CURLOPT_POSTFIELDS] = $request->body;
            }
            if (!curl_setopt_array($handle, $options)) {
                throw new HttpConfigurationException('HTTP transport options are invalid.');
            }
            $ok = curl_exec($handle);
            if ($ok === false) {
                if ($tooLarge) throw new HttpClientException('External HTTP response exceeds its size limit.');
                if ($writeFailed) throw new HttpConnectionException('Download destination write failed.');
                $code = curl_errno($handle);
                if ($code === CURLE_OPERATION_TIMEDOUT) {
                    throw new HttpTimeoutException('External HTTP request timed out.');
                }
                // curl_error() can include a credential-bearing target URL.
                throw new HttpConnectionException('External HTTP request failed to connect.');
            }
            if ($status === 0) $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($output !== $request->sinkStream && is_resource($output)) {
                fflush($output);
                fclose($output);
                $output = null;
            }
            if ($request->sinkPath !== null) {
                if ($status >= 200 && $status < 300) {
                    if (file_exists($request->sinkPath) || !rename($temporary, $request->sinkPath)) {
                        throw new HttpConnectionException('Download destination could not be finalized.');
                    }
                    $temporary = null;
                }
            }
            return new HttpResponse($status, $body, $headers);
        } finally {
            if ($request->sinkPath !== null && is_resource($output)) fclose($output);
            if ($temporary !== null) @unlink($temporary);
            foreach ($temporaryUploads as $upload) @unlink($upload);
            curl_close($handle);
        }
    }
}
