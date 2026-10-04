<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for a missing selected Fragment diagnostic. */
class_alias(\App\View\FragmentNotFoundException::class, __NAMESPACE__ . '\\FragmentNotFoundException');
