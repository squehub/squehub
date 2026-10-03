<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for an invalid caller-supplied Fragment name. */
class_alias(\App\View\InvalidFragmentNameException::class, __NAMESPACE__ . '\\InvalidFragmentNameException');
