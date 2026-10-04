<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact type alias for the canonical \App\Validation\UploadedFile; no wrapper state or conversion. */
class_alias(\App\Validation\UploadedFile::class, __NAMESPACE__ . '\\UploadedFile');
