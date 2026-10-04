<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact type alias for the canonical \App\Database\Collections\ModelCollection; no wrapper state or conversion. */
class_alias(\App\Database\Collections\ModelCollection::class, __NAMESPACE__ . '\\ModelCollection');
