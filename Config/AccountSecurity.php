<?php

declare(strict_types=1);

/** Token storage is lazy; applications create the table with a migration. */
return [
    'tokens' => ['driver' => 'database', 'table' => 'account_security_tokens'],
    'password_reset' => ['ttl' => 3600],
    'email_verification' => ['ttl' => 86400],
];
