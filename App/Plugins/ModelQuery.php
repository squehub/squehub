<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact type alias for the canonical \App\Database\ModelQuery; no wrapper state or conversion. */
class_alias(\App\Database\ModelQuery::class, __NAMESPACE__ . '\\ModelQuery');
