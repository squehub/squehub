<?php

declare(strict_types=1);

/** Route local development requests through the same guarded asset source as web entry points. */
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (in_array($method, ['GET', 'HEAD'], true)) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    $root = dirname(__DIR__);
    $mount = \App\Http\StaticAssetResponder::configuredMount($root);
    if ($mount !== null) {
        try {
            $request = new \App\Http\Request($method,
                (string) ($_SERVER['REQUEST_URI'] ?? '/'));
            $asset = (new \App\Http\StaticAssetResponder($root))->response($request, $mount);
            if ($asset !== null) {
                $asset->send($method === 'HEAD');
                return true;
            }
        } catch (\Throwable) {
            (new \App\Http\Response('Internal Server Error', 500,
                ['Content-Type' => 'text/plain; charset=UTF-8']))
                ->send($method === 'HEAD');
            return true;
        }
    }
}

require dirname(__DIR__) . '/public/index.php';
