<?php

declare(strict_types=1);

namespace App\Frontend;

use App\Http\Exception\NotFoundHttpException;
use App\Http\FileResponse;
use App\Http\Request;
use App\Http\Response;
use Closure;

/**
 * Streams enabled Package assets through the normal HTTP response boundary.
 *
 * These files live outside public/, and the reserved URL is resolved only
 * after the Kernel has validated the application mount. A missing or disabled
 * Package returns the ordinary 404; it cannot fall through to an SPA shell.
 */
final class ServePackageAsset
{
    public function __construct(private PackageAssetSource $source)
    {
    }

    /** @param Closure(Request):Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $path = $request->path();
        if (!str_starts_with($path, '/assets/Packages/')) {
            return $next($request);
        }
        if (!in_array($request->method(), ['GET', 'HEAD'], true)
            || preg_match('~\A/assets/Packages/([A-Z][A-Za-z0-9_]*)/(.+)\z~D', $path, $matches) !== 1) {
            throw new NotFoundHttpException();
        }
        $file = $this->source->resolve($matches[1], $matches[2]);
        $type = PackageAssetSource::contentType($matches[2]);
        if ($file === null || $type === null) {
            throw new NotFoundHttpException();
        }
        // Activation can change between requests, so do not cache the private
        // source response across a disable or Package replacement.
        return (new FileResponse($file, basename($file), $type, false, $request))
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    }
}
