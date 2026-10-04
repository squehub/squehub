<?php

declare(strict_types=1);

/** Safe public API metadata; secrets and operational endpoint URLs stay elsewhere. */
return [
    'title' => 'SqueHub API',
    'version' => '1.0.0',
    'description' => null,
    'servers' => ['/'],
    // Coverage is visible by default without blocking small applications.
    // CI can opt into a case per public operation and warning failures.
    'verification' => [
        'require_case_per_operation' => false,
        'fail_on_warning' => false,
    ],
];
