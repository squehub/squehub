<?php

declare(strict_types=1);

namespace App\Plugins;

/** Public name for the authenticated, parsed SqueHub event value. */
class_alias(\App\Webhooks\VerifiedWebhook::class, __NAMESPACE__ . '\\VerifiedWebhook');
