<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact type alias for the canonical \App\Validation\UniqueRule; no wrapper state or conversion. */
class_alias(\App\Validation\UniqueRule::class, __NAMESPACE__ . '\\UniqueRule');
