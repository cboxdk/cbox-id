<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

readonly class Entity
{
    public function __construct(public string $namespace, public string $type, public string $id, public string $tenantId = '') {}

    /** @return array{namespace:string,type:string,id:string,tenant_id?:string} */
    public function toArray(): array
    {
        return [...($this->tenantId !== '' ? ['tenant_id' => $this->tenantId] : []), 'namespace' => $this->namespace, 'type' => $this->type, 'id' => $this->id];
    }
}
