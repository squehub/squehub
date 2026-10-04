<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for a verified external principal. */
class_alias(\App\OAuth\ExternalIdentity::class, __NAMESPACE__ . '\\ExternalIdentity');
