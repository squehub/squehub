<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact type alias for the canonical \App\Http\JsonResponse; no wrapper state or conversion. */
class_alias(\App\Http\JsonResponse::class, __NAMESPACE__ . '\\JsonResponse');
