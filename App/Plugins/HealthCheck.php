<?php

declare(strict_types=1);

namespace App\Plugins;

/** Application-facing alias of the canonical package check contract. */
class_alias(\App\Health\HealthCheck::class, __NAMESPACE__ . '\\HealthCheck');
