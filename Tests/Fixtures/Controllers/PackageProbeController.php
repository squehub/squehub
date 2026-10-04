<?php

declare(strict_types=1);

namespace Project\Packages\PhaseFourPackage\Controllers;

final class PackageProbeController
{
    public function show(string $id): string
    {
        return 'package:' . $id;
    }
}
