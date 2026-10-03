<?php

declare(strict_types=1);

namespace Packages\LegacyCasePackage\Controllers;

final class LegacyPackageController
{
    public function show(string $id): string
    {
        return 'legacy-package:' . $id;
    }
}
