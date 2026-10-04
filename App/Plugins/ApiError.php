<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact public type for intentional application errors handled by the HTTP boundary. */
class_alias(\App\Api\ApiError::class, __NAMESPACE__ . '\\ApiError');
