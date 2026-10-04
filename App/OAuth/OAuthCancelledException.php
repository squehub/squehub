<?php

declare(strict_types=1);

namespace App\OAuth;

/** A provider-reported user cancellation, distinct from an infrastructure failure. */
final class OAuthCancelledException extends OAuthException
{
}
