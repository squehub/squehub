<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact outgoing response alias, distinct from incoming App\Plugins\Response. */
class_alias(\App\HttpClient\HttpResponse::class, __NAMESPACE__ . '\\HttpResponse');
