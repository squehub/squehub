<?php

declare(strict_types=1);

namespace App\Http;

use Stringable;
use UnexpectedValueException;

/** Converts controller results into Responses while preserving captured legacy output. */
final class ResponseNormalizer
{
    public function normalize(DispatchResult $result): Response
    {
        $status = $result->matched ? 200 : 404;
        $value = $result->value;
        if ($value instanceof Response) {
            // An explicit response owns its body and headers. Discard legacy echo
            // so it cannot corrupt JSON or duplicate response content.
            return $value;
        }
        if (is_array($value)) {
            return new JsonResponse($value, $status);
        }
        if ($value === null) {
            return new Response($result->output, $status);
        }
        if (is_scalar($value) || $value instanceof Stringable) {
            return new Response($result->output . (string) $value, $status);
        }
        throw new UnexpectedValueException('HTTP handler returned an unsupported value.');
    }
}
