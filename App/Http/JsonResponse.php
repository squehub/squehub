<?php

declare(strict_types=1);

namespace App\Http;

use JsonException;

/** Encodes JSON strictly and fixes its content type for the HTTP sender. */
final class JsonResponse extends Response
{
    public function __construct(mixed $data, int $status = 200, array $headers = [])
    {
        try {
            $content = json_encode(
                $data,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            );
        } catch (JsonException $exception) {
            throw new ResponseEncodingException('JSON response could not be encoded.', 0, $exception);
        }
        foreach (array_keys($headers) as $name) {
            if (strcasecmp((string) $name, 'Content-Type') === 0) {
                unset($headers[$name]);
            }
        }
        $headers['Content-Type'] = 'application/json; charset=UTF-8';
        parent::__construct($content, $status, $headers);
    }
}
