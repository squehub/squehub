<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact type alias for the canonical \App\Http\Response; no wrapper state or conversion. */
class_alias(\App\Http\Response::class, __NAMESPACE__ . '\\Response');
