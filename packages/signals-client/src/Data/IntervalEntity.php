<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

use Cbox\Signals\Support\Wire;
use InvalidArgumentException;

/** Internal references only; never an API secret, session, device or raw IP. */
readonly class IntervalEntity
{
    public function __construct(public string $namespace, public string $type, public string $id)
    {
        if ($namespace === '' || strlen($namespace) > 256 || $id === '' || strlen($id) > 256 || ! in_array($type, ['user', 'organization', 'team', 'token', 'service', 'resource', 'customer', 'application'], true)) {
            throw new InvalidArgumentException('Invalid interval entity.');
        }
    }

    /** @return array{namespace:string,type:string,id:string} */
    public function toArray(): array
    {
        return ['namespace' => $this->namespace, 'type' => $this->type, 'id' => $this->id];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(Wire::text($data['namespace'] ?? null), Wire::text($data['type'] ?? null), Wire::text($data['id'] ?? null));
    }
}
