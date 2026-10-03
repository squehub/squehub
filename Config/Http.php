<?php

declare(strict_types=1);

/** A URL mount prefix; unrelated to the Application's filesystem base path. */
/** @var \App\Foundation\Environment $environment */
return [
    'base_path' => $environment->get('APP_BASE_PATH', ''),
];
