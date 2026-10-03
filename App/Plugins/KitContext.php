<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact application-facing alias; hook overrides keep the same parameter type. */
class_alias(\App\Kits\KitContext::class, __NAMESPACE__ . '\\KitContext');
