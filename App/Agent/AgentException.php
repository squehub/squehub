<?php

declare(strict_types=1);

namespace App\Agent;

use RuntimeException;

/** A safe Agent boundary failure; source, credentials, and raw tool input stay private. */
final class AgentException extends RuntimeException
{
}
