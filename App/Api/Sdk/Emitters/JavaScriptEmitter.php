<?php

declare(strict_types=1);

namespace App\Api\Sdk\Emitters;

use App\Api\Sdk\SdkModel;

/** Emits native ES modules and JSDoc; no TypeScript build step is involved. */
final class JavaScriptEmitter extends EcmaScriptEmitter
{
    /** @return array<string, string> Relative generated path => file contents. */
    public function emit(SdkModel $model): array
    {
        return $this->render($model, false);
    }
}
