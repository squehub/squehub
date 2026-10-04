<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for a Container-resolved private-channel rule. */
class_alias(\App\Broadcasting\ChannelAuthorizer::class, __NAMESPACE__ . '\\ChannelAuthorizer');
