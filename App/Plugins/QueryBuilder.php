<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact type alias for the canonical \App\Database\QueryBuilder; no wrapper state or conversion. */
class_alias(\App\Database\QueryBuilder::class, __NAMESPACE__ . '\\QueryBuilder');
