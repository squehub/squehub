<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact type alias for the canonical \App\Database\Schema\Table; no wrapper state or conversion. */
class_alias(\App\Database\Schema\Table::class, __NAMESPACE__ . '\\Table');
