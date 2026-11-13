<?php

declare(strict_types=1);

namespace App\Platform\Realtime;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;

/**
 * "Something on this channel changed": the message a live page receives.
 *
 * Sent NOW, because it is only ever dispatched from {@see PublishLiveSignal}, which is
 * already the queued job — the web request that caused the change never talks to the
 * socket server. Rescued, because a Reverb that is down must cost a log line, never a
 * failed job or a retry storm: the page is still correct the next time it loads.
 *
 * The payload is an invalidation signal and nothing else ({@see Change}).
 */
final class LiveSignal implements ShouldBroadcastNow, ShouldRescue
{
    public const string NAME = 'changed';

    /**
     * @param  list<string>  $topics
     * @param  list<array{topic: string, type: string, id: string|null, action: string}>  $changes
     */
    public function __construct(
        public readonly string $channel,
        public readonly array $topics,
        public readonly array $changes = [],
        public readonly bool $truncated = false,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->channel)];
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /** @return array{topics: list<string>, changes: list<array{topic: string, type: string, id: string|null, action: string}>, truncated: bool} */
    public function broadcastWith(): array
    {
        return ['topics' => $this->topics, 'changes' => $this->changes, 'truncated' => $this->truncated];
    }
}
