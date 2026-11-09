<?php

declare(strict_types=1);

namespace App\Platform\Billing;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Billing's last answers (admission, features, the signup policy), kept so no page waits on
 * Billing.
 *
 * An answer is kept yes or no, with the time it was asked. A page (a GET) reads it at once, also
 * while Billing is down; once it is older than `service-access.billing_snapshot_ttl` seconds the
 * page still gets it and a queued job asks Billing again. A change (POST, PUT, PATCH, DELETE) the
 * person waits on anyway never acts on a kept answer: it asks Billing first, and the answer is
 * kept for the pages after it. Nothing kept, or older than {@see MAX_AGE}: that one request waits
 * on Billing once. Outside an HTTP request (a job, a command) Billing is always asked.
 *
 * Values are plain arrays and scalars: the cache unserializes no objects.
 */
final readonly class BillingSnapshots
{
    /** The oldest answer a page may still be served while a fresh one is fetched. */
    public const int MAX_AGE = 86_400;

    public function __construct(private Cache $cache) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $ask  asks Billing now; throws when it cannot answer
     * @param  Closure(): void  $later  queues the background refresh
     * @param  bool|null  $acting  whether the caller is about to change something (then Billing is asked first); null reads it off the HTTP method
     * @return T|mixed the kept value (validate it) or a fresh answer
     */
    public function remember(string $key, Closure $ask, Closure $later, ?bool $acting = null): mixed
    {
        $request = request();
        if ($request->route() === null || ($acting ?? ! $request->isMethodSafe())) {
            return $this->refresh($key, $ask);
        }

        $kept = $this->read($key);
        if ($kept === null) {
            return $this->refresh($key, $ask);
        }
        if ($kept['stale']) {
            try {
                $later();
            } catch (Throwable $error) {
                // A refresh that could not be queued is asked again by the next page.
                report($error);
            }
        }

        return $kept['value'];
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $ask
     * @return T
     */
    public function refresh(string $key, Closure $ask): mixed
    {
        $value = $ask();
        $this->write($key, $value);

        return $value;
    }

    public function write(string $key, mixed $value): void
    {
        $this->cache->put(self::cacheKey($key), ['value' => $value, 'at' => Carbon::now()->getTimestamp()], self::MAX_AGE);
    }

    public function forget(string $key): void
    {
        $this->cache->forget(self::cacheKey($key));
    }

    /** @return array{value: mixed, stale: bool}|null */
    public function read(string $key): ?array
    {
        $stored = $this->cache->get(self::cacheKey($key));
        if (! is_array($stored) || ! is_int($stored['at'] ?? null) || ! array_key_exists('value', $stored)) {
            return null;
        }
        $age = Carbon::now()->getTimestamp() - $stored['at'];
        if ($age > self::MAX_AGE) {
            return null;
        }

        return ['value' => $stored['value'], 'stale' => $age > max(0, config()->integer('service-access.billing_snapshot_ttl', 30))];
    }

    /** @param  list<string>  $parts */
    public static function key(string $kind, array $parts = []): string
    {
        return $kind.':'.hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    private static function cacheKey(string $key): string
    {
        return 'cbox-id.billing-snapshot.'.$key;
    }
}
