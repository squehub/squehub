<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact application gateway to the Application-owned contract registry. */
class_alias(\App\Api\Contract\Contract::class, __NAMESPACE__ . '\\Contract');
