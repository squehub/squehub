<?php

declare(strict_types=1);

namespace App\Plugins;

/** Application-facing alias for the bounded MySQL transaction isolation enum. */
class_alias(\App\Database\TransactionIsolation::class, __NAMESPACE__ . '\\TransactionIsolation');
