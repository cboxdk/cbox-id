<?php

declare(strict_types=1);

use App\Http\Middleware\ThrottleInOneRoundTrip;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| A rate limit is checked and counted in one round trip
|--------------------------------------------------------------------------
|
| Laravel's cache-backed limiter asks the shared store five times per limit on an allowed
| request (GET, EVAL, EVAL, INCRBY, GET), and `/oauth/token` and the `api-*` limiters carry
| two limits each: ten round trips of about 1 ms on production before the endpoint ran.
| ThrottleInOneRoundTrip runs the same check and the same count as one script, on the same
| keys in the same format, so the stock RateLimiter (and a pod still running the old
| middleware during a rollout) reads and writes the same counters.
|
| The Redis cases need a real server: set THROTTLE_TEST_REDIS_HOST (and _PORT). CI's
| PostgreSQL job runs them against Valkey. Without it they are skipped, and the last test
| holds the fallback every other test in the suite goes through.
*/

function throttleRedisHost(): ?string
{
    $host = getenv('THROTTLE_TEST_REDIS_HOST');

    return is_string($host) && $host !== '' ? $host : null;
}

/** Point the rate limiter at a real Redis under a prefix of this test's own. */
function limiterOnRedis(): string
{
    $prefix = 'throttle-test-'.Str::lower(Str::random(10)).':';

    config([
        'database.redis.client' => 'phpredis',
        'database.redis.options.prefix' => '',
        'database.redis.throttle-test' => [
            'host' => throttleRedisHost(),
            'port' => (int) (getenv('THROTTLE_TEST_REDIS_PORT') ?: 6379),
            'password' => getenv('THROTTLE_TEST_REDIS_PASSWORD') ?: null,
            'database' => 15,
        ],
        'cache.stores.throttle-test' => ['driver' => 'redis', 'connection' => 'throttle-test', 'lock_connection' => 'throttle-test'],
        'cache.prefix' => $prefix,
        'cache.limiter' => 'throttle-test',
    ]);

    app()->forgetInstance(RateLimiter::class);
    app()->forgetInstance('redis');
    app()->forgetInstance('cache');
    app()->forgetInstance('cache.store');
    RateLimiterFacade::clearResolvedInstances();
    Redis::clearResolvedInstances();

    return $prefix;
}

function throttledRoute(string $middleware): void
{
    Route::middleware($middleware)->get('/_throttled', fn () => response()->json(['ok' => true]));
}

/** @return list<string> the commands sent to Redis while $callback ran */
function redisCommandsDuring(Closure $callback): array
{
    $commands = [];
    Redis::connection('throttle-test')->getEventDispatcher() ?? Redis::connection('throttle-test')->setEventDispatcher(app('events'));
    Redis::enableEvents();
    Event::listen(CommandExecuted::class, function (CommandExecuted $event) use (&$commands): void {
        $commands[] = strtolower($event->command);
    });

    $callback();

    return $commands;
}

it('counts down and then refuses, as the stock limiter does', function (): void {
    limiterOnRedis();
    throttledRoute(ThrottleInOneRoundTrip::class.':3,1');

    foreach ([2, 1, 0] as $remaining) {
        $this->getJson('/_throttled')->assertOk()
            ->assertHeader('X-RateLimit-Limit', '3')
            ->assertHeader('X-RateLimit-Remaining', (string) $remaining);
    }

    $refused = $this->getJson('/_throttled')->assertStatus(429)->assertHeader('X-RateLimit-Remaining', '0');

    expect((int) $refused->headers->get('Retry-After'))->toBeGreaterThan(50)->toBeLessThanOrEqual(60);
})->skip(fn (): bool => throttleRedisHost() === null, 'THROTTLE_TEST_REDIS_HOST is not set');

it('asks Redis once for an allowed request, whatever the number of limits', function (): void {
    limiterOnRedis();
    RateLimiterFacade::for('two-limits', fn () => [
        Limit::perMinute(30)->by('client|abc'),
        Limit::perMinute(300)->by('address|203.0.113.9'),
    ]);
    throttledRoute(ThrottleInOneRoundTrip::class.':two-limits');
    $this->getJson('/_throttled')->assertOk();

    $commands = redisCommandsDuring(fn () => $this->getJson('/_throttled')->assertOk()->assertHeader('X-RateLimit-Remaining', '28'));

    expect($commands)->toBe(['eval']);
})->skip(fn (): bool => throttleRedisHost() === null, 'THROTTLE_TEST_REDIS_HOST is not set');

it('refuses on any limit and does not count a refused request against the others', function (): void {
    limiterOnRedis();
    RateLimiterFacade::for('two-limits', fn () => [
        Limit::perMinute(2)->by('client|abc'),
        Limit::perMinute(300)->by('address|203.0.113.9'),
    ]);
    throttledRoute(ThrottleInOneRoundTrip::class.':two-limits');

    $this->getJson('/_throttled')->assertOk();
    $this->getJson('/_throttled')->assertOk();
    $this->getJson('/_throttled')->assertStatus(429);
    $this->getJson('/_throttled')->assertStatus(429);

    expect((int) app(RateLimiter::class)->attempts(md5('two-limits'.'address|203.0.113.9')))->toBe(2);
})->skip(fn (): bool => throttleRedisHost() === null, 'THROTTLE_TEST_REDIS_HOST is not set');

it('shares its counters with the stock limiter, so a rollout does not reset or double a limit', function (): void {
    limiterOnRedis();
    RateLimiterFacade::for('shared', fn () => Limit::perMinute(3)->by('caller'));
    throttledRoute(ThrottleInOneRoundTrip::class.':shared');
    $key = md5('shared'.'caller');

    // A pod on the old middleware counted two.
    app(RateLimiter::class)->hit($key, 60);
    app(RateLimiter::class)->hit($key, 60);

    $this->getJson('/_throttled')->assertOk()->assertHeader('X-RateLimit-Remaining', '0');
    $this->getJson('/_throttled')->assertStatus(429);

    expect((int) app(RateLimiter::class)->attempts($key))->toBe(3)
        ->and(app(RateLimiter::class)->tooManyAttempts($key, 3))->toBeTrue()
        ->and(app(RateLimiter::class)->availableIn($key))->toBeGreaterThan(50);
})->skip(fn (): bool => throttleRedisHost() === null, 'THROTTLE_TEST_REDIS_HOST is not set');

it('lets requests through again when the window has passed', function (): void {
    limiterOnRedis();
    RateLimiterFacade::for('short', fn () => Limit::perSecond(1)->by('caller'));
    throttledRoute(ThrottleInOneRoundTrip::class.':short');

    $this->getJson('/_throttled')->assertOk();
    $this->getJson('/_throttled')->assertStatus(429);

    usleep(1_200_000);

    $this->getJson('/_throttled')->assertOk();
})->skip(fn (): bool => throttleRedisHost() === null, 'THROTTLE_TEST_REDIS_HOST is not set');

it('is the stock limiter on a store that is not Redis', function (): void {
    throttledRoute(ThrottleInOneRoundTrip::class.':2,1');

    $this->getJson('/_throttled')->assertOk()->assertHeader('X-RateLimit-Remaining', '1');
    $this->getJson('/_throttled')->assertOk()->assertHeader('X-RateLimit-Remaining', '0');
    $this->getJson('/_throttled')->assertStatus(429);
});

it('is what the `throttle` alias runs', function (): void {
    expect(app('router')->getMiddleware()['throttle'] ?? null)->toBe(ThrottleInOneRoundTrip::class);
});
