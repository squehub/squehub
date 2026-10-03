<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact type alias for the canonical \App\Authorization\AuthorizationDecision; no wrapper state or conversion. */
class_alias(\App\Authorization\AuthorizationDecision::class, __NAMESPACE__ . '\\AuthorizationDecision');
