<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for channels accepted by NotificationManager. */
class_alias(\App\Notifications\NotificationChannel::class, __NAMESPACE__ . '\\NotificationChannel');
