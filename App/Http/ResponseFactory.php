<?php

declare(strict_types=1);

namespace App\Http;

/** Injectable construction API for ordinary, structured, and deferred responses. */
final class ResponseFactory
{
    public function make(string $content = '', int $status = 200, array $headers = []): Response
    {
        return new Response($content, $status, $headers);
    }

    public function json(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, $headers);
    }

    public function redirect(string $location, int $status = 302): RedirectResponse
    {
        return new RedirectResponse($location, $status);
    }

    /** PHP strings are bytes; no character conversion or JSON encoding occurs. */
    public function binary(string $bytes, string $contentType = 'application/octet-stream',
        int $status = 200, array $headers = []): Response
    {
        if ($contentType === '' || strlen($contentType) > 512) {
            throw new \InvalidArgumentException('Binary response content type is invalid.');
        }
        foreach (array_keys($headers) as $name) {
            if (strcasecmp((string) $name, 'Content-Type') === 0) unset($headers[$name]);
        }
        $headers['Content-Type'] = $contentType;
        return new Response($bytes, $status, $headers);
    }

    /**
     * A stream producer returns iterable byte-string chunks. It runs only when
     * the response is sent, never during route inspection or construction.
     *
     * @param callable():iterable<string> $producer
     */
    public function stream(callable $producer, int $status = 200, array $headers = []): StreamResponse
    {
        return new StreamResponse($producer, $status, $headers);
    }

    /**
     * Download a local file selected and authorized by application code.
     * Passing the Request opts into one safe byte range; no global request is
     * read and no remote URL is fetched.
     */
    public function download(string $path, ?string $filename = null,
        ?string $contentType = null, ?Request $request = null): FileResponse
    {
        return new FileResponse($path, $filename, $contentType, true, $request);
    }

    /** Display a local file inline using the same bounded sender as downloads. */
    public function file(string $path, ?string $filename = null,
        ?string $contentType = null, ?Request $request = null): FileResponse
    {
        return new FileResponse($path, $filename, $contentType, false, $request);
    }
}
