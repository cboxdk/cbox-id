<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

readonly class EvaluationRequest
{
    public function __construct(public string $requestId, public Entity $subject, public string $deviceId = '', public string $ip = '', public string $sessionId = '') {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['request_id' => $this->requestId, 'subject' => $this->subject->toArray(), 'action' => 'auth.login', 'context' => ['device_id' => $this->deviceId, 'ip' => $this->ip, 'session_id' => $this->sessionId]];
    }
}
