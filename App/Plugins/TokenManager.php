<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for the Application-owned named token issuance service. */
class_alias(\App\Auth\Tokens\TokenManager::class, __NAMESPACE__ . '\\TokenManager');
