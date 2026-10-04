<?php

declare(strict_types=1);

namespace App\Contributions;

use InvalidArgumentException;

/** A portable identity for the component responsible for a contribution. */
final readonly class ContributionOwner
{
    public function __construct(public string $type, public string $name)
    {
        if (!in_array($type, ['package', 'kit', 'application', 'framework'], true)
            || strlen($name) > 128
            || preg_match('/\A[A-Za-z][A-Za-z0-9._-]*\z/D', $name) !== 1) {
            throw new InvalidArgumentException('Contribution owner identity is invalid.');
        }
    }

    /** @return array{type:string,name:string} */
    public function toArray(): array
    {
        return ['type' => $this->type, 'name' => $this->name];
    }

    public function key(): string
    {
        return $this->type . ':' . $this->name;
    }
}
