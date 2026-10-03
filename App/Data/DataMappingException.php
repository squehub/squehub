<?php

declare(strict_types=1);

namespace App\Data;

use RuntimeException;
use Throwable;

/**
 * Reports a mapping failure without retaining or printing submitted values.
 *
 * Missing and malformed fields are request input failures. Definition and
 * constructor failures remain developer errors so a browser cannot turn an
 * application invariant into an apparently valid field validation message.
 */
final class DataMappingException extends RuntimeException
{
    private const INPUT_REASONS = ['missing_field', 'invalid_type', 'invalid_enum'];

    public function __construct(
        private readonly string $reason,
        private readonly ?string $field = null,
        ?Throwable $previous = null
    ) {
        $description = match ($reason) {
            'missing_field' => 'A required data field is missing.',
            'invalid_type' => 'A data field has an invalid type.',
            'invalid_enum' => 'A data field has an invalid enum value.',
            'unsupported_type' => 'A data constructor uses an unsupported type.',
            'invalid_class' => 'The selected data class cannot be constructed.',
            'invalid_nested' => 'A nested data declaration is invalid.',
            'depth_limit' => 'Nested data exceeds the supported depth.',
            'constructor_failure' => 'The data constructor rejected its arguments.',
            'rules_missing' => 'The data class must provide validation rules.',
            default => 'Data mapping failed.',
        };
        if ($field !== null) {
            $description = rtrim($description, '.') . ' (' . $field . ').';
        }
        parent::__construct($description, 0, $previous);
    }

    public function reason(): string { return $this->reason; }
    public function field(): ?string { return $this->field; }

    /** Only input failures may become ordinary HTTP validation errors. */
    public function isInputFailure(): bool
    {
        return $this->field !== null && in_array($this->reason, self::INPUT_REASONS, true);
    }

    /** Field names come from trusted constructor declarations, not submitted values. */
    public function validationMessage(): string
    {
        return $this->reason === 'missing_field'
            ? 'The ' . $this->field . ' field is required.'
            : 'The ' . $this->field . ' field has an invalid value.';
    }
}
