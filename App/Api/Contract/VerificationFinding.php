<?php

declare(strict_types=1);

namespace App\Api\Contract;

use InvalidArgumentException;

/**
 * A bounded network-contract observation. Descriptions are framework-owned so
 * an exception, body, header value, or credential cannot become report text.
 */
final readonly class VerificationFinding
{
    private const MESSAGES = [
        'contract_invalid' => 'The application contract could not be compiled.',
        'route_missing' => 'The case refers to an operation without a public route.',
        'method_mismatch' => 'The operation method differs from its route.',
        'version_mismatch' => 'The operation version differs from its route.',
        'security_mismatch' => 'Declared security differs from known route middleware.',
        'security_unverifiable' => 'Custom middleware security cannot be verified statically.',
        'operation_uncovered' => 'No executable case is registered for this operation.',
        'execution_prohibited' => 'Executable cases are not permitted in this environment.',
        'mutation_skipped' => 'A mutation case requires explicit verification opt-in.',
        'case_invalid' => 'The verification case is incomplete or its input is invalid.',
        'fixture_failed' => 'Verification fixture setup or cleanup failed.',
        'request_schema_mismatch' => 'The case input does not match its declared request schema.',
        'status_undeclared' => 'The response status is not declared by the operation.',
        'status_mismatch' => 'The response status differs from the case expectation.',
        'unexpected_redirect' => 'The API operation returned an undeclared browser redirect.',
        'content_type_mismatch' => 'The response content type differs from the declaration.',
        'header_missing' => 'A declared response header is missing.',
        'header_mismatch' => 'A declared response header has an incompatible value.',
        'json_invalid' => 'The response body is not valid JSON.',
        'response_schema_mismatch' => 'The JSON response does not match its declared schema.',
        'case_failed' => 'The case could not complete through the HTTP Kernel.',
    ];

    public function __construct(
        public string $severity,
        public string $code,
        public ?string $operationId = null,
        public ?string $caseName = null,
        public ?string $path = null,
        public ?string $expected = null,
        public ?string $actual = null
    ) {
        if (!in_array($severity, ['error', 'warning', 'info'], true)
            || !isset(self::MESSAGES[$code])) {
            throw new InvalidArgumentException('Verification finding type is invalid.');
        }
        foreach ([$operationId, $caseName, $path, $expected, $actual] as $value) {
            if ($value !== null && (strlen($value) > 256
                || preg_match('/[\x00-\x1F\x7F]/', $value) === 1)) {
                throw new InvalidArgumentException('Verification finding metadata is invalid.');
            }
        }
    }

    /** @return array<string,string|null> */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity,
            'code' => $this->code,
            'operation_id' => $this->operationId,
            'case' => $this->caseName,
            'message' => self::MESSAGES[$this->code],
            'path' => $this->path,
            'expected' => $this->expected,
            'actual' => $this->actual,
        ];
    }
}
