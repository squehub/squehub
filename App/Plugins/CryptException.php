<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact catch type for safe cryptographic failures. */
class_alias(\App\Cryptography\CryptException::class, __NAMESPACE__ . '\\CryptException');
