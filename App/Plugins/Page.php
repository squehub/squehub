<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact type alias for the canonical \App\Database\Pagination\Page; no wrapper state or conversion. */
class_alias(\App\Database\Pagination\Page::class, __NAMESPACE__ . '\\Page');
