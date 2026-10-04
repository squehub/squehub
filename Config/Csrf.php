<?php

declare(strict_types=1);

/** Application-wide web CSRF policy; exclusions require explicit paths. */
return [
    'enabled' => true,
    'field' => '_csrf',
    'header' => 'X-CSRF-Token',
    'except' => [],
];
