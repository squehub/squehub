<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for the real-Kernel testing client. */
class_alias(\App\Testing\TestClient::class, __NAMESPACE__ . '\\TestClient');
