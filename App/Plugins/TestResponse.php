<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for the PHPUnit assertions on an HTTP Response. */
class_alias(\App\Testing\TestResponse::class, __NAMESPACE__ . '\\TestResponse');
