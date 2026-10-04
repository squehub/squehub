<?php

declare(strict_types=1);

namespace App\Plugins;

/** Public type name for a scheduled webhook delivery identity. */
class_alias(\App\Webhooks\WebhookDeliveryTicket::class, __NAMESPACE__ . '\\WebhookDeliveryTicket');
