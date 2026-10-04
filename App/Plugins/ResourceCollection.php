<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact public type for collections returned by ApiResource::collection(). */
class_alias(\App\Api\ResourceCollection::class, __NAMESPACE__ . '\\ResourceCollection');
