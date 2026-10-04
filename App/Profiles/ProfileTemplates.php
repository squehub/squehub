<?php

declare(strict_types=1);

namespace App\Profiles;

/**
 * Transparent optional frontend source. The PHP View is the document shell;
 * Vite compiles only the module entry and never owns a second HTML document.
 */
final class ProfileTemplates
{
    /** @return array<string,string> Application-relative path => complete source. */
    public static function files(string $name): array
    {
        if (!in_array($name, ['vite', 'react', 'vue'], true)) {
            throw new ProfileException('Unknown frontend profile.');
        }
        $entry = $name === 'react' ? 'Src/Main.jsx' : 'Src/Main.js';
        $dependencies = match ($name) {
            'react' => ['react' => '^19.2.0', 'react-dom' => '^19.2.0'],
            'vue' => ['vue' => '^3.5.0'],
            default => [],
        };
        $devDependencies = ['vite' => '^7.3.0'];
        if ($name === 'react') $devDependencies['@vitejs/plugin-react'] = '^5.1.0';
        if ($name === 'vue') $devDependencies['@vitejs/plugin-vue'] = '^6.0.0';
        $package = json_encode([
            'name' => 'squehub-frontend', 'private' => true, 'type' => 'module',
            'scripts' => ['dev' => 'vite', 'build' => 'vite build'],
            'dependencies' => $dependencies, 'devDependencies' => $devDependencies,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $plugin = match ($name) {
            'react' => "import react from '@vitejs/plugin-react';\n",
            'vue' => "import vue from '@vitejs/plugin-vue';\n",
            default => '',
        };
        $plugins = match ($name) {
            'react' => "  plugins: [react()],\n",
            'vue' => "  plugins: [vue()],\n",
            default => '',
        };
        $vite = "import { defineConfig } from 'vite';\n" . $plugin . "\n"
            . "// PHP serves the document and APIs; Vite serves only local modules/HMR.\n"
            . "const backend = process.env.SQUEHUB_BACKEND_ORIGIN || 'http://127.0.0.1:8000';\n"
            . "const parsed = new URL(backend);\n"
            . "if (parsed.protocol !== 'http:' || !['localhost', '127.0.0.1'].includes(parsed.hostname)\n"
            . "    || !parsed.port || parsed.pathname !== '/' || parsed.search || parsed.hash) {\n"
            . "  throw new Error('Vite backend must be a loopback HTTP origin.');\n"
            . "}\n"
            . "const mount = process.env.SQUEHUB_BASE_PATH || '';\n"
            . "if (mount && (mount.length > 1024\n"
            . "    || !/^\\/[A-Za-z0-9._~-]+(?:\\/[A-Za-z0-9._~-]+)*$/.test(mount))) {\n"
            . "  throw new Error('SqueHub base path is invalid.');\n"
            . "}\n\n"
            . "export default defineConfig({\n" . $plugins
            . "  base: './',\n"
            . "  server: { host: '127.0.0.1', strictPort: true, proxy: {\n"
            . "    '/api': { target: backend, changeOrigin: false,\n"
            . "      rewrite: (url) => mount + url }\n"
            . "  } },\n"
            . "  build: { outDir: '../../public/assets/build', emptyOutDir: true, manifest: true,\n"
            . "    rollupOptions: { input: '" . $entry . "' } },\n"
            . "});\n";
        $api = <<<'JS'
            // Resolve application-relative API paths against SqueHub's public mount.
            // Session requests remain same-origin; unsafe methods carry the CSRF token.
            export async function squehubFetch(path, options = {}) {
              if (typeof path !== 'string' || !path.startsWith('/') || path.startsWith('//')) {
                throw new TypeError('Expected an application-relative API path.');
              }
              const mount = document.querySelector('meta[name="squehub-base-path"]')?.content || '/';
              const base = mount.replace(/\/$/, '');
              const method = String(options.method || 'GET').toUpperCase();
              const headers = new Headers(options.headers || {});
              if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) {
                const token = document.querySelector('meta[name="csrf-token"]')?.content;
                const csrfHeader = document.querySelector('meta[name="squehub-csrf-header"]')?.content;
                if (token && csrfHeader) headers.set(csrfHeader, token);
              }
              return fetch(`${base}${path}`, { ...options, headers, credentials: 'same-origin' });
            }
            JS;
        $shell = <<<'VIEW'
            <!doctype html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <meta name="csrf-token" content="{{ csrf_token() }}">
                <meta name="squehub-csrf-header" content="{{ config('csrf.header', 'X-CSRF-Token') }}">
                <meta name="squehub-base-path" content="{{ asset('/') }}">
                <title>SqueHub frontend</title>
                @frontend('app')
                @stack('head')
                @stack('styles')
            </head>
            <body>
                <div id="squehub-frontend"></div>
                @stack('scripts')
            </body>
            </html>
            VIEW;
        $route = <<<'PHP'
            <?php

            /** The ordinary backend route returns the same CSRF-safe shell as SPA navigation. */
            use App\Plugins\Route;
            use App\Plugins\View;

            Route::path('/frontend')
                ->get(static fn () => View::response('Frontend.App', headers: [
                    'Cache-Control' => 'private, no-store',
                ]))
                ->named('frontend.app');
            PHP;
        $readme = "# SqueHub {$name} frontend\n\n"
            . "The PHP View at `Project/Views/Frontend/App.squehub.php` owns the document, CSRF token, and asset stacks. "
            . "This directory owns the optional module source.\n\n"
            . "Run `npm install` here, then `php squehub dev --frontend` from the application root. "
            . "Run `php squehub frontend:build` before production deployment. "
            . "Do not commit `node_modules/`. The generated entry is `{$entry}`.\n";
        $files = [
            'Project/Frontend/.gitignore' => "node_modules/\n",
            'Project/Frontend/README.md' => $readme,
            'Project/Frontend/package.json' => $package,
            'Project/Frontend/vite.config.mjs' => $vite,
            'Project/Frontend/Src/Api.js' => $api . "\n",
            'Project/Frontend/Src/App.css' => "body { margin: 0; font-family: system-ui, sans-serif; }\n",
            'Project/Views/Frontend/App.squehub.php' => $shell . "\n",
            'Project/Routes/Frontend.php' => $route . "\n",
        ];
        if ($name === 'react') {
            $files['Project/Frontend/Src/Main.jsx'] = <<<'JS'
                import React from 'react';
                import { createRoot } from 'react-dom/client';
                import './App.css';

                function App() {
                  return <main><h1>Welcome to SqueHub</h1><p>React is ready.</p></main>;
                }

                createRoot(document.getElementById('squehub-frontend')).render(<App />);
                JS;
        } elseif ($name === 'vue') {
            $files['Project/Frontend/Src/Main.js'] = <<<'JS'
                import { createApp } from 'vue';
                import App from './App.vue';
                import './App.css';

                createApp(App).mount('#squehub-frontend');
                JS;
            $files['Project/Frontend/Src/App.vue'] = <<<'VUE'
                <template>
                  <main><h1>Welcome to SqueHub</h1><p>Vue is ready.</p></main>
                </template>
                VUE;
        } else {
            $files['Project/Frontend/Src/Main.js'] = <<<'JS'
                import './App.css';

                document.getElementById('squehub-frontend').innerHTML =
                  '<main><h1>Welcome to SqueHub</h1><p>Vite is ready.</p></main>';
                JS;
        }
        foreach ($files as &$content) {
            if (!str_ends_with($content, "\n")) $content .= "\n";
        }
        unset($content);
        ksort($files, SORT_STRING);
        return $files;
    }
}
