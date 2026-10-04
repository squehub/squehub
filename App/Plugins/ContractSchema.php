<?php

declare(strict_types=1);

namespace App\Plugins;

/** Contract JSON Schema; App\Plugins\Schema remains the database schema API. */
class_alias(\App\Api\Contract\Schema::class, __NAMESPACE__ . '\\ContractSchema');
