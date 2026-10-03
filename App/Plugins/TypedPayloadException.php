<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact application-facing alias for a safe typed payload failure. */
class_alias(\App\Data\TypedPayloadException::class, __NAMESPACE__ . '\\TypedPayloadException');
