<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Health\Health as Gateway;
use App\Health\HealthManager;
use App\Health\HealthReport;

/** Stable application gateway for package checks and current health reports. */
final class Health
{
    public static function manager(): HealthManager { return Gateway::manager(); }
    public static function live(): HealthReport { return Gateway::live(); }
    public static function ready(): HealthReport { return Gateway::ready(); }
    public static function doctor(): HealthReport { return Gateway::doctor(); }
    public static function infrastructure(): array { return Gateway::infrastructure(); }
}
