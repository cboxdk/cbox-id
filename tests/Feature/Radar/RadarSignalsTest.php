<?php

declare(strict_types=1);

use App\Platform\Radar\IpIntelligence\CachingIpIntelligence;
use App\Platform\Radar\IpIntelligence\IpinfoIpIntelligence;
use App\Platform\Radar\IpIntelligence\IpIntelligence;
use App\Platform\Radar\IpIntelligence\IpProfile;
use App\Platform\Radar\IpIntelligence\MaxMindIpIntelligence;
use App\Platform\Radar\IpIntelligence\NullIpIntelligence;
use App\Platform\Radar\RadarDevices;
use App\Platform\Radar\RadarDisposableDomains;
use App\Platform\Radar\RadarPseudonyms;
use App\Platform\Radar\RadarVelocity;
use App\Platform\Radar\Testing\FakeIpIntelligence;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Risk\Contracts\DisposableDomains;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Support\MmdbWriter;

/*
|--------------------------------------------------------------------------
| Radar's signals, each on its own and against fakes.
|--------------------------------------------------------------------------
*/

afterEach(fn () => Carbon::setTestNow());

/** The keys an array cache holds — to prove what is NOT a key. */
function radarCacheKeys(Repository $cache): array
{
    $store = $cache->getStore();
    $storage = (new ReflectionProperty(ArrayStore::class, 'storage'))->getValue($store);

    return array_keys(is_array($storage) ? $storage : []);
}

it('knows nothing about any address by default, and sends nothing anywhere', function (): void {
    Http::fake();

    expect((new NullIpIntelligence)->lookup('81.7.3.4'))->toBeNull()
        ->and(app(IpIntelligence::class)->lookup('81.7.3.4'))->toBeNull();

    Http::assertNothingSent();
});

it('reads geo, network and anonymiser flags from local MaxMind database files', function (): void {
    $dir = sys_get_temp_dir().'/radar-mmdb-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($dir);

    MmdbWriter::write($dir.'/city.mmdb', [
        '81.7.0.0/16' => ['country' => ['iso_code' => 'DK'], 'location' => ['latitude' => 55.6759, 'longitude' => 12.5655]],
    ], 'GeoLite2-City');
    MmdbWriter::write($dir.'/asn.mmdb', [
        '81.7.0.0/16' => ['autonomous_system_number' => 64500, 'autonomous_system_organization' => 'Example Telecom'],
    ], 'GeoLite2-ASN');
    MmdbWriter::write($dir.'/anonymous.mmdb', [
        '81.7.0.0/16' => ['is_anonymous_vpn' => true, 'is_hosting_provider' => true],
    ], 'GeoIP2-Anonymous-IP');

    $maxmind = new MaxMindIpIntelligence($dir.'/city.mmdb', $dir.'/asn.mmdb', $dir.'/anonymous.mmdb');
    $profile = $maxmind->lookup('81.7.3.4');

    expect($profile)->not->toBeNull()
        ->and($profile?->country)->toBe('DK')
        ->and($profile?->latitude)->toBe(55.6759)
        ->and($profile?->asn)->toBe(64500)
        ->and($profile?->asOrganization)->toBe('Example Telecom')
        ->and($profile?->vpn)->toBeTrue()
        ->and($profile?->hosting)->toBeTrue()
        ->and($profile?->tor)->toBeFalse()
        // Not in any database: unknown, not an error.
        ->and($maxmind->lookup('8.8.8.8'))->toBeNull()
        // A file that is not there is simply not consulted — fail open.
        ->and((new MaxMindIpIntelligence($dir.'/missing.mmdb', null, null))->lookup('81.7.3.4'))->toBeNull()
        // Nor is one that is not a database.
        ->and((new MaxMindIpIntelligence(__FILE__, null, null))->lookup('81.7.3.4'))->toBeNull();

    File::deleteDirectory($dir);
});

it('reads IPinfo, free plan and paid, and fails open on anything else', function (): void {
    Http::fake([
        'ipinfo.test/81.7.3.4/json' => Http::response(['ip' => '81.7.3.4', 'country' => 'DK', 'loc' => '55.6759,12.5655', 'org' => 'AS64500 Example Telecom']),
        'ipinfo.test/198.51.100.7/json' => Http::response([
            'country' => 'US',
            'loc' => '40.7128,-74.0060',
            'asn' => ['asn' => 'AS64501', 'name' => 'Example Cloud'],
            'privacy' => ['vpn' => false, 'proxy' => false, 'tor' => true, 'relay' => false, 'hosting' => true],
        ]),
        'ipinfo.test/192.0.2.1/json' => Http::response(['error' => 'rate limited'], 429),
        'ipinfo.test/192.0.2.2/json' => Http::response(['ip' => '192.0.2.2', 'bogon' => true]),
    ]);

    $ipinfo = new IpinfoIpIntelligence(app(HttpClient::class), 'tok_test', 'https://ipinfo.test');

    $free = $ipinfo->lookup('81.7.3.4');
    $paid = $ipinfo->lookup('198.51.100.7');

    expect($free?->country)->toBe('DK')
        ->and($free?->longitude)->toBe(12.5655)
        ->and($free?->asn)->toBe(64500)
        ->and($free?->asOrganization)->toBe('Example Telecom')
        ->and($free?->hosting)->toBeFalse()
        ->and($paid?->asn)->toBe(64501)
        ->and($paid?->tor)->toBeTrue()
        ->and($paid?->hosting)->toBeTrue()
        ->and($ipinfo->lookup('192.0.2.1'))->toBeNull()
        ->and($ipinfo->lookup('192.0.2.2'))->toBeNull()
        ->and($ipinfo->lookup('not-an-ip'))->toBeNull()
        ->and((new IpinfoIpIntelligence(app(HttpClient::class), '', 'https://ipinfo.test'))->lookup('81.7.3.4'))->toBeNull();

    // The token travels as a bearer header, never in the URL a proxy log keeps.
    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer tok_test') && ! str_contains($request->url(), 'tok_test'));
});

it('looks an address up once, caches it under a pseudonym, and never looks up a private one', function (): void {
    $fake = new FakeIpIntelligence(['81.7.3.4' => new IpProfile(country: 'DK')]);
    $cache = new Repository(new ArrayStore);
    $cached = new CachingIpIntelligence($fake, $cache, app(RadarPseudonyms::class));

    $cached->lookup('81.7.3.4');
    $cached->lookup('81.7.3.4');
    $cached->lookup('8.8.4.4'); // unknown is remembered too
    $cached->lookup('8.8.4.4');
    $cached->lookup('10.1.2.3');
    $cached->lookup('127.0.0.1');

    expect($fake->lookups)->toBe(2)
        ->and($cached->lookup('81.7.3.4')?->country)->toBe('DK')
        ->and(implode(' ', radarCacheKeys($cache)))->not->toContain('81.7.3.4')->not->toContain('8.8.4.4');
});

it('counts in sliding windows that forget what fell out of them', function (): void {
    Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_000 - (1_800_000_000 % 60)));
    $velocity = app(RadarVelocity::class);

    foreach (range(1, 8) as $ignored) {
        $velocity->hit('ip_attempts', '203.0.113.9', 60);
    }

    expect($velocity->count('ip_attempts', '203.0.113.9', 60))->toBe(8)
        ->and($velocity->count('ip_attempts', '198.51.100.7', 60))->toBe(0);

    // Half a window on, half of the previous bucket still lies inside the window.
    Carbon::setTestNow(now()->addSeconds(90));
    expect($velocity->count('ip_attempts', '203.0.113.9', 60))->toBe(4);

    // Two windows on, nothing is left.
    Carbon::setTestNow(now()->addSeconds(60));
    expect($velocity->count('ip_attempts', '203.0.113.9', 60))->toBe(0);
});

it('counts DIFFERENT members of a set once each', function (): void {
    Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_000 - (1_800_000_000 % 600)));
    $velocity = app(RadarVelocity::class);

    foreach (['a', 'b', 'a', 'c', 'b'] as $member) {
        $velocity->remember('ip_emails', '203.0.113.9', $member, 600);
    }

    expect($velocity->distinct('ip_emails', '203.0.113.9', 600))->toBe(3);

    Carbon::setTestNow(now()->addSeconds(1200));
    expect($velocity->distinct('ip_emails', '203.0.113.9', 600))->toBe(0);
});

it('keeps one environment\'s counters out of another\'s', function (): void {
    $context = app(EnvironmentContext::class);
    $velocity = app(RadarVelocity::class);

    foreach (range(1, 5) as $ignored) {
        $velocity->hit('ip_attempts', '203.0.113.9', 60);
    }

    $context->set(GenericEnvironment::of('env_other'));
    $elsewhere = $velocity->count('ip_attempts', '203.0.113.9', 60);
    $context->set(GenericEnvironment::of('env_test'));

    expect($elsewhere)->toBe(0)
        ->and($velocity->count('ip_attempts', '203.0.113.9', 60))->toBe(5);
});

it('recognises throwaway mail domains and their subdomains, for Radar and the risk score alike', function (): void {
    $domains = app(DisposableDomains::class);

    expect($domains)->toBeInstanceOf(RadarDisposableDomains::class)
        ->and($domains->contains('mailinator.com'))->toBeTrue()
        ->and($domains->contains('Inbox.Mailinator.COM'))->toBeTrue()
        ->and($domains->contains('guerrillamail.com'))->toBeTrue()
        ->and($domains->contains('gmail.com'))->toBeFalse()
        ->and($domains->contains('com'))->toBeFalse()
        ->and($domains->contains(''))->toBeFalse();
});

it('refreshes the list from its source, and keeps the old one when the answer looks wrong', function (): void {
    $path = sys_get_temp_dir().'/radar-disposable-'.bin2hex(random_bytes(4)).'/list.txt';
    config([
        'cbox-id.radar.disposable_domains_path' => $path,
        'cbox-id.radar.disposable_domains_url' => 'https://lists.test/disposable.txt',
    ]);

    $good = implode("\n", array_map(static fn (int $n): string => "throwaway{$n}.example", range(1, 150)))."\nnot a domain\n";
    Http::fakeSequence('lists.test/*')
        ->push($good)
        ->push("just.one\n")
        ->push('', 500);

    $this->artisan('radar:refresh-disposable-domains')->assertSuccessful();
    expect((new RadarDisposableDomains([$path]))->contains('throwaway42.example'))->toBeTrue()
        ->and((string) file_get_contents($path))->not->toContain('not a domain');

    $this->artisan('radar:refresh-disposable-domains')->assertFailed();
    $this->artisan('radar:refresh-disposable-domains')->assertFailed();
    expect((new RadarDisposableDomains([$path]))->contains('throwaway42.example'))->toBeTrue();

    File::deleteDirectory(dirname($path));
});

it('issues a first-party device cookie holding a random id, and keeps only its pseudonym', function (): void {
    $devices = app(RadarDevices::class);
    $request = Request::create('/login', 'POST', server: ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh) Chrome/120.0.6099.71 Safari/537.36']);

    $first = $devices->identify($request);
    $again = $devices->identify($request);
    $queued = Cookie::queued(RadarDevices::cookieName());

    expect($queued)->not->toBeNull()
        ->and($queued?->isHttpOnly())->toBeTrue()
        ->and($queued?->getSameSite())->toBe('lax')
        ->and($queued?->getValue())->toMatch('/^[a-f0-9]{32}$/')
        ->and($first->returning)->toBeFalse()
        // One id per request: assessment and success agree on the device.
        ->and($again->deviceHash)->toBe($first->deviceHash)
        ->and($first->deviceHash)->not->toContain((string) $queued?->getValue())
        ->and($first->deviceHash)->toBe(app(RadarPseudonyms::class)->device((string) $queued?->getValue()));

    $returning = Request::create('/login', 'POST', cookies: [RadarDevices::cookieName() => (string) $queued?->getValue()]);

    expect($devices->identify($returning)->returning)->toBeTrue()
        ->and($devices->identify($returning)->deviceHash)->toBe($first->deviceHash);
});

it('fingerprints a browser coarsely, so a version bump is still the same browser', function (): void {
    $v120 = Request::create('/', server: ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh) Chrome/120.0.6099.71 Safari/537.36', 'HTTP_ACCEPT_LANGUAGE' => 'da-DK,da;q=0.9']);
    $v120b = Request::create('/', server: ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh) Chrome/120.0.6099.129 Safari/537.36', 'HTTP_ACCEPT_LANGUAGE' => 'da-DK,da;q=0.9,en;q=0.8']);
    $firefox = Request::create('/', server: ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; rv:121.0) Gecko/20100101 Firefox/121.0', 'HTTP_ACCEPT_LANGUAGE' => 'da-DK']);

    expect(RadarDevices::fingerprintMaterial($v120))->toBe(RadarDevices::fingerprintMaterial($v120b))
        ->and(RadarDevices::fingerprintMaterial($v120))->not->toBe(RadarDevices::fingerprintMaterial($firefox));
});
