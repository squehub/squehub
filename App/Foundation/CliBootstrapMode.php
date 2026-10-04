<?php

declare(strict_types=1);

namespace App\Foundation;

use App\Packages\PackageName;

/**
 * Select a safe Package boot mode before CLI providers register. Package and
 * Kit management commands inspect source and state without executing Package
 * providers or Kit lifecycle hooks during command bootstrap.
 */
final class CliBootstrapMode
{
    /** @param list<string> $arguments Complete CLI argv, including script name. */
    public static function configure(Application $app, array $arguments): void
    {
        $command = null;
        $commandIndex = null;
        foreach (array_slice($arguments, 1, null, true) as $index => $argument) {
            if (str_starts_with($argument, '-')) { continue; }
            $command = $argument;
            $commandIndex = $index;
            break;
        }
        $informational = array_intersect(['--help', '-h', '--version', '-V'], $arguments) !== [];
        if ($command === 'config:cache' && in_array('--preview', $arguments, true)) {
            // A reviewed cache plan must not run executable Config PHP or
            // provider boot code before the command performs its inspection.
            $app->inspectConfigurationOnly();
            return;
        }
        $verifyNames = [];
        foreach (array_slice($arguments, ($commandIndex ?? 0) + 1) as $argument) {
            if (!str_starts_with($argument, '-')) { $verifyNames[] = $argument; }
        }
        if ($command === 'package:verify' && !$informational && count($verifyNames) === 1
            && PackageName::valid($verifyNames[0])) {
            // Verification explicitly executes enabled Package definitions.
            $app->verifyPackagesOnly();
            return;
        }
        if (in_array($command, ['config:cache', 'config:clear', 'route:clear', 'studio'], true)) {
            // Recovery and inspection commands must boot even when an active
            // configuration artifact is corrupt or belongs to another build.
            $app->ignoreConfigCache();
        }
        if ($command === 'view:clear'
            || $command === 'config:clear'
            || $command === 'config:cache'
            || $command === 'route:clear'
            || ($command === 'route:cache' && $informational)
            || $command === 'studio'
            || ($command === 'view:cache' && $informational)
            || $command === null || in_array($command, [
            'help', 'h', 'list', 'agent:mcp', 'agent:status',
            'package:list', 'package:inspect', 'package:verify', 'package:install', 'package:enable',
            'package:disable', 'package:upgrade', 'package:remove', 'doctor', 'setup',
            'kit:list', 'kit:inspect', 'kit:install', 'kit:enable', 'kit:disable',
            'kit:upgrade', 'kit:remove',
            'bundle:export', 'bundle:inspect', 'bundle:import',
            'upgrade:check',
            'recovery:plan',
            'migrate:plan',
            'make:controller', 'make:middleware', 'make:migration', 'make:model', 'make:seeder',
            'make:feature',
            'profile:inspect', 'profile:apply', 'profile:remove',
            'frontend:status', 'frontend:build',
        ], true)) {
            $app->inspectPackagesOnly();
        }
    }
}
