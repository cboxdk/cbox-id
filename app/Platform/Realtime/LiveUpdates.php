<?php

declare(strict_types=1);

namespace App\Platform\Realtime;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Collects what changed while a request (or a job) runs and publishes it once, at the end.
 *
 * THE RULES, each for a reason:
 *
 *  - **Committed changes only.** A change is buffered after its transaction commits, so a
 *    rolled-back write never tells a page to look.
 *  - **One publish per channel per request.** A bulk action touching two hundred rows is
 *    one message per channel, listing the topics (and the first few records) it touched.
 *  - **Nobody watching, nothing sent.** A channel is published to only while a browser has
 *    been authorized for it recently ({@see watch()}), so the token refreshes and sign-ins
 *    of people with no page open cost a cache read, not a queued job.
 *  - **Bursts are throttled.** At most one publish per channel and topic per window, plus
 *    one trailing publish for whatever changed after it, so the last change is never lost.
 *  - **Never in the way.** Publishing is a queued job; anything that goes wrong here is
 *    reported and swallowed. A live update is a convenience — the write that caused it
 *    must succeed whatever happens to the signal.
 */
final class LiveUpdates
{
    private const string WATCH = 'realtime:watch:';

    private const string LEAD = 'realtime:lead:';

    private const string TRAIL = 'realtime:trail:';

    /** Flush early past this many channels, so a long job cannot grow the buffer forever. */
    private const int MAX_BUFFERED_CHANNELS = 200;

    /** @var array<string, array{topics: array<string, true>, changes: list<array{topic: string, type: string, id: string|null, action: string}>, truncated: bool}> */
    private array $pending = [];

    public function __construct(private readonly Cache $cache) {}

    /** Whether live updates are on: Reverb configured with a key and secret, and not switched off. */
    public static function enabled(): bool
    {
        $key = config('broadcasting.connections.reverb.key');
        $secret = config('broadcasting.connections.reverb.secret');

        return config('realtime.enabled') === true
            && config('broadcasting.default') === 'reverb'
            && is_string($key) && $key !== ''
            && is_string($secret) && $secret !== '';
    }

    /**
     * Note a change, to be published once whatever transaction it belongs to has committed.
     */
    public function record(Change $change, Audience $audience, ?Connection $connection = null): void
    {
        if (! self::enabled()) {
            return;
        }

        $channels = $audience->channels();

        if ($channels === []) {
            return;
        }

        $buffer = function () use ($change, $channels): void {
            foreach ($channels as $channel) {
                $this->buffer($channel, $change);
            }

            if (count($this->pending) > self::MAX_BUFFERED_CHANNELS) {
                $this->flush();
            }
        };

        // Runs at once outside a transaction; after the outermost commit inside one; never
        // when it rolls back.
        ($connection ?? DB::connection())->afterCommit($buffer);
    }

    /** Publish everything buffered so far, and forget it. */
    public function flush(): void
    {
        if ($this->pending === []) {
            return;
        }

        $pending = $this->pending;
        $this->pending = [];

        try {
            $this->publish($pending);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Forget what is buffered without publishing it. */
    public function discard(): void
    {
        $this->pending = [];
    }

    /**
     * Mark a channel as watched: a browser was just authorized to listen on it.
     */
    public function watch(string $channel): void
    {
        try {
            $this->cache->put(self::WATCH.$channel, true, max(60, config()->integer('realtime.watch_seconds', 21600)));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @return array<string, array{topics: array<string, true>, changes: list<array{topic: string, type: string, id: string|null, action: string}>, truncated: bool}> */
    public function pending(): array
    {
        return $this->pending;
    }

    private function buffer(string $channel, Change $change): void
    {
        $entry = $this->pending[$channel] ?? ['topics' => [], 'changes' => [], 'truncated' => false];
        $entry['topics'][$change->topic] = true;

        $row = $change->toArray();

        if (! in_array($row, $entry['changes'], true)) {
            if (count($entry['changes']) < max(0, config()->integer('realtime.max_changes', 20))) {
                $entry['changes'][] = $row;
            } else {
                $entry['truncated'] = true;
            }
        }

        $this->pending[$channel] = $entry;
    }

    /**
     * @param  array<string, array{topics: array<string, true>, changes: list<array{topic: string, type: string, id: string|null, action: string}>, truncated: bool}>  $pending
     */
    private function publish(array $pending): void
    {
        $channels = array_keys($pending);
        $watched = $this->cache->many(array_map(static fn (string $channel): string => self::WATCH.$channel, $channels));
        $window = max(1, config()->integer('realtime.throttle_seconds', 1));
        $queue = config('realtime.queue');
        $queue = is_string($queue) && $queue !== '' ? $queue : null;

        foreach ($pending as $channel => $entry) {
            if (($watched[self::WATCH.$channel] ?? null) === null) {
                continue;
            }

            $now = [];
            $later = [];

            foreach (array_keys($entry['topics']) as $topic) {
                // Leading edge: the first change in a window goes out at once.
                if ($this->cache->add(self::LEAD.$channel.':'.$topic, true, $window)) {
                    $now[] = $topic;

                    continue;
                }

                // Inside the window: one trailing publish, a window from now, covers this
                // change and every other until it runs. One already scheduled covers it too.
                if ($this->cache->add(self::TRAIL.$channel.':'.$topic, true, $window)) {
                    $later[] = $topic;
                }
            }

            if ($now !== []) {
                $changes = array_values(array_filter($entry['changes'], static fn (array $change): bool => in_array($change['topic'], $now, true)));

                PublishLiveSignal::dispatch(new LiveSignal($channel, $now, $changes, $entry['truncated']))
                    ->onQueue($queue);
            }

            if ($later !== []) {
                // No record references: by the time it runs it stands for every change in
                // the window, not just the ones this request saw.
                PublishLiveSignal::dispatch(new LiveSignal($channel, $later, [], true))
                    ->onQueue($queue)
                    ->delay($window);
            }
        }
    }
}
