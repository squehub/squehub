<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact public exception type for invalid resource construction or representation. */
class_alias(\App\Api\ResourceException::class, __NAMESPACE__ . '\\ResourceException');
