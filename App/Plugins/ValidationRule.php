<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for custom rules accepted by the canonical Validator. */
class_alias(\App\Validation\ValidationRule::class, __NAMESPACE__ . '\\ValidationRule');
