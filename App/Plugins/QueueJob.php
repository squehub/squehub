<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact interface alias, preserving canonical QueueJob type identity. */
class_alias(\App\Queue\QueueJob::class, __NAMESPACE__ . '\\QueueJob');
