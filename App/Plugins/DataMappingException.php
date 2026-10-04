<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for safe typed-data mapping failures. */
class_alias(\App\Data\DataMappingException::class, __NAMESPACE__ . '\\DataMappingException');
