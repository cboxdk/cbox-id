<?php

declare(strict_types=1);

use App\Models\Radar\RadarDevice;
use App\Models\RiskDecision;
use App\Platform\PlatformAuth;
use App\Platform\Radar\Enums\RadarAction;
use App\Platform\Radar\Enums\RadarList;
use App\Platform\Radar\Enums\RadarListKind;
use App\Platform\Radar\Enums\RadarMode;
use App\Platform\Radar\Enums\RadarRuleScope;
use App\Platform\Radar\IpIntelligence\IpIntelligence;
use App\Platform\Radar\IpIntelligence\IpProfile;
use App\Platform\Radar\RadarDevices;
use App\Platform\Radar\RadarLists;
use App\Platform\Radar\RadarPolicy;
use App\Platform\Radar\RadarPseudonyms;
use App\Platform\Radar\RadarRules;
use App\Platform\Radar\Testing\FakeIpIntelligence;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Otp\Contracts\OtpChannels;
use Cbox\Id\Otp\Testing\FakeOtpChannel;
use Cbox\Risk\Contracts\RiskScorer;
use Cbox\Risk\Enums\Outcome;
use Cbox\Risk\ValueObjects\RiskAssessment;
use Cbox\Risk\ValueObjects\RiskContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| Radar at the doors: monitor and enforce, block and challenge, and what it remembers.
|--------------------------------------------------------------------------
*/

const RADAR_PASSWORD = 'a-strong-password-1234';
const RADAR_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/537.36 Chrome/120.0.6099.71 Safari/537.36';

beforeEach(function (): void {
    // The score is the risk package's to test; here it is pinned calm, so every verdict
    // below is Radar's own.
    app()->instance(RiskScorer::class, new class implements RiskScorer
    {
        public function assess(RiskContext $context): RiskAssessment
        {
            return new RiskAssessment(0.0, Outcome::Allow, []);
        }
    });

    app(OtpChannels::class)->register('email', new FakeOtpChannel);
    config(['risk.mode' => 'monitor']);
});

afterEach(fn () => Carbon::setTestNow());

function radarUser(string $email): string
{
    return app(Subjects::class)->create($email, 'Radar Person', RADAR_PASSWORD)->id;
}

/** @param  array<string, string>  $server */
function radarSignIn(string $email, string $ip = '203.0.113.9', string $userAgent = RADAR_UA, ?string $password = null, array $cookies = []): TestResponse
{
    test()->flushSession();

    $request = test()->withServerVariables(['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $userAgent]);

    foreach ($cookies as $name => $value) {
        $request = $request->withCookie($name, $value);
    }

    return $request->from(route('login'))->post(route('login.attempt'), [
        'email' => $email,
        'password' => $password ?? RADAR_PASSWORD,
        'identified' => true,
    ]);
}

function radarLastDecision(): RiskDecision
{
    return RiskDecision::query()->orderByDesc('id')->firstOrFail();
}

function radarPlaceAddresses(): FakeIpIntelligence
{
    $fake = new FakeIpIntelligence([
        '203.0.113.9' => new IpProfile(country: 'DK', latitude: 55.6761, longitude: 12.5683, asn: 64500, asOrganization: 'Example Telecom'),
        '198.51.100.7' => new IpProfile(country: 'US', latitude: 40.7128, longitude: -74.0060, asn: 64501),
        '192.0.2.44' => new IpProfile(country: 'NL', asn: 64502, vpn: true),
    ]);
    app()->instance(IpIntelligence::class, $fake);

    return $fake;
}

it('records a block under monitor and lets the sign-in through', function (): void {
    radarUser('dana@acme.example');
    $rule = app(RadarRules::class)->create('No acme', null, RadarAction::Block, RadarRuleScope::All, [
        ['field' => 'email_domain', 'operator' => 'eq', 'value' => 'acme.example'],
    ]);

    radarSignIn('dana@acme.example')->assertRedirect(route('dashboard'));

    $decision = radarLastDecision();

    expect($decision->verdict)->toBe('block')
        ->and($decision->enforced)->toBeFalse()
        ->and($decision->mode)->toBe('monitor')
        ->and($decision->rule)->toBe('rule:'.$rule->id)
        ->and($decision->method)->toBe('password');
});

it('refuses under enforce with the same generic sentence whatever decided it', function (): void {
    radarUser('dana@acme.example');
    radarUser('eli@globex.example');
    app(RadarPolicy::class)->setMode(RadarMode::Enforce);
    app(RadarRules::class)->create('No acme', null, RadarAction::Block, RadarRuleScope::All, [
        ['field' => 'email_domain', 'operator' => 'eq', 'value' => 'acme.example'],
    ]);
    app(RadarLists::class)->add(RadarList::Deny, RadarListKind::Email, 'eli@globex.example');

    $byRule = radarSignIn('dana@acme.example')->assertSessionHasErrors('email');
    expect(session(PlatformAuth::SESSION_KEY))->toBeNull();
    $byList = radarSignIn('eli@globex.example')->assertSessionHasErrors('email');

    // NO LEAK: one sentence for a rule, a list and a wrong guess at the score — nothing that
    // tells an attacker which thing to change.
    $generic = __('auth.common.could_not_process');
    expect(session('errors')->first('email'))->toBe($generic);
    $byRule->assertSessionHasErrors(['email' => $generic]);
    $byList->assertSessionHasErrors(['email' => $generic]);

    expect(radarLastDecision()->enforced)->toBeTrue()
        ->and(radarLastDecision()->rule)->toBe('deny_list:email');
});

it('challenges with the emailed code under enforce', function (): void {
    radarUser('dana@acme.example');
    app(RadarPolicy::class)->setMode(RadarMode::Enforce);
    app(RadarRules::class)->create('Unusual', null, RadarAction::Challenge, RadarRuleScope::SignIn, [
        ['field' => 'method', 'operator' => 'eq', 'value' => 'password'],
    ]);

    radarSignIn('dana@acme.example')->assertRedirect(route('login.step-up'));

    expect(session(PlatformAuth::SESSION_KEY))->toBeNull()
        ->and(radarLastDecision()->verdict)->toBe('challenge');
});

it('follows the deployment default until the environment chooses its own mode', function (): void {
    radarUser('dana@acme.example');
    app(RadarRules::class)->create('No acme', null, RadarAction::Block, RadarRuleScope::All, [
        ['field' => 'email_domain', 'operator' => 'eq', 'value' => 'acme.example'],
    ]);
    config(['risk.mode' => 'enforce']);

    radarSignIn('dana@acme.example')->assertSessionHasErrors('email');
    expect(app(RadarPolicy::class)->modeInherited())->toBeTrue();

    // The environment chooses monitor: the deployment's enforce no longer applies to it.
    app(RadarPolicy::class)->setMode(RadarMode::Monitor);

    radarSignIn('dana@acme.example')->assertRedirect(route('dashboard'));
    expect(radarLastDecision()->enforced)->toBeFalse()
        ->and(radarLastDecision()->mode)->toBe('monitor');
});

it('remembers the device a sign-in succeeded from, and challenges a new one once switched on', function (): void {
    radarUser('dana@acme.example');
    app(RadarPolicy::class)->setMode(RadarMode::Enforce);
    app(RadarPolicy::class)->updateBuiltins(['new_device' => ['enabled' => true]]);

    // The first sign-in an account makes is enrolment, not a new device.
    $first = radarSignIn('dana@acme.example')->assertRedirect(route('dashboard'));
    $cookie = $first->getCookie(RadarDevices::cookieName());

    expect($cookie)->not->toBeNull()
        ->and($cookie?->isHttpOnly())->toBeTrue()
        ->and(RadarDevice::query()->count())->toBe(1);

    // Another browser, no cookie: new.
    radarSignIn('dana@acme.example', userAgent: 'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0')
        ->assertRedirect(route('login.step-up'));
    expect(radarLastDecision()->facts['new_device'] ?? null)->toBeTrue()
        ->and(radarLastDecision()->rule)->toBe('builtin:new_device');

    // The first browser's cookie, even with its user agent changed: known.
    radarSignIn('dana@acme.example', userAgent: 'Something else entirely', cookies: [RadarDevices::cookieName() => (string) $cookie?->getValue()])
        ->assertRedirect(route('dashboard'));
    expect(radarLastDecision()->facts['new_device'] ?? null)->toBeFalse()
        ->and(radarLastDecision()->device_hash)->toBe(app(RadarPseudonyms::class)->device((string) $cookie?->getValue()));

    // And the same browser without its cookie is recognised by its fingerprint.
    radarSignIn('dana@acme.example')->assertRedirect(route('dashboard'));
    expect(radarLastDecision()->facts['new_device'] ?? null)->toBeFalse();
});

it('challenges impossible travel from the last SUCCESSFUL sign-in', function (): void {
    radarPlaceAddresses();
    radarUser('dana@acme.example');
    app(RadarPolicy::class)->setMode(RadarMode::Enforce);

    Carbon::setTestNow('2026-10-01 08:00:00');
    radarSignIn('dana@acme.example', ip: '203.0.113.9')->assertRedirect(route('dashboard'));

    $device = RadarDevice::query()->sole();
    expect($device->country)->toBe('DK')
        // Kept coarse: one decimal, about 11 km.
        ->and((float) $device->latitude)->toBe(55.7)
        ->and((float) $device->longitude)->toBe(12.6);

    // A FAILED attempt from the other side of the world moves nothing: the history is only
    // written by a sign-in that succeeded.
    Carbon::setTestNow('2026-10-01 08:30:00');
    radarSignIn('dana@acme.example', ip: '198.51.100.7', password: 'wrong-password-entirely');
    expect(RadarDevice::query()->sole()->country)->toBe('DK');

    Carbon::setTestNow('2026-10-01 09:00:00');
    radarSignIn('dana@acme.example', ip: '198.51.100.7')->assertRedirect(route('login.step-up'));

    $decision = radarLastDecision();

    expect($decision->rule)->toBe('builtin:impossible_travel')
        ->and($decision->country)->toBe('US')
        ->and($decision->asn)->toBe(64501)
        ->and($decision->facts['impossible_travel'] ?? null)->toBeTrue()
        ->and($decision->facts['travel_kmh'] ?? 0)->toBeGreaterThan(6000);

    // A day later the same hop is a plausible flight.
    Carbon::setTestNow('2026-10-02 12:00:00');
    radarSignIn('dana@acme.example', ip: '198.51.100.7')->assertRedirect(route('dashboard'));
});

it('blocks credential stuffing: many addresses from one IP', function (): void {
    app(RadarPolicy::class)->setMode(RadarMode::Enforce);

    foreach (range(1, 9) as $n) {
        radarSignIn("victim{$n}@acme.example", ip: '192.0.2.10', password: 'leaked-password-'.$n)->assertSessionHasErrors('email');
        expect(radarLastDecision()->verdict)->toBe('allow');
    }

    radarSignIn('victim10@acme.example', ip: '192.0.2.10', password: 'leaked-password-10')->assertSessionHasErrors('email');

    expect(radarLastDecision()->verdict)->toBe('block')
        ->and(radarLastDecision()->rule)->toBe('builtin:credential_stuffing')
        ->and(radarLastDecision()->facts['ip_distinct_emails_10m'] ?? null)->toBe(10);

    // Another IP is its own count.
    radarSignIn('victim11@acme.example', ip: '192.0.2.11', password: 'x')->assertSessionHasErrors('email');
    expect(radarLastDecision()->verdict)->toBe('allow');
});

it('challenges an account guessed at from many IPs', function (): void {
    radarUser('dana@acme.example');
    app(RadarPolicy::class)->setMode(RadarMode::Enforce);
    // Below the account lockout, which would otherwise refuse the right password too.
    app(RadarPolicy::class)->updateBuiltins(['account_attack' => ['threshold' => 3]]);

    foreach (range(1, 3) as $n) {
        radarSignIn('dana@acme.example', ip: "192.0.2.{$n}", password: 'guess-'.$n)->assertSessionHasErrors('email');
    }

    // The right password, from yet another address: a second factor first.
    radarSignIn('dana@acme.example', ip: '192.0.2.200')->assertRedirect(route('login.step-up'));
    expect(radarLastDecision()->rule)->toBe('builtin:account_attack');
});

it('lets an allow-list entry through what the built-in rules would block', function (): void {
    app(RadarPolicy::class)->setMode(RadarMode::Enforce);
    app(RadarLists::class)->add(RadarList::Allow, RadarListKind::Ip, '192.0.2.0/24', 'Our load-test runner');

    foreach (range(1, 12) as $n) {
        radarSignIn("load{$n}@acme.example", ip: '192.0.2.10', password: 'x');
    }

    expect(radarLastDecision()->verdict)->toBe('allow')
        ->and(radarLastDecision()->rule)->toBe('allow_list:ip')
        ->and(radarLastDecision()->triggered)->toContain('builtin:credential_stuffing');
});

it('challenges an anonymising network when IP intelligence reports one', function (): void {
    radarPlaceAddresses();
    radarUser('dana@acme.example');
    app(RadarPolicy::class)->setMode(RadarMode::Enforce);

    radarSignIn('dana@acme.example', ip: '192.0.2.44')->assertRedirect(route('login.step-up'));
    expect(radarLastDecision()->rule)->toBe('builtin:anonymous_network');
});

it('blocks a sign-up at a throwaway mail provider under enforce', function (): void {
    Mail::fake();
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    installedDeployment();
    app(RadarPolicy::class)->setMode(RadarMode::Enforce);

    attemptSignup(['email' => 'bot@mailinator.com'])
        ->assertSessionHasErrors(['email' => __('auth.common.could_not_process')]);

    expect(app(Subjects::class)->findByEmail('bot@mailinator.com'))->toBeNull()
        ->and(radarLastDecision()->rule)->toBe('builtin:disposable_email')
        ->and(radarLastDecision()->method)->toBe('sign_up');
});

it('keeps one environment\'s rules, lists and devices out of another\'s', function (): void {
    radarUser('dana@acme.example');
    $context = app(EnvironmentContext::class);

    $context->set(GenericEnvironment::of('env_other'));
    app(RadarPolicy::class)->setMode(RadarMode::Enforce);
    app(RadarRules::class)->create('Block everyone', null, RadarAction::Block, RadarRuleScope::All, [
        ['field' => 'method', 'operator' => 'eq', 'value' => 'password'],
    ]);
    app(RadarLists::class)->add(RadarList::Deny, RadarListKind::Email, 'dana@acme.example');
    $otherSubject = app(RadarPseudonyms::class)->subject('dana@acme.example');
    $context->set(GenericEnvironment::of('env_test'));

    expect(app(RadarPolicy::class)->snapshot()->rules)->toBe([])
        ->and(app(RadarPolicy::class)->snapshot()->entries)->toBe([])
        ->and(app(RadarPolicy::class)->mode())->toBe(RadarMode::Monitor)
        // The same address is a different account in another environment.
        ->and(app(RadarPseudonyms::class)->subject('dana@acme.example'))->not->toBe($otherSubject);

    radarSignIn('dana@acme.example')->assertRedirect(route('dashboard'));
    expect(radarLastDecision()->verdict)->toBe('allow')
        ->and(radarLastDecision()->environment_id)->toBe('env_test');
});

it('stores no IP, address or user agent beyond what is documented', function (): void {
    radarPlaceAddresses();
    radarUser('dana.reeves@acme.example');

    $response = radarSignIn('dana.reeves@acme.example', ip: '203.0.113.9');
    $cookie = (string) $response->getCookie(RadarDevices::cookieName())?->getValue();

    radarSignIn('mallory@acme.example', ip: '203.0.113.9', password: 'nope');

    $stored = json_encode([
        RiskDecision::query()->get()->map->getAttributes()->all(),
        RadarDevice::query()->get()->map->getAttributes()->all(),
    ], JSON_THROW_ON_ERROR);

    expect($stored)->not->toContain('203.0.113.9')
        ->and($stored)->not->toContain('dana.reeves')
        ->and($stored)->not->toContain('mallory')
        ->and($stored)->not->toContain('Chrome/120')
        ->and($cookie)->toMatch('/^[a-f0-9]{32}$/')
        ->and($stored)->not->toContain($cookie)
        // What IS kept, as documented: coarse place and network, and the mail domain.
        ->and($stored)->toContain('acme.example')
        ->and(radarLastDecision()->country)->toBe('DK');
});

it('forgets devices past their retention, in every environment', function (): void {
    config(['cbox-id.radar.device_retention_days' => 180]);

    foreach (['env_test', 'env_other'] as $environment) {
        app(EnvironmentContext::class)->set(GenericEnvironment::of($environment));

        foreach ([200, 10] as $daysAgo) {
            (new RadarDevice)->forceFill([
                'subject_hash' => str_repeat('a', 64),
                'fingerprint_hash' => hash('sha256', $environment.$daysAgo),
                'first_seen_at' => now()->subDays($daysAgo),
                'last_seen_at' => now()->subDays($daysAgo),
            ])->save();
        }
    }

    app(EnvironmentContext::class)->set(null);
    Artisan::call('model:prune', ['--model' => [RadarDevice::class]]);
    app(EnvironmentContext::class)->set(GenericEnvironment::of('env_test'));

    expect(RadarDevice::query()->withoutGlobalScopes()->count())->toBe(2)
        ->and(RadarDevice::query()->withoutGlobalScopes()->where('last_seen_at', '<', now()->subDays(180))->count())->toBe(0);
});

it('falls back to the risk score alone when Radar itself cannot run', function (): void {
    config(['risk.mode' => 'enforce']);
    radarUser('dana@acme.example');
    app()->instance(RiskScorer::class, new class implements RiskScorer
    {
        public function assess(RiskContext $context): RiskAssessment
        {
            return new RiskAssessment(95.0, Outcome::Reject, []);
        }
    });
    // A deploy whose migration has not run yet: Radar's own tables are not there.
    Schema::drop('radar_rules');

    radarSignIn('dana@acme.example')->assertSessionHasErrors(['email' => __('auth.common.could_not_process')]);
    expect(radarLastDecision()->verdict)->toBe('block');
});
