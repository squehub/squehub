<?php

declare(strict_types=1);

namespace App\Http;

use InvalidArgumentException;

/** Represents a returnable redirect without sending headers or exiting. */
final class RedirectResponse extends Response
{
    public function __construct(string $location, int $status = 302, array $headers = [])
    {
        if ($location === '' || !in_array($status, [301, 302, 303, 307, 308], true)) {
            throw new InvalidArgumentException('Redirect requires a location and a redirect status.');
        }
        foreach (array_keys($headers) as $name) {
            if (strcasecmp((string) $name, 'Location') === 0) {
                unset($headers[$name]);
            }
        }
        $headers['Location'] = $location;
        parent::__construct('', $status, $headers);
    }
}
