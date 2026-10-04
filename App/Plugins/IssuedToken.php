<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for one-time token issuance data and safe metadata. */
class_alias(\App\Auth\Tokens\IssuedToken::class, __NAMESPACE__ . '\\IssuedToken');
