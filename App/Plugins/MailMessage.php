<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact type alias for the canonical \App\Mail\MailMessage; no wrapper state or conversion. */
class_alias(\App\Mail\MailMessage::class, __NAMESPACE__ . '\\MailMessage');
