<?php

declare(strict_types=1);

namespace App\Validation;

use App\Container\Container;
use App\Database\Database;
use App\Database\Identifier;
use DateTimeImmutable;
use InvalidArgumentException;

/** Validates an input snapshot without changing values or performing I/O at rule setup. */
final class Validator
{
    private const NAMES = ['required', 'present', 'nullable', 'string', 'integer', 'numeric', 'boolean', 'array',
        'email', 'url', 'min', 'max', 'between', 'size', 'in', 'not_in', 'same', 'different', 'confirmed',
        'date', 'before', 'after', 'regex', 'unique', 'exists', 'file', 'image', 'mimes'];
    private const MIME_TYPES = [
        'pdf' => ['application/pdf'], 'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'],
        'gif' => ['image/gif'], 'webp' => ['image/webp'], 'txt' => ['text/plain'],
    ];
    private array $labels = [];
    /** @var array<string, array<string, true>> */
    private array $databaseCache = [];
    private string $currentPattern = '';

    public function __construct(private array $data, private ?Container $container = null)
    {
    }

    public function labels(array $labels): self
    {
        foreach ($labels as $field => $label) {
            if (!is_string($field) || !is_string($label) || $label === '') {
                throw new InvalidArgumentException('Validation labels must be non-empty strings keyed by field.');
            }
        }
        $this->labels = $labels;
        return $this;
    }

    public function check(array $rules, array $messages = []): ValidationResult
    {
        foreach ($messages as $key => $message) {
            if (!is_string($key) || !is_string($message)) {
                throw new InvalidArgumentException('Validation messages must be strings keyed by rule.');
            }
        }
        $this->databaseCache = [];
        $compiled = [];
        foreach ($rules as $field => $definition) {
            if (!is_string($field) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\.(?:\*|[A-Za-z0-9_]+))*\z/D', $field) !== 1) {
                throw new InvalidArgumentException('Invalid validation field path.');
            }
            $compiled[$field] = $this->parse($definition);
        }

        $errors = [];
        $accepted = [];
        foreach ($compiled as $pattern => $fieldRules) {
            $this->currentPattern = $pattern;
            foreach ($this->expand($pattern) as $field) {
                [$exists, $value] = $this->value($field);
                $nullable = in_array('nullable', array_column($fieldRules, 0), true);
                $numeric = in_array('integer', array_column($fieldRules, 0), true)
                    || in_array('numeric', array_column($fieldRules, 0), true);
                $absentFile = $value instanceof UploadedFile && $value->isAbsent();
                $typeFailed = false;
                foreach ($fieldRules as [$rule, $parameters]) {
                    if ($rule === 'nullable') {
                        continue;
                    }
                    if (!$exists && $rule !== 'required' && $rule !== 'present') {
                        continue;
                    }
                    if ($value === null && $nullable && $rule !== 'required' && $rule !== 'present') {
                        continue;
                    }
                    if ($absentFile && $nullable && $rule !== 'required' && $rule !== 'present') {
                        continue;
                    }
                    if ($typeFailed && in_array($rule, ['min', 'max', 'between', 'size'], true)) {
                        continue;
                    }
                    if ($typeFailed && in_array($rule, ['before', 'after', 'mimes'], true)) {
                        continue;
                    }
                    $message = $rule instanceof ValidationRule
                        ? ($rule instanceof UniqueRule && str_contains($pattern, '*')
                            ? $this->wildcardUnique($rule, $field, $value)
                            : $rule->validate($field, $value, $this->data))
                        : $this->test($rule, $parameters, $field, $value, $exists, $numeric);
                    if ($message !== null) {
                        $key = $rule instanceof UniqueRule ? 'unique' : ($rule instanceof ValidationRule ? 'custom' : $rule);
                        $errors[$field][] = $this->message($messages, $field, $pattern, $key, $message);
                        if (in_array($key, ['string', 'integer', 'numeric', 'boolean', 'array', 'file', 'image', 'date'], true)) {
                            $typeFailed = true;
                        }
                    }
                }
                if ($exists && !$absentFile && !isset($errors[$field])) {
                    $accepted[$field] = $value;
                }
            }
        }

        $validated = [];
        uksort($accepted, static fn (string $a, string $b): int => substr_count($a, '.') <=> substr_count($b, '.'));
        foreach ($accepted as $field => $value) {
            $descendant = false;
            foreach (array_keys($compiled) as $pattern) {
                if (str_starts_with($pattern, $field . '.')) {
                    $descendant = true;
                    break;
                }
            }
            $this->put($validated, $field, $descendant && is_array($value) ? [] : $value);
        }
        return new ValidationResult($errors, $validated);
    }

    private function parse(mixed $definition): array
    {
        if (is_string($definition)) {
            if (str_starts_with($definition, 'regex:') && @preg_match(substr($definition, 6), '') !== false) {
                $parts = [$definition];
            } elseif (preg_match('/(?:^|\|)regex:/', $definition) && str_contains(substr($definition, strpos($definition, 'regex:')), '|')) {
                throw new InvalidArgumentException('Regex patterns containing a pipe must use array rule form.');
            } else {
                $parts = explode('|', $definition);
            }
        } elseif (is_array($definition)) {
            $parts = $definition;
        } else {
            throw new InvalidArgumentException('Validation rules must be a string or array.');
        }
        if ($parts === []) {
            throw new InvalidArgumentException('Validation rule lists must not be empty.');
        }
        $rules = [];
        foreach ($parts as $part) {
            if ($part instanceof ValidationRule) {
                $rules[] = [$part, []];
                continue;
            }
            if (is_string($part) && is_subclass_of($part, ValidationRule::class)) {
                $rules[] = [($this->container ?? new Container())->make($part), []];
                continue;
            }
            if (!is_string($part) || $part === '') {
                throw new InvalidArgumentException('Invalid validation rule definition.');
            }
            [$name, $argument] = array_pad(explode(':', $part, 2), 2, null);
            if (!in_array($name, self::NAMES, true)) {
                throw new InvalidArgumentException("Unknown validation rule '{$name}'.");
            }
            $parameters = $argument === null ? [] : ($name === 'regex' ? [$argument] : explode(',', $argument));
            $count = count($parameters);
            $expected = match ($name) {
                'min', 'max', 'size', 'same', 'different', 'before', 'after', 'regex' => 1,
                'between', 'unique', 'exists' => 2,
                'in', 'not_in', 'mimes' => -1,
                default => 0,
            };
            if (($expected >= 0 && $count !== $expected) || ($expected === -1 && $count < 1)
                || in_array('', $parameters, true)) {
                throw new InvalidArgumentException("Invalid parameters for validation rule '{$name}'.");
            }
            if (in_array($name, ['min', 'max', 'size', 'between'], true)) {
                foreach ($parameters as $number) {
                    if (!is_numeric($number) || !is_finite((float) $number)) {
                        throw new InvalidArgumentException("Invalid size parameter for validation rule '{$name}'.");
                    }
                }
                if ($name === 'between' && (float) $parameters[0] > (float) $parameters[1]) {
                    throw new InvalidArgumentException('Between minimum must not exceed maximum.');
                }
            }
            if (in_array($name, ['unique', 'exists'], true)) {
                Identifier::simple($parameters[0]);
                Identifier::simple($parameters[1]);
            }
            if ($name === 'regex' && @preg_match($parameters[0], '') === false) {
                throw new InvalidArgumentException('Invalid regular expression validation rule.');
            }
            if ($name === 'mimes') {
                foreach ($parameters as $extension) {
                    if (!isset(self::MIME_TYPES[strtolower($extension)])) {
                        throw new InvalidArgumentException('Unsupported MIME extension.');
                    }
                }
            }
            $rules[] = [$name, $parameters];
        }
        return $rules;
    }

    /** Expand only existing wildcard parents; an empty array has no children to validate. */
    private function expand(string $pattern): array
    {
        $paths = [['', $this->data]];
        foreach (explode('.', $pattern) as $segment) {
            $next = [];
            foreach ($paths as [$path, $node]) {
                if ($segment === '*') {
                    if (is_array($node)) {
                        foreach ($node as $key => $child) {
                            $next[] = [ltrim($path . '.' . $key, '.'), $child];
                        }
                    }
                } else {
                    $next[] = [ltrim($path . '.' . $segment, '.'), is_array($node) ? ($node[$segment] ?? null) : null];
                }
            }
            $paths = $next;
        }
        return array_column($paths, 0);
    }

    private function value(string $field): array
    {
        $node = $this->data;
        foreach (explode('.', $field) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return [false, null];
            }
            $node = $node[$segment];
        }
        return [true, $node];
    }

    private function put(array &$output, string $field, mixed $value): void
    {
        $segments = explode('.', $field);
        $node =& $output;
        foreach ($segments as $segment) {
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                $node[$segment] = [];
            }
            $node =& $node[$segment];
        }
        $node = $value;
    }

    private function test(string $rule, array $params, string $field, mixed $value, bool $exists, bool $numeric): ?string
    {
        $label = $this->label($field);
        $base = "The {$label} field";
        if ($rule === 'required') return $exists && $value !== null && $value !== '' && $value !== [] && !($value instanceof UploadedFile && $value->isAbsent()) ? null : "$base is required.";
        if ($rule === 'present') return $exists ? null : "$base must be present.";
        if ($rule === 'string') return is_string($value) ? null : "$base must be a string.";
        if ($rule === 'integer') return (is_int($value) || (is_string($value) && filter_var($value, FILTER_VALIDATE_INT) !== false)) ? null : "$base must be an integer.";
        if ($rule === 'numeric') return ((is_int($value) || is_float($value) || is_string($value)) && is_numeric($value) && is_finite((float) $value)) ? null : "$base must be numeric.";
        if ($rule === 'boolean') return in_array($value, [true, false, 1, 0, '1', '0', 'true', 'false'], true) ? null : "$base must be a boolean.";
        if ($rule === 'array') return is_array($value) ? null : "$base must be an array.";
        if ($rule === 'email') return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? null : "$base must be a valid email address.";
        if ($rule === 'url') return is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true) ? null : "$base must be a valid HTTP URL.";
        if ($rule === 'date') return $this->date($value) !== null ? null : "$base must be a date in Y-m-d format.";
        if ($rule === 'file') return $value instanceof UploadedFile && $value->isValid() ? null : "$base must be a valid uploaded file.";
        if ($rule === 'image') return $value instanceof UploadedFile && $value->isImage() ? null : "$base must be a valid image.";
        if ($rule === 'mimes') {
            $allowed = [];
            foreach ($params as $extension) {
                array_push($allowed, ...self::MIME_TYPES[strtolower($extension)]);
            }
            return $value instanceof UploadedFile && in_array($value->mimeType(), $allowed, true) ? null : "$base has an unsupported file type.";
        }
        if (in_array($rule, ['min', 'max', 'between', 'size'], true)) {
            $size = $this->sizeOf($value, $numeric);
            if ($size === null) return "$base has an invalid size.";
            $pass = match ($rule) {
                'min' => $size >= (float) $params[0], 'max' => $size <= (float) $params[0],
                'size' => $size === (float) $params[0],
                default => $size >= (float) $params[0] && $size <= (float) $params[1],
            };
            $constraint = $rule === 'between' ? "between {$params[0]} and {$params[1]}" : ($rule === 'size' ? "exactly {$params[0]}" : ($rule === 'min' ? "at least {$params[0]}" : "at most {$params[0]}"));
            return $pass ? null : "$base must be {$constraint}.";
        }
        if ($rule === 'in' || $rule === 'not_in') {
            if (!is_scalar($value)) return "$base has an invalid value.";
            $found = in_array(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value, $params, true);
            return ($rule === 'in' ? $found : !$found) ? null : "$base has an invalid value.";
        }
        if ($rule === 'same' || $rule === 'different' || $rule === 'confirmed') {
            $other = $rule === 'confirmed' ? $field . '_confirmation' : $params[0];
            [$otherExists, $otherValue] = $this->value($other);
            $same = $otherExists && $value === $otherValue;
            return ($rule === 'different' ? $otherExists && !$same : $same) ? null : "$base does not satisfy {$rule}.";
        }
        if ($rule === 'before' || $rule === 'after') {
            $date = $this->date($value);
            [$hasOther, $other] = $this->value($params[0]);
            $compare = $this->date($hasOther ? $other : $params[0]);
            return $date !== null && $compare !== null && ($rule === 'before' ? $date < $compare : $date > $compare) ? null : "$base must be {$rule} {$params[0]}.";
        }
        if ($rule === 'regex') return is_string($value) && preg_match($params[0], $value) === 1 ? null : "$base has an invalid format.";
        if ($rule === 'unique' || $rule === 'exists') {
            if (!is_scalar($value)) return "$base has an invalid value.";
            $found = $this->databaseMatch($rule, $params, $value);
            return ($rule === 'exists' ? $found : !$found) ? null : ($rule === 'exists' ? "$base does not exist." : "$base is already in use.");
        }
        throw new InvalidArgumentException("Unknown validation rule '{$rule}'.");
    }

    /** Wildcard table lookups are batched in bounded IN queries, never one query per item. */
    private function databaseMatch(string $rule, array $params, mixed $value): bool
    {
        if (!str_contains($this->currentPattern, '*')) {
            return Database::manager()->table($params[0])->filter($params[1], $value)->exists();
        }
        $cacheKey = implode('|', [$this->currentPattern, $rule, ...$params]);
        if (!isset($this->databaseCache[$cacheKey])) {
            $values = [];
            foreach ($this->expand($this->currentPattern) as $field) {
                [$exists, $candidate] = $this->value($field);
                if ($exists && is_scalar($candidate)) {
                    $values[(string) $candidate] = $candidate;
                }
            }
            $this->databaseCache[$cacheKey] = DatabaseRuleLookup::matching(array_values($values), $params[0], $params[1]);
        }
        return isset($this->databaseCache[$cacheKey][(string) $value]);
    }

    private function wildcardUnique(UniqueRule $rule, string $field, mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return 'The ' . $this->label($field) . ' field is invalid.';
        }
        $key = 'object|' . spl_object_id($rule) . '|' . $this->currentPattern;
        if (!isset($this->databaseCache[$key])) {
            $values = [];
            foreach ($this->expand($this->currentPattern) as $candidateField) {
                [$exists, $candidate] = $this->value($candidateField);
                if ($exists && is_scalar($candidate)) {
                    $values[(string) $candidate] = $candidate;
                }
            }
            $this->databaseCache[$key] = $rule->existing(array_values($values));
        }
        return isset($this->databaseCache[$key][(string) $value])
            ? 'The ' . $this->label($field) . ' field is already in use.' : null;
    }

    private function sizeOf(mixed $value, bool $numeric): ?float
    {
        if ($value instanceof UploadedFile) return $value->isValid() ? $value->size() / 1024 : null;
        if (is_array($value)) return (float) count($value);
        if (is_int($value) || is_float($value)) return is_finite((float) $value) ? (float) $value : null;
        if (is_string($value)) {
            if ($numeric) return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
            return (float) (function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value));
        }
        return null;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $value) !== 1) return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    private function label(string $field): string
    {
        return $this->labels[$field] ?? str_replace(['_', '.'], [' ', ' '], $field);
    }

    private function message(array $messages, string $field, string $pattern, string $rule, string $default): string
    {
        return $messages[$field . '.' . $rule] ?? $messages[$pattern . '.' . $rule] ?? $messages[$rule] ?? $default;
    }
}
