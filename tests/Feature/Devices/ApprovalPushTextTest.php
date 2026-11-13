<?php

declare(strict_types=1);

use App\Platform\CloudflareGeo;
use App\Platform\Mfa\PhoneApprovalOrigin;
use App\Platform\PlatformAuth;
use Cbox\Id\Devices\Contracts\ApprovalOriginResolver;
use Cbox\Id\Devices\Contracts\PushTransport;
use Cbox\Id\Devices\Enums\ApprovalKind;
use Cbox\Id\Devices\Http\Middleware\RequireAuthenticator;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Devices\Support\ApprovalKindHint;
use Cbox\Id\Devices\Support\ApprovalPushText;
use Cbox\Id\Devices\Testing\FakePushTransport;
use Cbox\Id\Devices\ValueObjects\ApprovalOrigin;
use Cbox\Id\Devices\ValueObjects\PushPayload;
use Cbox\Id\OAuthServer\Contracts\ActionApprovals;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\OAuthServer\Contracts\TokenIntrospector;
use Cbox\Id\OAuthServer\Models\BackchannelAuthRequest;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Cbox\Id\UserProperties\Contracts\UserPreferences;
use Cbox\Id\UserProperties\ValueObjects\PropertyPatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

const CHROME_ON_MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

const FIREFOX_ON_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0';

afterEach(function (): void {
    Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
});

/** @param array<string, mixed> $origin */
function approvalPush(array $origin, string $locale = 'en', ?string $action = null): PushPayload
{
    return app(ApprovalPushText::class)->payload(
        origin: new ApprovalOrigin(...$origin),
        locale: $locale,
        expiresAt: now()->addSeconds(300),
        routing: ['kind' => 'approval', 'request_id' => '01JABCDEFGHJKMNPQRSTVWXYZ0', 'url' => 'com.cboxid.authenticator://approvals/01JABCDEFGHJKMNPQRSTVWXYZ0'],
        collapseKey: '01JABCDEFGHJKMNPQRSTVWXYZ0',
        action: $action,
    );
}

/** Everything a lock screen and the app's routing data carry, as one string. */
function everythingPushed(?PushPayload $payload): string
{
    return implode(' | ', [$payload?->title, $payload?->body, ...array_values($payload?->data ?? [])]);
}

describe('the text', function (): void {
    it('asks to sign in to the app, from where and on what', function (): void {
        $payload = approvalPush(['kind' => ApprovalKind::SignIn, 'app' => 'Cbox Billing', 'city' => 'Copenhagen', 'countryCode' => 'DK', 'userAgent' => CHROME_ON_MAC, 'host' => 'id.example.test']);

        expect($payload->title)->toBe('Sign in to Cbox Billing?')
            ->and($payload->body)->toBe('Copenhagen, Denmark · Chrome on macOS · expires in 5 min')
            ->and($payload->collapseKey)->toBe('01JABCDEFGHJKMNPQRSTVWXYZ0')
            ->and($payload->data)->toMatchArray([
                'kind' => 'approval',
                'request_id' => '01JABCDEFGHJKMNPQRSTVWXYZ0',
                'purpose' => 'sign_in',
                'client_name' => 'Cbox Billing',
                'location' => 'Copenhagen, Denmark',
                'device' => 'Chrome on macOS',
            ]);
    });

    it('says a plain sign-in when no app is known', function (): void {
        $payload = approvalPush(['kind' => ApprovalKind::SignIn]);

        expect($payload->title)->toBe('Confirm sign-in')
            ->and($payload->body)->toBe('Tap to review · expires in 5 min')
            ->and($payload->data)->not->toHaveKeys(['client_name', 'location', 'device'])
            ->and($payload->data['purpose'])->toBe('sign_in');
    });

    it('names only the country when the city is unknown, and nothing when neither is', function (): void {
        expect(approvalPush(['kind' => ApprovalKind::StepUp, 'countryCode' => 'SE', 'userAgent' => 'Firefox/142.0'])->body)
            ->toBe('Sweden · Firefox · expires in 5 min')
            ->and(approvalPush(['kind' => ApprovalKind::StepUp, 'userAgent' => FIREFOX_ON_WINDOWS])->body)
            ->toBe('Firefox on Windows · expires in 5 min')
            ->and(approvalPush(['kind' => ApprovalKind::StepUp, 'city' => 'Nowhere'])->body)
            ->toBe('Tap to review · expires in 5 min');
    });

    it('asks to confirm security access for a step-up', function (): void {
        $payload = approvalPush(['kind' => ApprovalKind::StepUp, 'countryCode' => 'DK']);

        expect($payload->title)->toBe('Confirm security access')
            ->and($payload->data['purpose'])->toBe('step_up');
    });

    it('names a held action without the code the two sides compare', function (): void {
        $payload = approvalPush(['kind' => ApprovalKind::Action], action: 'Deploy key wants to run organizations.delete · 7F3A');

        expect($payload->title)->toBe('Approve: Deploy key wants to run organizations.delete')
            ->and(everythingPushed($payload))->not->toContain('7F3A')
            ->and($payload->data['purpose'])->toBe('action')
            ->and(approvalPush(['kind' => ApprovalKind::Action])->title)->toBe('Approve an action')
            ->and(mb_strlen(approvalPush(['kind' => ApprovalKind::Action], action: str_repeat('x', 300).' · 7F3A')->title))->toBeLessThan(100);
    });

    it('names the app asking for a CIBA approval, and only that', function (): void {
        expect(approvalPush(['kind' => ApprovalKind::Authentication, 'app' => 'Acme Agent'])->title)->toBe('Acme Agent is asking for your approval')
            ->and(approvalPush(['kind' => ApprovalKind::Authentication])->title)->toBe('Approval request');
    });

    it('speaks the approver\'s language', function (): void {
        $payload = approvalPush(['kind' => ApprovalKind::SignIn, 'app' => 'Cbox Billing', 'city' => 'Copenhagen', 'countryCode' => 'DK', 'userAgent' => CHROME_ON_MAC], 'da');

        expect($payload->title)->toBe('Log ind på Cbox Billing?')
            ->and($payload->body)->toBe('Copenhagen, Danmark · Chrome på macOS · udløber om 5 min.')
            ->and($payload->data['location'])->toBe('Copenhagen, Danmark')
            ->and(approvalPush(['kind' => ApprovalKind::Authentication, 'app' => 'Acme'], 'de')->title)->toBe('Acme bittet um deine Freigabe');
    });

    it('keeps a client-chosen name to one short line', function (): void {
        $payload = approvalPush(['kind' => ApprovalKind::SignIn, 'app' => "Evil\nApp ".str_repeat('a', 100)]);

        expect($payload->title)->not->toContain("\n")
            ->and(mb_strlen($payload->title))->toBeLessThan(60);
    });
});

describe('where it came from', function (): void {
    /** @param array<string, string> $headers */
    function browserRequest(array $headers, string $peer = '10.244.0.10'): void
    {
        Request::setTrustedProxies(['10.244.0.0/16'], Request::HEADER_X_FORWARDED_FOR);
        $server = ['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_FOR' => '8.8.4.4', 'HTTP_USER_AGENT' => CHROME_ON_MAC];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }
        app()->instance('request', Request::create('https://id.example.test/mfa/mobile', 'POST', server: $server));
    }

    it('reads city, region and country from the trusted edge, the device from the agent, and never the address', function (): void {
        browserRequest(['cf-ipcountry' => 'dk', 'CF-IPCity' => 'K%C3%B8benhavn', 'CF-Region' => 'Capital Region', 'CF-Region-Code' => '84', 'CF-Timezone' => 'Europe/Copenhagen']);

        $geo = CloudflareGeo::fromRequest(request());
        $origin = app(PhoneApprovalOrigin::class)->capture(ApprovalKind::StepUp);

        expect($geo?->countryCode)->toBe('DK')
            ->and($geo?->regionCode)->toBe('84')
            ->and($geo?->timezone)->toBe('Europe/Copenhagen')
            ->and($origin->city)->toBe('København')
            ->and($origin->region)->toBe('Capital Region')
            ->and($origin->countryCode)->toBe('DK')
            ->and($origin->userAgent)->toBe(CHROME_ON_MAC)
            ->and($origin->host)->toBe('id.example.test')
            ->and(json_encode($origin->toColumns()))->not->toContain('8.8.4.4')->not->toContain('10.244');
    });

    it('ignores the edge headers from a peer that is not the trusted ingress', function (): void {
        browserRequest(['CF-IPCountry' => 'DK', 'CF-IPCity' => 'Copenhagen'], peer: '8.8.4.4');

        expect(CloudflareGeo::fromRequest(request()))->toBeNull()
            ->and(app(PhoneApprovalOrigin::class)->capture(ApprovalKind::SignIn)->countryCode)->toBeNull();
    });

    it('treats unknown and Tor as no place, and drops an unusable city', function (string $country, ?string $city, ?string $expectedCountry, ?string $expectedCity): void {
        browserRequest(['CF-IPCountry' => $country, 'CF-IPCity' => $city ?? '']);

        $geo = CloudflareGeo::fromRequest(request());

        expect($geo?->countryCode)->toBe($expectedCountry)->and($geo?->city)->toBe($expectedCity);
    })->with([
        'unknown' => ['XX', 'Copenhagen', null, null],
        'tor' => ['T1', 'Copenhagen', null, null],
        'not a code' => ['DNK', 'Copenhagen', null, null],
        'control characters' => ['DK', 'Cop%0Aenhagen', 'DK', null],
        'invalid UTF-8' => ['DK', '%FF%FE', 'DK', null],
        'decoded' => ['SE', 'G%C3%B6teborg', 'SE', 'Göteborg'],
        'country only' => ['NO', null, 'NO', null],
    ]);

    it('caps the city at 160 characters', function (): void {
        browserRequest(['CF-IPCountry' => 'DK', 'CF-IPCity' => str_repeat('a', 300)]);

        expect(mb_strlen((string) CloudflareGeo::fromRequest(request())?->city))->toBe(160);
    });

    it('gives a request a server filed no where at all', function (): void {
        browserRequest(['CF-IPCountry' => 'DK', 'CF-IPCity' => 'Copenhagen']);

        $origin = app(PhoneApprovalOrigin::class)->capture(ApprovalKind::Action);

        expect($origin->countryCode)->toBeNull()->and($origin->userAgent)->toBeNull()->and($origin->host)->toBeNull()
            ->and($origin->isEmpty())->toBeTrue()
            ->and(app(ApprovalOriginResolver::class))->toBeInstanceOf(PhoneApprovalOrigin::class);
    });
});

describe('the pushes', function (): void {
    beforeEach(function (): void {
        $this->transport = new FakePushTransport;
        app()->instance(PushTransport::class, $this->transport);
        [$this->subject, $this->client] = cibaSubjectWithDevice();
    });

    it('pushes a held action as an action, and a stated kind only once', function (): void {
        app(ApprovalKindHint::class)->expect(ApprovalKind::StepUp);
        app(ActionApprovals::class)->request($this->client, $this->subject, 'Confirm security access · localhost · 123456', str_repeat('a', 64), 300);
        $stepUp = $this->transport->latest()?->payload;

        app(ActionApprovals::class)->request($this->client, $this->subject, 'Deploy key wants to run organizations.delete · 7F3A', str_repeat('b', 64), 300);
        $action = $this->transport->latest()?->payload;

        expect($stepUp?->title)->toBe('Confirm security access')
            ->and(everythingPushed($stepUp))->not->toContain('123456')
            ->and($action?->title)->toBe('Approve: Deploy key wants to run organizations.delete')
            ->and($action?->data['purpose'] ?? null)->toBe('action')
            ->and($action?->data['v'] ?? null)->toBe('2')
            ->and($action?->data['install_id'] ?? null)->toBe(Device::query()->firstOrFail()->install_id);
    });

    it('shows no location for a held action or a CIBA request, whatever the request carried', function (): void {
        Request::setTrustedProxies(['10.244.0.0/16'], Request::HEADER_X_FORWARDED_FOR);
        app()->instance('request', Request::create('https://id.example.test/api', 'POST', server: [
            'REMOTE_ADDR' => '10.244.0.10', 'HTTP_X_FORWARDED_FOR' => '8.8.4.4', 'HTTP_CF_IPCOUNTRY' => 'DK', 'HTTP_CF_IPCITY' => 'Copenhagen', 'HTTP_USER_AGENT' => CHROME_ON_MAC,
        ]));

        app(ActionApprovals::class)->request($this->client, $this->subject, 'Deploy key wants to run organizations.delete · 7F3A', str_repeat('c', 64), 300);
        $action = $this->transport->latest()?->payload;
        app(BackchannelAuthentication::class)->request($this->client, ['openid'], $this->subject, 'Transfer DKK 4,200');
        $ciba = $this->transport->latest()?->payload;

        expect($action?->body)->toBe('Tap to review · expires in 5 min')
            ->and($ciba?->body)->toBe('Tap to review · expires in 5 min')
            ->and(everythingPushed($action).everythingPushed($ciba))->not->toContain('Copenhagen')->not->toContain('Denmark')->not->toContain('Chrome');
    });

    it('writes a CIBA prompt in the person\'s own language, without the binding message', function (): void {
        app(UserPreferences::class)->patch($this->subject, new PropertyPatch(0, (object) ['language' => 'da']));

        app(BackchannelAuthentication::class)->request($this->client, ['openid'], $this->subject, 'Transfer DKK 4,200 to ACME ApS');
        $payload = $this->transport->latest()?->payload;

        expect($payload?->title)->toBe('Agent beder om din godkendelse')
            ->and($payload?->body)->toBe('Tryk for at se den · udløber om 5 min.')
            ->and($payload?->data['purpose'] ?? null)->toBe('authentication')
            ->and($payload?->data['client_name'] ?? null)->toBe('Agent')
            ->and($payload?->data)->not->toHaveKeys(['location', 'device'])
            ->and(everythingPushed($payload))->not->toContain('4,200')->not->toContain('ciba@acme.test');
    });
});

describe('a browser sign-in, end to end', function (): void {
    it('pushes who, where and what, and the approvals API says the same', function (): void {
        $transport = new FakePushTransport;
        app()->instance(PushTransport::class, $transport);
        [$subject] = cibaSubjectWithDevice();
        Device::query()->update(['dpop_jkt' => 'test-thumbprint']);
        config(['trustedproxy.proxies' => ['10.244.0.0/16']]);

        $this->withCredentials();
        request()->setLaravelSession(app('session.store'));
        app(PlatformAuth::class)->holdForMfa(request(), $subject);
        $this->withCookie(config('session.cookie'), session()->getId())
            ->withServerVariables(['REMOTE_ADDR' => '10.244.0.10'])
            ->withHeaders(['X-Forwarded-For' => '8.8.4.4', 'CF-IPCountry' => 'DK', 'CF-IPCity' => 'Copenhagen', 'User-Agent' => CHROME_ON_MAC]);

        $code = $this->postJson('/mfa/mobile', ['operation' => 'start'])->assertOk()->json('code');
        $payload = $transport->latest()?->payload;
        $row = BackchannelAuthRequest::query()->sole();

        expect($row->getAttribute('origin_kind'))->toBe('sign_in')
            ->and($row->getAttribute('origin_country_code'))->toBe('DK')
            ->and($row->getAttribute('origin_city'))->toBe('Copenhagen')
            ->and($row->getAttribute('origin_user_agent'))->toBe(CHROME_ON_MAC)
            ->and($row->getAttribute('origin_host'))->toBe('localhost')
            ->and(json_encode($row->toArray()))->not->toContain('8.8.4.4');

        expect($payload?->title)->toStartWith('Sign in to ')->toEndWith('?')
            ->and($payload?->body)->toBe('Copenhagen, Denmark · Chrome on macOS · expires in 5 min')
            ->and($payload?->data['purpose'] ?? null)->toBe('sign_in')
            ->and(everythingPushed($payload))->not->toContain((string) $code)->not->toContain('8.8.4.4')->not->toContain('ciba@acme.test');

        $this->withoutMiddleware(RequireAuthenticator::class);
        $this->app->instance(TokenIntrospector::class, new class($subject) implements TokenIntrospector
        {
            public function __construct(private string $subject) {}

            public function introspect(string $token): Introspection
            {
                return Introspection::active($this->subject, 'cid_authenticator', ['approvals.read'], []);
            }

            public function revoke(string $jti): void {}
        });

        $response = $this->getJson('/api/v1/approvals', ['Authorization' => 'Bearer phone'])
            ->assertOk()
            ->assertJsonPath('data.0.kind', 'sign_in')
            ->assertJsonPath('data.0.purpose', 'action')
            ->assertJsonPath('data.0.origin.city', 'Copenhagen')
            ->assertJsonPath('data.0.origin.country', 'Denmark')
            ->assertJsonPath('data.0.origin.country_code', 'DK')
            ->assertJsonPath('data.0.origin.device', 'Chrome on macOS')
            ->assertJsonPath('data.0.origin.host', 'localhost');

        expect($response->json('data.0.origin.app'))->toBeString()
            ->and($response->getContent())->not->toContain('8.8.4.4');
    });

    it('reports no origin for a CIBA request', function (): void {
        app()->instance(PushTransport::class, new FakePushTransport);
        [$subject, $client] = cibaSubjectWithDevice();
        app(BackchannelAuthentication::class)->request($client, ['openid'], $subject, 'Transfer DKK 4,200');

        $this->withoutMiddleware(RequireAuthenticator::class);
        $this->app->instance(TokenIntrospector::class, new class($subject) implements TokenIntrospector
        {
            public function __construct(private string $subject) {}

            public function introspect(string $token): Introspection
            {
                return Introspection::active($this->subject, 'cid_authenticator', ['approvals.read'], []);
            }

            public function revoke(string $jti): void {}
        });

        $this->getJson('/api/v1/approvals', ['Authorization' => 'Bearer phone'])
            ->assertOk()
            ->assertJsonPath('data.0.kind', 'authentication')
            ->assertJsonPath('data.0.origin', null)
            ->assertJsonPath('data.0.binding_message', 'Transfer DKK 4,200');
    });
});
