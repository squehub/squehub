<?php

declare(strict_types=1);

namespace App\Storage;

/** Optional driver observation avoids a second remote request after a committed stream write. */
interface StreamWriteSize
{
    public function streamWriteSize(): ?int;
}
