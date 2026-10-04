<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact type alias for the canonical \App\Http\Request; no wrapper state or conversion. */
class_alias(\App\Http\Request::class, __NAMESPACE__ . '\\Request');
