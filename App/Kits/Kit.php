<?php

declare(strict_types=1);

namespace App\Kits;

/**
 * Trusted author hooks run only during an explicit Kit lifecycle apply.
 * Kit classes are never service providers or part of normal request boot.
 */
abstract class Kit
{
    public function beforeInstall(KitContext $context): void {}
    public function afterInstall(KitContext $context): void {}
    public function beforeEnable(KitContext $context): void {}
    public function afterEnable(KitContext $context): void {}
    public function beforeDisable(KitContext $context): void {}
    public function afterDisable(KitContext $context): void {}
    public function beforeUpgrade(KitContext $context): void {}
    public function afterUpgrade(KitContext $context): void {}
    public function beforeRemove(KitContext $context): void {}
    public function afterRemove(KitContext $context): void {}
}
