<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact type alias for the canonical \App\Mail\MailAddress; no wrapper state or conversion. */
class_alias(\App\Mail\MailAddress::class, __NAMESPACE__ . '\\MailAddress');
