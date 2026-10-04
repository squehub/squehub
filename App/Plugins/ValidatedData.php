<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for data classes that declare their Request validation rules. */
class_alias(\App\Data\ValidatedData::class, __NAMESPACE__ . '\\ValidatedData');
