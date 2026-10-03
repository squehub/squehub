<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for stored token metadata without raw or hashed secret access. */
class_alias(\App\Auth\Tokens\TokenMetadata::class, __NAMESPACE__ . '\\TokenMetadata');
