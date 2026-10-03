<?php

declare(strict_types=1);

namespace App\Api;

use App\Http\Exception\HttpException;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * A deliberate public application error, thrown through the ordinary Kernel.
 * Message and details are application-authored public data, never a place for
 * raw requests or Models. The exception's diagnostic message omits that data;
 * the existing exception boundary owns safe rendering and request correlation.
 */
final class ApiError extends HttpException
{
    private function __construct(
        private readonly string $errorCode,
        #[SensitiveParameter] private readonly string $publicMessage,
        int $status,
        #[SensitiveParameter] private readonly ?array $details,
        #[SensitiveParameter] array $headers
    ) {
        if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $errorCode) !== 1) {
            throw new InvalidArgumentException('API error codes require a bounded lowercase identifier.');
        }
        if ($status < 400 || $status > 599) {
            throw new InvalidArgumentException('API error status must be between 400 and 599.');
        }
        parent::__construct($status, 'An application API error occurred.', $headers);
    }

    public static function make(
        string $code,
        #[SensitiveParameter] string $message,
        int $status = 400,
        #[SensitiveParameter] ?array $details = null,
        #[SensitiveParameter] array $headers = []
    ): self {
        return new self($code, $message, $status, $details, $headers);
    }

    public function errorCode(): string { return $this->errorCode; }
    public function publicMessage(): string { return $this->publicMessage; }
    public function details(): ?array { return $this->details; }
}
