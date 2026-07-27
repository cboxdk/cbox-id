<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Testing;

use Cbox\Id\Devices\Contracts\PushTransport;
use Cbox\Id\Devices\ValueObjects\PushMessage;
use Cbox\Id\Devices\ValueObjects\PushResult;
use RuntimeException;

/**
 * An in-memory transport that records what it was asked to send, for asserting against.
 *
 * Ships in src/ rather than tests/ deliberately, matching every other module here: the
 * app's own suite consumes it, and so would anyone embedding this module.
 */
final class FakePushTransport implements PushTransport
{
    /** @var list<PushMessage> */
    private array $sent = [];

    private PushResult $next;

    private bool $throwOnSend = false;

    public function __construct(?PushResult $next = null)
    {
        $this->next = $next ?? PushResult::delivered(200);
    }

    public function send(PushMessage $message): PushResult
    {
        $this->sent[] = $message;

        if ($this->throwOnSend) {
            throw new RuntimeException('Transport exploded.');
        }

        return $this->next;
    }

    /**
     * Make every subsequent send return this result — for driving the retry, circuit
     * breaker and token-retirement paths.
     */
    public function willReturn(PushResult $result): self
    {
        $this->next = $result;

        return $this;
    }

    /**
     * Make every subsequent send THROW rather than return a failure result. This is the
     * fail-open case: a transport blowing up must degrade into a retry, never into a
     * broken login.
     */
    public function willThrow(bool $throw = true): self
    {
        $this->throwOnSend = $throw;

        return $this;
    }

    /**
     * @return list<PushMessage>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    public function count(): int
    {
        return count($this->sent);
    }

    public function latest(): ?PushMessage
    {
        return $this->sent === [] ? null : $this->sent[count($this->sent) - 1];
    }

    public function flush(): void
    {
        $this->sent = [];
    }
}
