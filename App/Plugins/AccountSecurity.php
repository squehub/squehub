<?php

declare(strict_types=1);

namespace App\Plugins;

use App\AccountSecurity\AccountSecurity as SecurityGateway;
use App\AccountSecurity\AccountSecurityManager;

/** Entry to the current Application's credential and account-token service. */
final class AccountSecurity
{
    public static function manager(): AccountSecurityManager { return SecurityGateway::manager(); }
}
