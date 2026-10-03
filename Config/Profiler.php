<?php

declare(strict_types=1);

/**
 * Development profiles are explicitly opt-in and never follow APP_DEBUG.
 * The provider also requires APP_ENV=development before capture can start.
 */
/** @var \App\Foundation\Environment $environment */
return [
    'enabled' => $environment->boolean('PROFILER_ENABLED', false),
    'store' => 'file', // file or array
    'max_profiles' => 100,
    'max_age_seconds' => 86400,
    'max_events' => 256,
    'max_profile_bytes' => 65536,
    'max_bytes' => 5242880,
];
