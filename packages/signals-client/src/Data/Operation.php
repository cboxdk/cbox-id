<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

use Cbox\Signals\Support\Wire;

readonly class Operation
{
    public function __construct(public string $id, public OperationState $status, public int $acceptedCount, public ?string $errorCode, public ?ReplayResult $result) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        if (! array_key_exists('error_code', $data) || ! array_key_exists('result', $data)) {
            Wire::fail();
        }

        return new self(Wire::text($data['id'] ?? null), OperationState::tryFrom(Wire::text($data['status'] ?? null)) ?? Wire::fail(), Wire::integer($data['accepted_count'] ?? null), $data['error_code'] === null ? null : Wire::text($data['error_code']), $data['result'] === null ? null : ReplayResult::fromArray(Wire::object($data['result'])));
    }
}
