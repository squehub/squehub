<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Contributions\ContributionOwner;
use App\Foundation\Application;
use App\Packages\PackageManager;
use App\Routing\MiddlewareRegistry;
use App\Support\RuntimeContext;
use DirectoryIterator;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Loads trusted application and package schedule definitions for CLI and Studio inspection.
 *
 * Directory spelling follows the current Project convention, with lowercase
 * variants accepted for existing projects. Both Project/<Package>/Scheduler
 * and Project/Packages/<Package>/Scheduler can contribute definitions. Neither
 * Application boot nor ordinary web requests load these files.
 */
final class ScheduleLoader
{
    public function load(Application $application): void
    {
        // Package inspection fixtures may load Schedule source solely to
        // verify contribution boundaries without registering Scheduler.
        if (!$application->container()->has(Scheduler::class)) {
            $this->loadSources($application);
            return;
        }
        $scheduler = $application->container()->make(Scheduler::class);
        if (!$scheduler->beginDefinitionSourceLoad()) return;
        try {
            $this->loadSources($application);
            $scheduler->completeDefinitionSourceLoad();
        } catch (Throwable $exception) {
            $scheduler->failDefinitionSourceLoad();
            throw $exception;
        }
    }

    /** Include trusted declaration files once; callbacks are registered, not run. */
    private function loadSources(Application $application): void
    {
        RuntimeContext::select($application);
        $project = $application->projectPath();
        $middleware = $application->container()->has(MiddlewareRegistry::class)
            ? $application->container()->make(MiddlewareRegistry::class) : null;
        $directories = [];

        $applicationSchedule = $this->existingDirectory($project, ['Scheduler', 'scheduler']);
        if ($applicationSchedule !== null) {
            $directories[] = [$applicationSchedule, new ContributionOwner('application', 'Project')];
        }

        // Some applications keep a package directly under Project. Only its
        // Scheduler directory is read; other package source remains untouched.
        foreach ($this->childDirectories($project) as $child) {
            $name = basename($child);
            if (strcasecmp($name, 'Scheduler') === 0 || strcasecmp($name, 'Packages') === 0) {
                continue;
            }
            $schedule = $this->existingDirectory($child, ['Scheduler', 'scheduler']);
            if ($schedule !== null) {
                $directories[] = [$schedule, new ContributionOwner('application', 'Project')];
            }
        }

        foreach ($application->container()->make(PackageManager::class)->active() as $package) {
            $schedule = $this->existingDirectory($package->path(), ['Scheduler', 'scheduler']);
            if ($schedule !== null) {
                $directories[] = [$schedule, new ContributionOwner('package', $package->name())];
            }
        }

        // Stable ordering makes duplicate-name failures reproducible. Nested
        // PHP files are definitions too; links are not followed outside roots.
        foreach ($directories as [$directory, $owner]) {
            $files = [];
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo && $file->isFile() && !$file->isLink()
                    && strcasecmp($file->getExtension(), 'php') === 0) {
                    $files[] = $file->getPathname();
                }
            }
            usort($files, 'strnatcasecmp');
            foreach ($files as $file) {
                $relative = str_replace('\\', '/', substr($file, strlen($application->basePath()) + 1));
                $application->contributions()->beginOwner($owner, $relative);
                try {
                    if ($owner->type !== 'package') {
                        require $file;
                        continue;
                    }
                    // Package definitions have the same additive boundaries as entry hooks.
                    $application->config()->beginPackageContext($owner->name);
                    try {
                        $middleware?->beginPackageContext($owner->name);
                        try {
                            require $file;
                        } finally {
                            $middleware?->endPackageContext();
                        }
                    } finally {
                        $application->config()->endPackageContext();
                    }
                } finally {
                    $application->contributions()->endOwner();
                }
            }
        }
    }

    /** @return list<string> */
    private function childDirectories(string $parent): array
    {
        $directories = [];
        if (!is_dir($parent)) {
            return $directories;
        }
        foreach (new DirectoryIterator($parent) as $entry) {
            if (!$entry->isDot() && $entry->isDir() && !$entry->isLink()) {
                $directories[] = $entry->getPathname();
            }
        }
        usort($directories, 'strnatcasecmp');
        return $directories;
    }

    /** @param list<string> $names */
    private function existingDirectory(string $parent, array $names): ?string
    {
        foreach ($names as $name) {
            $candidate = $parent . '/' . $name;
            if (is_dir($candidate) && !is_link($candidate)) {
                return $candidate;
            }
        }
        return null;
    }
}
