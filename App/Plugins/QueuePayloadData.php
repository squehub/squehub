<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact application-facing alias for an explicitly durable typed value. */
class_alias(\App\Data\QueuePayloadData::class, __NAMESPACE__ . '\\QueuePayloadData');
