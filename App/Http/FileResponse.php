<?php

declare(strict_types=1);

namespace App\Http;

use InvalidArgumentException;
use LogicException;

/**
 * Delivers a regular local file in bounded chunks through Response::send().
 * Application code selects and authorizes the path; this class never treats a
 * request path as an authorized filesystem path or fetches remote resources.
 */
final class FileResponse extends Response
{
    private const CHUNK_BYTES = 65536;

    private string $source;
    private int $size;
    private int $modified;
    private int $device;
    private int $inode;
    private int $offset = 0;
    private int $length;
    /** @var resource|false */
    private $handle = false;

    public function __construct(
        string $path,
        ?string $filename = null,
        ?string $contentType = null,
        bool $download = true,
        ?Request $request = null
    ) {
        if ($path === '' || strlen($path) > 4096 || str_contains($path, "\0")
            || str_contains($path, '://')) {
            throw new InvalidArgumentException('File response source must be a local path.');
        }
        $source = @realpath($path);
        if ($source === false || !is_file($source) || !is_readable($source)) {
            throw new FileResponseException('Response file is unavailable.');
        }
        clearstatcache(true, $source);
        $stat = @stat($source);
        if (!is_array($stat) || !is_int($stat['size']) || $stat['size'] < 0) {
            throw new FileResponseException('Response file metadata is unavailable.');
        }
        $this->source = $source;
        $this->size = $stat['size'];
        $this->modified = (int) $stat['mtime'];
        $this->device = (int) $stat['dev'];
        $this->inode = (int) $stat['ino'];
        $this->length = $this->size;

        if ($contentType !== null && ($contentType === '' || strlen($contentType) > 512)) {
            throw new InvalidArgumentException('File response content type is invalid.');
        }

        // A client-visible filename is metadata. Never expose a physical path.
        $name = $filename ?? basename(str_replace('\\', '/', $source));
        $disposition = self::disposition($download ? 'attachment' : 'inline', $name);
        $headers = [
            'Content-Type' => $contentType ?? 'application/octet-stream',
            'Content-Disposition' => $disposition,
            'Content-Length' => (string) $this->size,
            'Accept-Ranges' => 'bytes',
        ];
        $status = 200;
        if ($request !== null && $request->header('If-Range') === null) {
            $range = $request->header('Range');
            if ($request->headerRepeated('Range') || $request->headerConflict('Range')
                || ($range !== null && !is_string($range))) {
                $status = 416;
            } elseif (is_string($range)) {
                $bounds = self::range($range, $this->size);
                if ($bounds === null) {
                    $status = 416;
                } else {
                    [$this->offset, $this->length] = $bounds;
                    $status = 206;
                    $headers['Content-Range'] = 'bytes ' . $this->offset . '-'
                        . ($this->offset + $this->length - 1) . '/' . $this->size;
                    $headers['Content-Length'] = (string) $this->length;
                }
            }
        }
        if ($status === 416) {
            $this->length = 0;
            $headers['Content-Range'] = 'bytes */' . $this->size;
            $headers['Content-Length'] = '0';
        }
        parent::__construct('', $status, $headers);
    }

    public function hasDeferredBody(): bool { return true; }

    public function withContent(string $content): static
    {
        throw new LogicException('A file response does not have replaceable string content.');
    }

    /** File size, range, and disposition are authoritative framework metadata. */
    public function withHeader(string $name, string $value): static
    {
        self::checkManagedHeader($name);
        return parent::withHeader($name, $value);
    }

    public function withoutHeader(string $name): static
    {
        self::checkManagedHeader($name);
        return parent::withoutHeader($name);
    }

    /**
     * Open before emitting headers so a vanished/replaced file fails without
     * sending a partial success response. The file is not snapshotted: changes
     * after this check can still interrupt delivery and must remain visible.
     */
    protected function prepareBody(): void
    {
        // Even a zero-byte 200 response must revalidate its source before
        // committing headers. A 416 has no selected representation to read.
        if ($this->status() === 416) return;
        clearstatcache(true, $this->source);
        $resolved = @realpath($this->source);
        if ($resolved === false || (PHP_OS_FAMILY === 'Windows'
            ? strcasecmp($resolved, $this->source) !== 0 : $resolved !== $this->source)) {
            throw new FileResponseException('Response file changed before delivery.');
        }
        $this->handle = @fopen($this->source, 'rb');
        if ($this->handle === false) {
            throw new FileResponseException('Response file cannot be opened.');
        }
        $stat = @fstat($this->handle);
        if (!is_array($stat) || ((int) $stat['mode'] & 0170000) !== 0100000
            || $stat['size'] !== $this->size
            || (int) $stat['mtime'] !== $this->modified
            || ($this->inode !== 0 && (int) $stat['ino'] !== 0
                && ((int) $stat['dev'] !== $this->device || (int) $stat['ino'] !== $this->inode))) {
            throw new FileResponseException('Response file changed before delivery.');
        }
        if ($this->offset > 0 && @fseek($this->handle, $this->offset) !== 0) {
            throw new FileResponseException('Response file cannot be positioned.');
        }
    }

    /** The loop never allocates memory based on the file or requested range size. */
    protected function emitBody(): void
    {
        if ($this->length === 0) return;
        if ($this->handle === false) {
            throw new FileResponseException('Response file is unavailable.');
        }
        $remaining = $this->length;
        while ($remaining > 0) {
            $chunk = @fread($this->handle, min(self::CHUNK_BYTES, $remaining));
            if (!is_string($chunk) || $chunk === '') {
                throw new FileResponseException('Response file changed during delivery.');
            }
            echo $chunk;
            $remaining -= strlen($chunk);
        }
    }

    /** Release the opened handle even when seeking or writing fails. */
    protected function finishBody(): void
    {
        if ($this->handle !== false) {
            fclose($this->handle);
            $this->handle = false;
        }
    }

    private static function checkManagedHeader(string $name): void
    {
        if (in_array(strtolower($name), [
            'content-length', 'content-range', 'content-disposition',
            'accept-ranges', 'transfer-encoding',
        ], true)) {
            throw new InvalidArgumentException('File response framing headers are framework-managed.');
        }
    }

    private static function disposition(string $type, string $name): string
    {
        if ($name === '' || $name === '.' || $name === '..' || strlen($name) > 255
            || preg_match('/[\x00-\x1F\x7F\/\\\\]/', $name) === 1
            || preg_match('//u', $name) !== 1) {
            throw new InvalidArgumentException('Client download filename is invalid.');
        }
        $fallback = preg_replace('/[^\x20-\x7E]/', '_', $name);
        if (!is_string($fallback)) {
            throw new InvalidArgumentException('Client download filename is invalid.');
        }
        $quoted = addcslashes($fallback, '\\"');
        $header = $type . '; filename="' . $quoted . '"';
        if ($fallback !== $name) {
            $header .= "; filename*=UTF-8''" . rawurlencode($name);
        }
        return $header;
    }

    /** @return ?array{int,int} Starting offset and byte count, or an unsatisfiable range. */
    private static function range(string $header, int $size): ?array
    {
        if ($size === 0 || strlen($header) > 128
            || preg_match('/\Abytes=(\d*)-(\d*)\z/D', $header, $match) !== 1
            || ($match[1] === '' && $match[2] === '')) {
            return null;
        }
        if ($match[1] === '') {
            $suffix = self::number($match[2]);
            if ($suffix === null || $suffix === 0) return null;
            $length = min($suffix, $size);
            return [$size - $length, $length];
        }
        $start = self::number($match[1]);
        $end = $match[2] === '' ? $size - 1 : self::number($match[2]);
        if ($start === null || $end === null || $start >= $size || $end < $start) return null;
        $end = min($end, $size - 1);
        return [$start, $end - $start + 1];
    }

    private static function number(string $digits): ?int
    {
        $normalized = ltrim($digits, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $maximum = (string) PHP_INT_MAX;
        if (strlen($normalized) > strlen($maximum)
            || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)) {
            return null;
        }
        return (int) $normalized;
    }
}
