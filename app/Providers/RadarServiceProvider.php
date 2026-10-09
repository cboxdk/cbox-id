<?php

declare(strict_types=1);

namespace App\Providers;

use App\Platform\Radar\IpIntelligence\CachingIpIntelligence;
use App\Platform\Radar\IpIntelligence\IpinfoIpIntelligence;
use App\Platform\Radar\IpIntelligence\IpIntelligence;
use App\Platform\Radar\IpIntelligence\MaxMindIpIntelligence;
use App\Platform\Radar\IpIntelligence\NullIpIntelligence;
use App\Platform\Radar\RadarDisposableDomains;
use App\Platform\Radar\RadarPseudonyms;
use App\Platform\Radar\RadarVelocity;
use Cbox\Risk\Contracts\DisposableDomains;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\ServiceProvider;

/**
 * RADAR's wiring: which IP intelligence source, which cache the counters share, and the one
 * disposable-domain list both Radar and the risk score read.
 *
 * Everything is overridable the ordinary way — bind your own {@see IpIntelligence} in a
 * provider that registers after this one and it is used (still behind the cache).
 */
final class RadarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(IpIntelligence::class, static fn (Application $app): IpIntelligence => new CachingIpIntelligence(
            self::source($app),
            self::cache($app),
            $app->make(RadarPseudonyms::class),
            self::int(config('cbox-id.radar.ip_intelligence.cache_ttl'), 86400),
        ));

        $this->app->bind(RadarVelocity::class, static fn (Application $app): RadarVelocity => new RadarVelocity(
            self::cache($app),
            $app->make(RadarPseudonyms::class),
        ));

        // One list for Radar's `disposable_email` rule AND the risk score's
        // `email.disposable` signal, so the two never disagree about a domain. Replaces the
        // risk package's own binding (app providers register after package ones).
        $this->app->singleton(RadarDisposableDomains::class, static function (): RadarDisposableDomains {
            $paths = [RadarDisposableDomains::bundledPath()];

            foreach ([config('risk.disposable_domains_path'), config('cbox-id.radar.disposable_domains_path')] as $path) {
                if (is_string($path) && $path !== '') {
                    $paths[] = $path;
                }
            }

            return new RadarDisposableDomains($paths);
        });
        $this->app->singleton(DisposableDomains::class, static fn (Application $app): DisposableDomains => $app->make(RadarDisposableDomains::class));
    }

    /** The configured source, uncached. An unknown driver is no source at all. */
    private static function source(Application $app): IpIntelligence
    {
        $string = static function (string $key): ?string {
            $value = config('cbox-id.radar.ip_intelligence.'.$key);

            return is_string($value) && $value !== '' ? $value : null;
        };
        $timeout = config('cbox-id.radar.ip_intelligence.ipinfo.timeout');

        return match ($string('driver') ?? 'none') {
            'maxmind' => new MaxMindIpIntelligence(
                $string('maxmind.city_database'),
                $string('maxmind.asn_database'),
                $string('maxmind.anonymous_database'),
            ),
            'ipinfo' => new IpinfoIpIntelligence(
                $app->make(HttpClient::class),
                $string('ipinfo.token') ?? '',
                $string('ipinfo.base_url') ?? 'https://ipinfo.io',
                is_numeric($timeout) ? (float) $timeout : 1.5,
            ),
            default => new NullIpIntelligence,
        };
    }

    /** The store the counters and the lookup cache share — `cache_store`, or the default. */
    private static function cache(Application $app): Cache
    {
        $store = config('cbox-id.radar.cache_store');

        return $app->make(CacheFactory::class)->store(is_string($store) && $store !== '' ? $store : null);
    }

    private static function int(mixed $value, int $default): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }
}
