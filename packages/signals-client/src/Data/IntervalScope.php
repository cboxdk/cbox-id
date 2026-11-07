<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

use Cbox\Signals\Support\Wire;
use InvalidArgumentException;

readonly class IntervalScope
{
    public function __construct(public string $projectId, public string $environmentId, public ?string $tenantId = null)
    {
        foreach ([$projectId, $environmentId, ...($tenantId === null ? [] : [$tenantId])] as $value) {
            if ($value === '' || strlen($value) > 256) {
                throw new InvalidArgumentException('Invalid interval scope.');
            }
        }
    }

    /** @return array<string,string> */
    public function toArray(): array
    {
        return ['project_id' => $this->projectId, 'environment_id' => $this->environmentId, 'audience' => $this->tenantId === null ? 'environment' : 'tenant', ...($this->tenantId === null ? [] : ['tenant_id' => $this->tenantId])];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $audience = Wire::text($data['audience'] ?? null);
        if (! in_array($audience, ['tenant', 'environment'], true) || ($audience === 'environment' && array_key_exists('tenant_id', $data))) {
            Wire::fail();
        }

        return new self(Wire::text($data['project_id'] ?? null), Wire::text($data['environment_id'] ?? null), $audience === 'tenant' ? Wire::text($data['tenant_id'] ?? null) : null);
    }
}
