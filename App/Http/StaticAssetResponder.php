<?php

declare(strict_types=1);

namespace App\Http;

use App\Foundation\Environment;
use App\Foundation\UrlBasePath;
use Throwable;

/**
 * Resolves ordinary public files from the Application's root Assets directory.
 *
 * A public/ document root cannot read a sibling Assets directory as a static
 * file. Both web entry points and the development router therefore use this
 * bounded fallback. Package URLs remain owned by the activation middleware.
 * Existing public/assets files remain a compatibility source for generated
 * frontend output; an exact root Assets match always takes precedence.
 */
final class StaticAssetResponder
{
    private const TYPES = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'text/javascript; charset=UTF-8',
        'mjs' => 'text/javascript; charset=UTF-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'eot' => 'application/vnd.ms-fontobject',
        'json' => 'application/json; charset=UTF-8',
        'webmanifest' => 'application/manifest+json; charset=UTF-8',
        'map' => 'application/json; charset=UTF-8',
        'txt' => 'text/plain; charset=UTF-8',
        'xml' => 'application/xml; charset=UTF-8',
    ];

    public function __construct(private string $applicationRoot)
    {
    }

    /** Read the same configured URL mount as the Application without booting it. */
    public static function configuredMount(string $applicationRoot): ?UrlBasePath
    {
        try {
            $configurationFile = $applicationRoot . '/Config/Http.php';
            if (!is_file($configurationFile) || !is_readable($configurationFile)) {
                // Setup fixtures and incomplete installs may not have HTTP
                // configuration yet; let their normal web boundary respond.
                return null;
            }
            $environment = new Environment($applicationRoot);
            $configuration = require $configurationFile;
            $configured = is_array($configuration) ? ($configuration['base_path'] ?? '') : null;
            return is_string($configured) ? new UrlBasePath($configured) : null;
        } catch (Throwable) {
            // Invalid configuration belongs to the normal web error boundary.
            return null;
        }
    }

    public function response(Request $request, UrlBasePath $mount): ?FileResponse
    {
        if (!in_array($request->transportMethod(), ['GET', 'HEAD'], true)) {
            return null;
        }
        $path = $mount->strip($request->rawPath());
        if ($path === null || strlen($path) > 1024
            || preg_match('~\A/assets/((?:[A-Za-z0-9_-][A-Za-z0-9._-]*/)*[A-Za-z0-9_-][A-Za-z0-9._-]*\.([A-Za-z0-9]+))\z~D', $path, $match) !== 1
            || preg_match('~\Aassets/Packages(?:/|$)~iD', ltrim($path, '/')) === 1) {
            return null;
        }
        $type = self::TYPES[strtolower($match[2])] ?? null;
        if ($type === null) {
            return null;
        }

        foreach (['Assets', 'public/assets'] as $directory) {
            $file = $this->containedFile($directory, $match[1]);
            if ($file !== null) {
                return (new FileResponse($file, basename($file), $type, false, $request))
                    ->withHeader('X-Content-Type-Options', 'nosniff')
                    ->withHeader('Cache-Control', 'public, max-age=300')
                    ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox");
            }
        }
        return null;
    }

    /** Match each segment exactly and refuse links before realpath containment. */
    private function containedFile(string $directory, string $relative): ?string
    {
        $application = realpath($this->applicationRoot);
        if ($application === false || !is_dir($application)) {
            return null;
        }
        $current = $application;
        // Checking the public/ intermediate segment matters: otherwise a
        // linked public directory could make the fallback leave this release.
        foreach (array_merge(explode('/', $directory), explode('/', $relative)) as $segment) {
            $entries = @scandir($current);
            if ($entries === false || !in_array($segment, $entries, true)) {
                return null;
            }
            $current .= '/' . $segment;
            if (is_link($current)) {
                return null;
            }
        }
        $file = realpath($current);
        if ($file === false || !is_file($file) || !is_readable($file)) {
            return null;
        }
        $normalizedRoot = rtrim(str_replace('\\', '/', $application), '/') . '/';
        $normalizedFile = str_replace('\\', '/', $file);
        $inside = PHP_OS_FAMILY === 'Windows'
            ? strncasecmp($normalizedFile, $normalizedRoot, strlen($normalizedRoot)) === 0
            : str_starts_with($normalizedFile, $normalizedRoot);
        return $inside ? $file : null;
    }
}
