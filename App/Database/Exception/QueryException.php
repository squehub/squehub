<?php

declare(strict_types=1);

namespace App\Database\Exception;

/** Wraps statement preparation or execution failures without exposing SQL. */
class QueryException extends DatabaseException
{
}
