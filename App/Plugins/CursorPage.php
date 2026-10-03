<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact type alias for the canonical cursor page; no wrapper state or conversion. */
class_alias(\App\Database\Pagination\CursorPage::class, __NAMESPACE__ . '\\CursorPage');
