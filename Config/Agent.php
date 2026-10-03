<?php

declare(strict_types=1);

/**
 * An explicit local Agent CLI starts in read-only mode. Additional supported
 * capabilities need bounded grants here; no remote transport or mutation is
 * enabled by configuration alone.
 */
return [
    'grants' => [],
];
