<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for a source-aware template compiler diagnostic. */
class_alias(\App\View\Compiler\CompilerException::class, __NAMESPACE__ . '\\CompilerException');
