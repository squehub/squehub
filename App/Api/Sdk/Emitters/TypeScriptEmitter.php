<?php

declare(strict_types=1);

namespace App\Api\Sdk\Emitters;

use App\Api\Sdk\SdkModel;

/** Emits a standalone Fetch-based TypeScript client from the normalized SDK model. */
final class TypeScriptEmitter extends EcmaScriptEmitter
{
    /** @return array<string, string> Relative generated path => file contents. */
    public function emit(SdkModel $model): array
    {
        return $this->render($model, true);
    }
}
