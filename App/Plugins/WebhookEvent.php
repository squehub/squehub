<?php

declare(strict_types=1);

namespace App\Plugins;

/** Public type name for one immutable canonical SqueHub webhook event. */
class_alias(\App\Webhooks\WebhookEvent::class, __NAMESPACE__ . '\\WebhookEvent');
