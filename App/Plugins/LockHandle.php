<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for the canonical bounded lease result. */
class_alias(\App\Locks\LockHandle::class, __NAMESPACE__ . '\\LockHandle');
