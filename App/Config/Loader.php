<?php

declare(strict_types=1);

namespace App\Config;

use App\Foundation\Environment;

/** Loads array-returning config files with $environment available in their scope. */
final class Loader
{
    public function load(string $directory, Environment $environment, Repository $repository): void
    {
        if (!is_dir($directory) || !is_readable($directory)) {
            throw new ConfigurationException('Application config directory is missing or unreadable.');
        }

        $files = glob($directory . '/*.php');
        if ($files === false) {
            throw new ConfigurationException('Application config directory could not be scanned.');
        }
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $filename = pathinfo($file, PATHINFO_FILENAME);
            // The standard OAuth acronym keeps its canonical file casing,
            // while application code reads the predictable `oauth` key.
            $name = $filename === 'OAuth' ? 'oauth' : lcfirst($filename);
            // Debug.php is a legacy executable error-handler script, not config data.
            if ($name === 'debug' || !preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $name)) {
                continue;
            }
            $values = $this->read($file, $environment);
            if (!is_array($values)) {
                throw new ConfigurationException("Configuration file '{$name}.php' must return an array.");
            }
            $repository->set($name, $values);
        }
    }

    private function read(string $file, Environment $environment): mixed
    {
        // require inherits this method's local scope, including $environment.
        return require $file;
    }
}
