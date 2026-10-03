<?php

declare(strict_types=1);

namespace App\Database\Schema;

use App\Database\Exception\DatabaseException;

/** Reports an invalid schema definition or a failed schema operation. */
class SchemaException extends DatabaseException
{
}
