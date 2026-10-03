<?php

declare(strict_types=1);

namespace App\Mail;

/** Immutable bytes and safe logical metadata; filenames never access a path. */
final readonly class MailAttachment
{
    public function __construct(
        private string $contents,
        private string $filename,
        private string $mime = 'application/octet-stream'
    ) {
        if ($filename === '' || $filename === '.' || $filename === '..'
            || strlen($filename) > 255 || str_contains($filename, '/')
            || str_contains($filename, '\\') || preg_match('/[\x00-\x1F\x7F]/', $filename)) {
            throw new MailConfigurationException('Invalid mail attachment filename.');
        }
        if (strlen($mime) > 127
            || preg_match('~\A[A-Za-z0-9!#$&^_.+-]+/[A-Za-z0-9!#$&^_.+-]+\z~D', $mime) !== 1) {
            throw new MailConfigurationException('Invalid mail attachment MIME type.');
        }
    }

    public function contents(): string { return $this->contents; }
    public function filename(): string { return $this->filename; }
    public function mime(): string { return $this->mime; }
}
