<?php

declare(strict_types=1);

namespace App\Api;

use App\Config\Repository;
use App\Http\Exception\HttpException;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Validation\ValidationException;
use Throwable;

/**
 * Formats known HTTP semantics at the existing exception boundary. Arbitrary
 * exceptions always become generic 500s, including in debug mode. Application
 * JsonResponses are not inspected or rewritten by this renderer.
 * @internal
 */
final class ApiErrorRenderer
{
    public function __construct(private Repository $config)
    {
    }

    public function render(Throwable $exception, Request $request): JsonResponse
    {
        $status = $exception instanceof ValidationException ? 422
            : ($exception instanceof HttpException ? $exception->status() : 500);
        if ($status < 400 || $status > 599) $status = 500;
        [$code, $message] = self::catalogue($status);
        $details = null;
        if ($exception instanceof ApiError) {
            $code = $exception->errorCode();
            $message = $exception->publicMessage();
            $details = $exception->details();
        } elseif ($exception instanceof ValidationException) {
            $details = $exception->errors();
        }
        if ($exception instanceof ApiError || $exception instanceof ValidationException) {
            [$publicMessage, $details] = (new ApiErrorData($this->config, $request))
                ->prepare($message, $details, $exception instanceof ValidationException);
            if ($exception instanceof ApiError) $message = $publicMessage;
        }
        $headers = $exception instanceof HttpException ? $exception->headers() : [];
        foreach (array_keys($headers) as $name) {
            if (in_array(strtolower((string) $name), ['content-length', 'content-type', 'cache-control', 'x-request-id'], true)) {
                unset($headers[$name]);
            }
        }
        $headers['Cache-Control'] = 'no-store';
        $headers['X-Request-ID'] = $request->requestId();
        $error = ['code' => $code, 'message' => $message];
        if ($details !== null) $error['details'] = $details;
        return new JsonResponse(['error' => $error, 'request_id' => $request->requestId()], $status, $headers);
    }

    /** A terminal fallback cannot recurse into application details or custom renderers. */
    public static function fallback(Request $request): JsonResponse
    {
        return new JsonResponse([
            'error' => ['code' => 'internal_error', 'message' => 'An unexpected error occurred.'],
            'request_id' => $request->requestId(),
        ], 500, ['Cache-Control' => 'no-store', 'X-Request-ID' => $request->requestId()]);
    }

    /** @return array{string, string} */
    private static function catalogue(int $status): array
    {
        return match ($status) {
            400 => ['bad_request', 'The request could not be processed.'],
            401 => ['unauthenticated', 'Authentication is required.'],
            403 => ['forbidden', 'This action is not allowed.'],
            404 => ['not_found', 'The requested resource was not found.'],
            405 => ['method_not_allowed', 'The request method is not allowed.'],
            409 => ['conflict', 'The request conflicts with the current state.'],
            422 => ['validation_failed', 'The submitted data is invalid.'],
            429 => ['rate_limited', 'Too many requests.'],
            500 => ['internal_error', 'An unexpected error occurred.'],
            503 => ['service_unavailable', 'The service is temporarily unavailable.'],
            default => ['http_error', 'The request could not be completed.'],
        };
    }
}
