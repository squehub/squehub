<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact type alias for the canonical \App\Database\Schema\Schema; no wrapper state or conversion. */
class_alias(\App\Database\Schema\Schema::class, __NAMESPACE__ . '\\Schema');
