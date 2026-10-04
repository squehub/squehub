<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact type alias for the canonical \App\Validation\ValidationResult; no wrapper state or conversion. */
class_alias(\App\Validation\ValidationResult::class, __NAMESPACE__ . '\\ValidationResult');
