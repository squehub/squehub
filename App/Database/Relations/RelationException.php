<?php

declare(strict_types=1);

namespace App\Database\Relations;

use LogicException;

/** Reports an invalid model relationship definition or lookup. */
final class RelationException extends LogicException
{
}
