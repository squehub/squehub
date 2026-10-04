<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact type alias for the canonical \App\AccountSecurity\SecurityToken; no wrapper state or conversion. */
class_alias(\App\AccountSecurity\SecurityToken::class, __NAMESPACE__ . '\\SecurityToken');
