<?php

declare(strict_types=1);

use App\Platform\Radar\IpIntelligence\IpIntelligence;
use App\Platform\Radar\IpIntelligence\IpProfile;
use App\Platform\Radar\IpIntelligence\OfflineIpIntelligence;
use App\Platform\Radar\RadarDevices;
use App\Platform\Radar\Testing\FakeIpIntelligence;
use App\SignalsData\IdentityContext;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\RefreshTokens;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Illuminate\Http\Request;

it('enriches source context without a Radar assessment and reuses results only within the request', function (): void {
    $source = new FakeIpIntelligence(['192.0.2.44' => new IpProfile(country: 'DK', latitude: 55.676, longitude: 12.568, asn: 64500, accuracyKm: 10)]);
    $context = new IdentityContext(app(RadarDevices::class), $source);
    $request = Request::create('/oauth/token', server: ['REMOTE_ADDR' => '192.0.2.44']);
    $first = $context->collect($request);
    expect($first['country'])->toBe('DK')->and($first['asn'])->toBe(64500)
        ->and($first['latitude'])->toBe(55.7)->and($first['longitude'])->toBe(12.6)
        ->and($first['geo_accuracy_km'])->toBe(18.0)
        ->and($first['enrichment_version'])->toBe('id-context-2')
        ->and($context->collect($request))->toBe($first)->and($source->lookups)->toBe(1);
    $context->collect(Request::create('/logout', server: ['REMOTE_ADDR' => '192.0.2.44']));
    expect($source->lookups)->toBe(2);
});

it('keeps Radar known and unknown results without repeating the lookup', function (?IpProfile $profile): void {
    $source = new FakeIpIntelligence(['192.0.2.44' => new IpProfile(country: 'US')]);
    $context = new IdentityContext(app(RadarDevices::class), $source);
    $request = Request::create('/login', server: ['REMOTE_ADDR' => '192.0.2.44']);
    $request->attributes->set(IdentityContext::PROFILE, $profile);
    expect($context->collect($request)['country'])->toBe($profile?->country)
        ->and($source->lookups)->toBe(0);
})->with([null, new IpProfile(country: 'DK')]);

it('never attaches the request address profile to an explicitly different session address', function (): void {
    $source = new FakeIpIntelligence(['192.0.2.45' => new IpProfile(country: 'SE', asn: 64501)]);
    $context = new IdentityContext(app(RadarDevices::class), $source);
    $request = Request::create('/login', server: ['REMOTE_ADDR' => '192.0.2.44']);
    $request->attributes->set(IdentityContext::PROFILE, new IpProfile(country: 'DK', asn: 64500));
    $result = $context->collect($request, '192.0.2.45');
    expect($result['ip'])->toBe('192.0.2.45')->and($result['country'])->toBe('SE')->and($result['asn'])->toBe(64501)
        ->and($result['device_id'])->toBeNull()->and($result['device_evidence'])->toBe('missing')
        ->and($context->collect($request)['country'])->toBe('DK')->and($source->lookups)->toBe(1);
});

it('keeps provider or cache failures unknown without aborting capture or retrying per event', function (): void {
    $source = new class implements IpIntelligence, OfflineIpIntelligence
    {
        public int $lookups = 0;

        public function lookup(string $ip): ?IpProfile
        {
            $this->lookups++;
            throw new RuntimeException('Unavailable provider');
        }

        public function lookupOffline(string $ip): ?IpProfile
        {
            return $this->lookup($ip);
        }
    };
    $context = new IdentityContext(app(RadarDevices::class), $source);
    $request = Request::create('/oauth/token', server: ['REMOTE_ADDR' => '192.0.2.44']);
    $first = $context->collect($request);
    expect($first['country'])->toBeNull()->and($first['asn'])->toBeNull()->and($first['latitude'])->toBeNull()
        ->and($context->collect($request))->toBe($first)->and($source->lookups)->toBe(1);
});

it('does not look up invalid addresses or invent accuracy for a provider without it', function (): void {
    $source = new FakeIpIntelligence(['192.0.2.44' => new IpProfile(country: 'DK', latitude: 55.676, longitude: 12.568)]);
    $context = new IdentityContext(app(RadarDevices::class), $source);
    $result = $context->collect(Request::create('/oauth/token', server: ['REMOTE_ADDR' => 'invalid']));
    expect($result['ip'])->toBeNull()->and($source->lookups)->toBe(0);
    $result = $context->collect(Request::create('/oauth/token', server: ['REMOTE_ADDR' => '192.0.2.44']));
    expect($result['country'])->toBe('DK')->and($result['latitude'])->toBeNull()
        ->and($result['longitude'])->toBeNull()->and($result['geo_accuracy_km'])->toBeNull();
});

it('exports geo and ASN for source transitions that do not pass through Radar', function (): void {
    $intelligence = new FakeIpIntelligence([
        '192.0.2.44' => new IpProfile(country: 'DK', asn: 64500),
    ]);
    app()->instance(IpIntelligence::class, $intelligence);
    installedDeployment();
    config(['signals-data.environments' => ['env_test'], 'signals-auth.enabled' => false]);
    app('request')->server->set('REMOTE_ADDR', '192.0.2.44');
    $subject = app(Subjects::class)->create('geo-source@example.test', 'Geo source');
    app(SessionManager::class)->start($subject->id, null, ['pwd'], '192.0.2.44', 'Firefox/140.0');
    $client = app(ClientRegistry::class)->register(new NewClient('Geo client', scopes: ['openid', 'offline_access'], grantTypes: ['refresh_token']))->client;
    app(RefreshTokens::class)->issue($client, $subject->id, null, ['openid', 'offline_access']);
    $events = collect($this->withToken(app(EnvironmentApiKeys::class)->issue('env_test', 'Source context test', ['signals_source:read'])->plaintext)->getJson('/api/v1/signals/source')->assertOk()->json('data'));
    foreach (['identity.account', 'identity.session', 'identity.token'] as $family) {
        $event = $events->firstWhere('name', $family);
        expect($event['data']['context']['country'])->toBe('DK')
            ->and($event['data']['context']['asn'])->toBe(64500)
            ->and($event['data']['context']['geo_accuracy_km'])->toBeNull();
    }
    expect($intelligence->lookups)->toBe(1);
});
