<?php

declare(strict_types=1);

namespace App\Http;

use App\Foundation\Application;
use App\Foundation\UrlBasePath;
use App\Api\ApiError;
use App\Api\ApiErrorRenderer;
use App\Api\ApiRequestPolicy;
use App\Authorization\AuthorizationException;
use App\Auth\TokenAuthenticationException;
use App\Diagnostics\Diagnostics;
use App\Http\Exception\HttpException;
use App\Logging\Logger;
use App\Routing\RouteRegistry;
use App\Validation\ValidationException;
use App\Support\SecretRedactor;
use App\Security\Csrf\CsrfException;
use App\Security\SignedUrl\InvalidSignedUrlException;
use App\RateLimit\RateLimitExceededException;
use App\OAuth\OAuthCancelledException;
use App\Webhooks\InvalidWebhookException;
use App\View\Compiler\CompilerException;
use App\View\FragmentNotFoundException;
use App\View\InvalidFragmentNameException;
use App\View\ViewHttpException;
use App\View\ViewNotFoundException;
use App\View\ViewRenderException;
use Stringable;
use Throwable;
use UnexpectedValueException;

/** Renders errors as HTML or JSON without exposing internals in production. */
final class ExceptionHandler
{
    private ?BrowserNavigation $browserNavigation = null;
    private ?Logger $logger = null;
    private ?Diagnostics $diagnostics = null;

    public function __construct(private Application $app, private ?RouteRegistry $routes = null)
    {
    }

    public function setBrowserNavigation(BrowserNavigation $navigation): void
    {
        $this->browserNavigation = $navigation;
    }

    public function setLogger(Logger $logger): void { $this->logger = $logger; }
    public function setDiagnostics(Diagnostics $diagnostics): void { $this->diagnostics = $diagnostics; }

    /** @internal Shared pre-routing policy; Accept alone preserves the legacy JSON contract. */
    public function isApiRequest(Request $request): bool
    {
        return (new ApiRequestPolicy($this->app->config()))->matches($request);
    }

    /** Render the original error even when best-effort exception logging fails. */
    public function render(Throwable $exception, Request $request): Response
    {
        try {
            $api = $exception instanceof ApiError || $this->isApiRequest($request);
        } catch (Throwable $failure) {
            $this->report($failure, $request);
            return ApiErrorRenderer::fallback($request);
        }
        if ($exception instanceof OAuthCancelledException) {
            // A user's denial is an expected terminal callback, not an
            // infrastructure error or a reason to expose provider text.
            if ($api) {
                return (new ApiErrorRenderer($this->app->config()))->render(
                    ApiError::make('oauth_cancelled', 'External sign-in was cancelled.', 400), $request);
            }
            return new Response('<!doctype html><html><head><title>Sign-in cancelled</title></head>'
                . '<body><h1>Sign-in cancelled</h1></body></html>', 400,
                ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
        }
        if ($exception instanceof InvalidWebhookException) {
            // A bad signature, timestamp, or envelope is one indistinguishable
            // public failure. Debug mode must not turn it into a signing oracle.
            if ($api) {
                return (new ApiErrorRenderer($this->app->config()))->render(
                    ApiError::make('invalid_webhook', 'Invalid webhook.', 400), $request);
            }
            return new Response('<!doctype html><html><head><title>Invalid webhook</title></head>'
                . '<body><h1>Invalid webhook</h1></body></html>', 400,
                ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
        }
        if ($exception instanceof InvalidSignedUrlException) {
            // A submitted signature is a bearer capability. Keep every
            // failure identical, including in debug mode and application logs.
            if ($api) {
                return (new ApiErrorRenderer($this->app->config()))->render(
                    ApiError::make('invalid_signed_url', 'Invalid signed URL.', 403), $request);
            }
            $custom = $this->customErrorResponse(403, ['Cache-Control' => 'no-store']);
            if ($custom !== null) return $custom;
            return new Response('<!doctype html><html><head><title>Forbidden</title></head>'
                . '<body><h1>Forbidden</h1><p>Invalid signed URL.</p></body></html>', 403,
                ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
        }
        if ($api) {
            try {
                $response = (new ApiErrorRenderer($this->app->config()))->render($exception, $request);
            } catch (Throwable $failure) {
                // A malformed public error cannot recurse into this handler or
                // expose its data. Report the rendering failure exactly once.
                $this->report($failure, $request);
                return ApiErrorRenderer::fallback($request);
            }
            $this->report($exception, $request);
            return $response;
        }
        $this->report($exception, $request);
        if ($exception instanceof RateLimitExceededException) {
            // A policy denial is expected control flow. Even debug responses
            // stay generic and reveal only safe integer policy headers.
            if ($request->expectsJson()) {
                return new JsonResponse(['message' => 'Too many requests.'], 429, $exception->headers());
            }
            $custom = $this->customErrorResponse(429, $exception->headers());
            if ($custom !== null) return $custom;
            $page = $this->errorPage(429);
            return new Response($page ?? '<!doctype html><html><head><title>Too Many Requests</title></head>'
                . '<body><h1>429 Too Many Requests</h1><p>Too many requests.</p></body></html>',
                429, [...$exception->headers(), 'Content-Type' => 'text/html; charset=UTF-8']);
        }
        if ($exception instanceof CsrfException) {
            // CSRF failures stay generic in debug mode as well: exception
            // context must never echo a submitted or expected secret.
            if ($request->expectsJson()) {
                return new JsonResponse(['message' => 'CSRF verification failed.'], 403);
            }
            $custom = $this->customErrorResponse(403, []);
            if ($custom !== null) {
                return $custom;
            }
            $page = $this->errorPage(403);
            if ($page !== null) {
                return new Response($page, 403, ['Content-Type' => 'text/html; charset=UTF-8']);
            }
            return new Response('<!doctype html><html><head><title>Forbidden</title></head>'
                . '<body><h1>Forbidden</h1><p>CSRF verification failed.</p></body></html>',
                403, ['Content-Type' => 'text/html; charset=UTF-8']);
        }
        if ($exception instanceof ValidationException) {
            if ($request->expectsJson()) {
                return new JsonResponse(['message' => 'Validation failed.', 'errors' => $exception->errors()], 422);
            }
            if ($this->browserNavigation !== null
                && in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                try {
                    $destination = $this->browserNavigation->back($request);
                    if ($destination !== null) {
                        $input = $request->all();
                        // The configured CSRF field is framework data, even if
                        // its custom spelling evades the ordinary key filter.
                        $field = $this->app->config()->get('csrf.field', '_csrf');
                        if (is_string($field)) unset($input[$field]);
                        // Both fields control framework request handling, not
                        // values that an application should repopulate.
                        unset($input['_token'], $input['_method']);
                        $store = $this->app->container()->make(\App\Session\SessionManager::class)->store();
                        $store->flashInput($input);
                        $store->flash('_validation_errors', $exception->errors());
                        return new RedirectResponse($destination, 303);
                    }
                } catch (Throwable $failure) {
                    // A broken session must never produce a redirect that
                    // promises errors and old input which were not persisted.
                    return $this->render($failure, $request);
                }
            }
            $escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html = '<!doctype html><html><head><title>Validation failed</title></head><body><h1>Validation failed</h1><ul>';
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $html .= '<li><strong>' . $escape((string) $field) . '</strong>: ' . $escape((string) $message) . '</li>';
                }
            }
            return new Response($html . '</ul></body></html>', 422, ['Content-Type' => 'text/html; charset=UTF-8']);
        }
        if ($exception instanceof AuthorizationException) {
            // Only a policy-authored denial message is rendered. Escape it in
            // HTML and never add identity, ability, or subject details.
            $message = $exception->getMessage();
            if ($request->expectsJson()) return new JsonResponse(['message' => $message], 403);
            $custom = $this->customErrorResponse(403, []);
            if ($custom !== null) return $custom;
            $safe = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return new Response('<!doctype html><html><head><title>Forbidden</title></head>'
                . '<body><h1>Forbidden</h1><p>' . $safe . '</p></body></html>',
                403, ['Content-Type' => 'text/html; charset=UTF-8']);
        }
        $status = $exception instanceof HttpException ? $exception->status() : 500;
        $headers = $exception instanceof HttpException ? $exception->headers() : [];
        if ($status === 404) {
            // A missing page may be published later. Do not cache the miss.
            $headers['Cache-Control'] = 'no-store';
        }
        $reason = match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            429 => 'Too Many Requests',
            503 => 'Service Unavailable',
            default => $status >= 500 ? 'Internal Server Error' : 'HTTP Error',
        };

        // An unknown URL is routine application control flow. Even in debug
        // mode it should not reveal router files or a framework stack trace.
        $viewDiagnostic = $exception instanceof CompilerException
            || $exception instanceof FragmentNotFoundException
            || $exception instanceof InvalidFragmentNameException
            || $exception instanceof ViewNotFoundException
            || $exception instanceof ViewRenderException
            || $exception instanceof ViewHttpException;
        if ($status === 404) {
            $details = ['error' => $reason];
        } elseif ($this->app->isDebug() && $viewDiagnostic) {
            // Compiled PHP locations and underlying runtime messages are not
            // template source locations. The logical exception is sufficient
            // for development without disclosing generated files or context.
            $details = [
                'error' => $reason,
                'exception' => $exception::class,
                'message' => $this->redact($exception->getMessage(), $request),
            ];
        } elseif ($this->app->isDebug()) {
            $details = [
                'error' => $reason,
                'exception' => $exception::class,
                'message' => $this->redact($exception->getMessage(), $request),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $this->redact($exception->getTraceAsString(), $request),
            ];
        } else {
            $details = ['error' => $reason];
        }

        if ($request->expectsJson()) {
            return new JsonResponse($details, $status, $headers);
        }

        $custom = $this->customErrorResponse($status, $headers);
        if ($custom !== null) {
            return $custom;
        }

        foreach (array_keys($headers) as $name) {
            if (strcasecmp((string) $name, 'Content-Type') === 0) {
                unset($headers[$name]);
            }
        }
        $headers['Content-Type'] = 'text/html; charset=UTF-8';
        // Status templates can render the already-redacted development details.
        // A missing or broken template still reaches the escaped fallback below.
        $page = $this->errorPage($status,
            $status !== 404 && $this->app->isDebug() ? $details : null);
        if ($page !== null) {
            return new Response($page, $status, $headers);
        }

        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<!doctype html><html><head><title>' . $escape($reason) . '</title></head><body>'
            . '<h1>' . $escape($reason) . '</h1>';
        if ($this->app->isDebug() && $status !== 404) {
            $html .= '<p>' . $escape($details['exception'] . ': ' . $details['message']) . '</p>';
            if (!$viewDiagnostic) {
                $html .= '<p>' . $escape($details['file'] . ':' . (string) $details['line']) . '</p>'
                    . '<pre>' . $escape($details['trace']) . '</pre>';
            }
        }
        $html .= '</body></html>';
        return new Response($html, $status, $headers);
    }

    /** Capture echoed SqueHub views and keep the original error status. */
    private function customErrorResponse(int $status, array $headers): ?Response
    {
        $handler = $this->routes?->errorHandler($status);
        if ($handler === null) {
            return null;
        }

        $level = ob_get_level();
        ob_start();
        try {
            $value = $handler();
            $output = '';
            while (ob_get_level() > $level) {
                $output = ob_get_clean() . $output;
            }
            if ($value instanceof Response) {
                // Preserve framework headers such as Allow on a 405, even if
                // the application returned a Response with status 200.
                $response = $value->withStatus($status);
                foreach ($headers as $name => $headerValue) {
                    $response = $response->withHeader((string) $name, (string) $headerValue);
                }
                return $response;
            }
            if ($value !== null && !is_scalar($value) && !$value instanceof Stringable) {
                throw new UnexpectedValueException('Error handler must return a Response, string, or null.');
            }
            $headers['Content-Type'] = 'text/html; charset=UTF-8';
            return new Response($output . ($value === null ? '' : (string) $value), $status, $headers);
        } catch (Throwable $failure) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            $this->report($failure);
            return null;
        }
    }

    /** Application-owned status templates preserve the legacy 404/500 paths. */
    private function errorPage(int $status, ?array $debugDetails = null): ?string
    {
        $file = $this->app->basePath('Project/Views/Default/Error/' . $status . '.php');
        if (!is_file($file)) {
            return null;
        }

        $level = ob_get_level();
        ob_start();
        try {
            // These PHP error templates are also included by the standalone
            // legacy router, which has no selected Application. Supply the
            // owning Application's asset URL without relying on global View
            // context; the templates retain a root fallback for legacy use.
            $squehubErrorFaviconUrl = $this->app->container()->has(UrlBasePath::class)
                ? $this->app->container()->make(UrlBasePath::class)
                    ->assetUrl('/assets/default/favicon/squehub-icon.png')
                : '/assets/default/favicon/squehub-icon.png';
            $squehubErrorDebug = $debugDetails;
            require $file;
            $html = '';
            while (ob_get_level() > $level) {
                $html = ob_get_clean() . $html;
            }
            return $html;
        } catch (Throwable $failure) {
            // A broken application page must not replace the original status
            // or disclose the template failure in the browser.
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            $this->report($failure);
            return null;
        }
    }

    private function redact(string $text, Request $request): string
    {
        // Keep the established debug-page marker while sharing secret lookup
        // with structured application logging.
        return str_replace('[REDACTED]', '[redacted]',
            (new SecretRedactor($this->app->config()))->redact($text, $request));
    }

    /** Report at the top HTTP boundary, leaving routine validation quiet. */
    private function report(Throwable $exception, ?Request $request = null): void
    {
        if ($this->logger === null || $exception instanceof ValidationException
            || $exception instanceof AuthorizationException) return;
        // Browser preflight denials are routine policy outcomes. Logging each
        // hostile or mistyped Origin would add noise without diagnostic value.
        if ($exception instanceof ApiError && $exception->errorCode() === 'cors_preflight_denied') return;
        $status = $exception instanceof HttpException ? $exception->status() : 500;
        if ($status === 404 || $exception instanceof RateLimitExceededException
            || $exception instanceof TokenAuthenticationException) return;
        $level = $status >= 500 ? 'error'
            : ($exception instanceof CsrfException || $status === 405 ? 'notice' : 'warning');
        try {
            $this->logger->log($level, $status >= 500 ? 'Unhandled HTTP exception' : 'HTTP request rejected', [
                'exception' => $exception,
                'status' => $status,
                'request_id' => $request?->requestId() ?? $this->diagnostics?->requestId(),
                  'diagnostics' => $this->diagnostics === null ? null
                      : [...$this->diagnostics->snapshot(), 'status' => $status],
            ]);
        } catch (Throwable $loggingFailure) {
            // Even fail_fast logging must not replace the original HTTP error.
            @error_log('SqueHub exception logger failure: ' . $loggingFailure::class
                . ' while reporting ' . $exception::class);
        }
    }
}
