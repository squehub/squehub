<?php

declare(strict_types=1);

namespace App\Api;

use App\Config\Repository;
use App\Http\Request;
use App\Http\Exception\MalformedJsonException;
use App\Support\SecretRedactor;
use App\Support\SensitiveKey;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Validates public error values before the existing JsonResponse encoder runs.
 * Objects are never traversed or asked to serialize. Known credentials are
 * redacted defensively; applications still decide which business data is public.
 * No request data or error details escape into aggregate diagnostics or logs.
 * @internal
 */
final class ApiErrorData
{
    private SecretRedactor $redactor;
    private array $secrets = [];
    private int $inspected = 0;

    public function __construct(Repository $config, private Request $request)
    {
        $this->redactor = new SecretRedactor($config);
        $this->collect($request->query(), false);
        $this->collect($request->headers(), false);
        try {
            $input = $request->all();
        } catch (MalformedJsonException) {
            // A malformed request body must not prevent its safe 400 rendering.
            $input = [];
        }
        $this->collect($input, false);
        // Custom CSRF names need not resemble credentials. Honor the same
        // configured field/header boundary as browser old-input filtering.
        $csrfField = $config->get('csrf.field', '_csrf');
        if (is_string($csrfField) && array_key_exists($csrfField, $input)) {
            $this->collect([$input[$csrfField]], true);
        }
        $csrfHeader = $config->get('csrf.header', 'X-CSRF-Token');
        if (is_string($csrfHeader)) $this->collect([$request->header($csrfHeader)], true);
        $cookie = $request->header('Cookie');
        if (is_string($cookie)) {
            foreach (explode(';', $cookie) as $pair) {
                $value = explode('=', trim($pair), 2)[1] ?? '';
                if ($value !== '') $this->secrets[] = $value;
            }
        }
        // Request cookies may be supplied independently of the Cookie header.
        $this->collect($request->cookies(), true);
    }

    public function prepare(#[SensitiveParameter] string $message, #[SensitiveParameter] ?array $details, bool $validation): array
    {
        $items = 0;
        $this->validate([$message, $details], 0, $items);
        if ($message === '') throw new InvalidArgumentException('API error messages must not be empty.');
        // Validate the original tree before redaction, so malformed sensitive
        // values cannot disappear behind a replacement marker and pass silently.
        json_encode([$message, $details], JSON_THROW_ON_ERROR);
        if ($validation && $details !== null) {
            foreach ($details as $field => $messages) {
                if (!is_string($field) || $field === '' || !is_array($messages) || !array_is_list($messages)) {
                    throw new InvalidArgumentException('Validation errors require named fields with message lists.');
                }
                foreach ($messages as $error) {
                    if (!is_string($error)) {
                        throw new InvalidArgumentException('Validation error messages must be strings.');
                    }
                }
            }
        }
        if ($details !== null && !$validation) $this->collect($details, false);
        $safeDetails = $details === null ? null : $this->redactArray($details, !$validation);
        return [$this->text($message), $safeDetails];
    }

    private function validate(#[SensitiveParameter] mixed $value, int $depth, int &$items): void
    {
        if ($depth > 32 || ++$items > 10000) {
            throw new InvalidArgumentException('API error details exceed the supported bounds.');
        }
        if (is_array($value)) {
            foreach ($value as $item) $this->validate($item, $depth + 1, $items);
            return;
        }
        if ($value === null || is_string($value) || is_int($value) || is_bool($value)
            || (is_float($value) && is_finite($value))) return;
        throw new InvalidArgumentException('API error details must contain only JSON scalars and arrays.');
    }

    /** Bounded inspection extracts credential values only, never whole request snapshots. */
    private function collect(#[SensitiveParameter] array $values, bool $sensitive, int $depth = 0): void
    {
        if ($depth > 32) {
            throw new InvalidArgumentException('API error privacy inspection exceeds the supported depth.');
        }
        foreach ($values as $key => $value) {
            if (++$this->inspected > 10000) {
                throw new InvalidArgumentException('API error privacy inspection exceeds the supported size.');
            }
            $secret = $sensitive || SensitiveKey::matches((string) $key);
            if (is_array($value)) $this->collect($value, $secret, $depth + 1);
            elseif ($secret && is_scalar($value) && (string) $value !== '') $this->secrets[] = (string) $value;
        }
    }

    private function redactArray(#[SensitiveParameter] array $values, bool $maskKeys): array
    {
        $safe = [];
        foreach ($values as $key => $value) {
            $safeKey = is_string($key) ? $this->text($key) : $key;
            if ($maskKeys && SensitiveKey::matches((string) $key)) $safe[$safeKey] = '[REDACTED]';
            elseif (is_array($value)) $safe[$safeKey] = $this->redactArray($value, $maskKeys);
            else $safe[$safeKey] = is_string($value) ? $this->text($value) : $value;
        }
        return $safe;
    }

    private function text(#[SensitiveParameter] string $value): string
    {
        return $this->redactor->redact($value, $this->request, $this->secrets);
    }
}
