<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact application-facing alias for the registered typed payload service. */
class_alias(\App\Data\TypedPayloadRegistry::class, __NAMESPACE__ . '\\TypedPayloadRegistry');
