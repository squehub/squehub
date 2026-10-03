<?php

declare(strict_types=1);

namespace App\Data;

/** Optional rule declaration for Request::validatedAs(ClassName::class). */
interface ValidatedData
{
    /** @return array<string, string|array> Rules consumed by the existing Validator. */
    public static function rules(): array;
}
