<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Request;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * `throttle`, with the check and the count of every limit on a request done in ONE round
 * trip to Redis.
 *
 * WHY. Laravel's limiter asks the cache five times per limit on an allowed request (GET the
 * counter, two scripts to start the window and the counter, INCRBY, GET again for the
 * header). `/oauth/token` and the `api-*` limiters carry two limits each. On production
 * (2026-11-12) a round trip to Valkey is about 1 ms, so a request paid 5-10 ms for its
 * rate limit before its endpoint ran, on every call between services.
 *
 * WHAT IS THE SAME. The limits themselves: who is counted, how many, for how long, when a
 * request is refused and with which headers. The order too: every limit is checked before
 * any is counted, so a refused request counts against nothing. And the storage: the same
 * two keys per limit that {@see RateLimiter} writes (`{key}` the counter,
 * `{key}:timer` when the window ends), in the same format, so `RateLimiter::attempts()`,
 * `clear()` and a pod still on the stock middleware during a rollout all see one counter.
 *
 * WHAT IS DIFFERENT. The steps run as one script, so they are also atomic: two requests
 * racing on the last attempt cannot both pass, which the five separate commands allowed.
 *
 * Only when the limiter's store is Redis through phpredis with plain values. Anything else
 * (the array store the tests run on, a serializer or compression on the connection, a
 * limit that counts after the response) is handled by the stock middleware unchanged.
 */
class ThrottleInOneRoundTrip extends ThrottleRequests
{
    /**
     * KEYS: per limit, the counter and its timer. ARGV: now, then per limit the maximum
     * and the window in seconds. Returns {0, index, attempts} for the first limit that is
     * exhausted, or {1, attempts...} after counting the request against every limit.
     */
    private const SCRIPT = <<<'LUA'
        local now = tonumber(ARGV[1])
        local limits = #KEYS / 2

        for i = 1, limits do
            local attempts = tonumber(redis.call('GET', KEYS[i * 2 - 1]) or 0) or 0

            if attempts >= tonumber(ARGV[i * 2]) then
                if redis.call('EXISTS', KEYS[i * 2]) == 1 then
                    return {0, i, attempts}
                end

                redis.call('DEL', KEYS[i * 2 - 1])
            end
        end

        local result = {1}

        for i = 1, limits do
            local window = tonumber(ARGV[i * 2 + 1])

            redis.call('SET', KEYS[i * 2], now + window, 'EX', window, 'NX')

            local attempts = redis.call('INCR', KEYS[i * 2 - 1])

            if attempts == 1 or redis.call('TTL', KEYS[i * 2 - 1]) < 0 then
                redis.call('EXPIRE', KEYS[i * 2 - 1], window)
            end

            result[#result + 1] = attempts
        end

        return result
        LUA;

    /**
     * @param  Request  $request
     * @param  array<int, object{key: string, maxAttempts: int, decaySeconds: int, responseCallback: ?callable, afterCallback: ?callable}>  $limits
     * @return Response
     */
    protected function handleRequest($request, Closure $next, array $limits)
    {
        $store = $this->redisStore();

        if ($store === null || $limits === [] || $this->countsAfterTheResponse($limits)) {
            return parent::handleRequest($request, $next, $limits);
        }

        $keys = [];
        $arguments = [Carbon::now()->getTimestamp()];

        foreach ($limits as $limit) {
            $key = $store->getPrefix().$this->limiter->cleanRateLimiterKey($limit->key);
            $keys[] = $key;
            $keys[] = $key.':timer';
            $arguments[] = $limit->maxAttempts;
            $arguments[] = max(1, $limit->decaySeconds);
        }

        // phpredis's own signature: the script, keys then arguments, and how many are keys.
        $result = $store->connection()->command('eval', [self::SCRIPT, [...$keys, ...$arguments], count($keys)]);
        $result = is_array($result) ? array_map(static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0, array_values($result)) : [];

        if (($result[0] ?? null) !== 1) {
            $refused = array_values($limits)[($result[1] ?? 1) - 1] ?? array_values($limits)[0];

            throw $this->buildException($request, $refused->key, $refused->maxAttempts, $refused->responseCallback);
        }

        $response = $next($request);

        if (! $response instanceof Response) {
            // The router turns whatever a route returns into a response before it gets here.
            throw new LogicException('The throttled route did not produce a response.');
        }

        foreach (array_values($limits) as $index => $limit) {
            $response = $this->addHeaders(
                $response,
                $limit->maxAttempts,
                max(0, $limit->maxAttempts - ($result[$index + 1] ?? $limit->maxAttempts)),
            );
        }

        return $response;
    }

    /**
     * The limiter's store when it is one this can script against: Redis, through phpredis,
     * holding counters as plain numbers.
     */
    private function redisStore(): ?RedisStore
    {
        $name = config('cache.limiter');
        $store = app(CacheFactory::class)->store(is_string($name) ? $name : null)->getStore();

        if (! $store instanceof RedisStore) {
            return null;
        }

        $connection = $store->connection();

        if (! $connection instanceof PhpRedisConnection || $connection->serialized() || $connection->compressed()) {
            return null;
        }

        return $store;
    }

    /** @param  array<int, object{afterCallback: ?callable}>  $limits */
    private function countsAfterTheResponse(array $limits): bool
    {
        foreach ($limits as $limit) {
            if ($limit->afterCallback !== null) {
                return true;
            }
        }

        return false;
    }
}
