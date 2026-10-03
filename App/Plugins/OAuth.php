<?php

declare(strict_types=1);

namespace App\Plugins;

use App\OAuth\OAuth as Gateway;
use App\OAuth\OAuthManager;
use App\OAuth\OAuthProvider;

/** Application-facing entry for configured OpenID Connect login providers. */
final class OAuth
{
    public static function manager(): OAuthManager { return Gateway::manager(); }
    public static function provider(string $name): OAuthProvider { return Gateway::provider($name); }
}
