<?php

declare(strict_types=1);

namespace App\Http;

/** Renders a safe, uncached setup response before Application bootstrap. */
final class EnvironmentSetupResponse
{
    public static function forRequest(Request $request, string $notice): Response
    {
        $headers = ['Cache-Control' => 'no-store'];
        if ($request->expectsJson()) {
            return new JsonResponse(['message' => $notice], 503, $headers);
        }

        $message = htmlspecialchars($notice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // The response is self-contained because it runs before views and
        // application assets can be resolved during normal bootstrap.
        $html = <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
              <meta charset="UTF-8">
              <meta name="viewport" content="width=device-width, initial-scale=1">
              <meta name="theme-color" content="#3782ab">
              <title>SqueHub setup required</title>
              <style>
                * { box-sizing: border-box; }
                body {
                  margin: 0;
                  min-height: 100vh;
                  display: grid;
                  place-items: center;
                  padding: 28px 18px;
                  background: #3782ab;
                  color: #17364b;
                  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
                  line-height: 1.55;
                }
                main {
                  width: min(100%, 680px);
                  padding: clamp(26px, 5vw, 48px);
                  border-radius: 20px;
                  background: #fff;
                  box-shadow: 0 24px 70px rgba(12, 49, 72, .23);
                }
                .brand { display: flex; align-items: center; gap: 11px; color: #236990; font-weight: 800; letter-spacing: .03em; }
                .brand-mark { display: grid; place-items: center; width: 34px; height: 34px; border-radius: 10px; background: #3782ab; color: #fff; font-size: 20px; }
                .eyebrow { margin: 34px 0 8px; color: #28759e; font-size: .75rem; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; }
                h1 { margin: 0; color: #14354b; font-size: clamp(1.9rem, 5vw, 2.65rem); line-height: 1.15; letter-spacing: -.035em; }
                .lead { margin: 18px 0 0; color: #455c6b; font-size: 1.05rem; }
                .steps { margin: 30px 0 0; padding: 25px 0 0; border-top: 1px solid #dce9f0; }
                .steps h2 { margin: 0 0 15px; font-size: 1rem; color: #17364b; }
                ol { margin: 0; padding-left: 22px; }
                li { padding-left: 5px; margin: 12px 0; }
                li::marker { color: #28759e; font-weight: 800; }
                code { padding: .18em .4em; border-radius: 5px; background: #e9f3f8; color: #195879; font-size: .9em; }
                .command { display: block; width: fit-content; max-width: 100%; margin: 8px 0 0; padding: 10px 13px; overflow-x: auto; background: #123c55; color: #fff; white-space: nowrap; }
                .note { margin: 26px 0 0; padding: 13px 16px; border-left: 3px solid #3782ab; border-radius: 0 7px 7px 0; background: #f0f7fb; color: #36566a; font-size: .94rem; }
                @media (max-width: 480px) {
                  main { border-radius: 14px; }
                  .eyebrow { margin-top: 27px; }
                  .lead { font-size: 1rem; }
                }
              </style>
            </head>
            <body>
              <main>
                <div class="brand"><span class="brand-mark" aria-hidden="true">S</span><span>SqueHub</span></div>
                <p class="eyebrow">Application setup</p>
                <h1>SqueHub setup required</h1>
                <p class="lead">{$message}</p>
                <section class="steps" aria-labelledby="setup-steps">
                  <h2 id="setup-steps">Finish setup</h2>
                  <ol>
                    <li>Copy the example configuration to <code>.env</code> if it is missing.
                      <code class="command">Copy-Item .example.env .env</code>
                    </li>
                    <li>Generate a private application key.
                      <code class="command">php squehub key:generate</code>
                    </li>
                    <li>Set <code>APP_KEY</code> in <code>.env</code> and review the application and database settings.</li>
                  </ol>
                </section>
                <p class="note">Save <code>.env</code>, then reload this page. SqueHub checks the file on each request.</p>
              </main>
            </body>
            </html>
            HTML;

        return new Response($html, 503, [...$headers, 'Content-Type' => 'text/html; charset=UTF-8']);
    }
}
