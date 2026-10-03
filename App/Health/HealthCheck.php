<?php

declare(strict_types=1);

namespace App\Health;

/** Package checks are resolved lazily through the Application container. */
interface HealthCheck
{
    public function check(): HealthResult;
}
