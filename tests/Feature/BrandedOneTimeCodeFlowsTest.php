<?php

declare(strict_types=1);

use App\Mail\OneTimeCodeMail;
use App\Platform\Appearance\BrandImage;
use App\Platform\Appearance\BrandImages;
use App\Platform\Appearance\BrandImageUpload;
use App\Platform\Locale\LocaleResolver;
use App\Platform\Otp\BrandedEmailOtpChannel;
use App\Platform\PlatformAuth;
use App\Platform\Radar\Enums\RadarAction;
use App\Platform\Radar\Enums\RadarMode;
use App\Platform\Radar\Enums\RadarRuleScope;
use App\Platform\Radar\RadarPolicy;
use App\Platform\Radar\RadarRules;
use Cbox\Id\Identity\Contracts\BreachedPasswordCheck;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\IdentityLink;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Identity\NeverBreachedCheck;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\DeviceAuthorization;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Enums\GrantPollStatus;
use Cbox\Id\OAuthServer\Models\DeviceCode;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Otp\Contracts\OtpChannels;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Cbox\Id\Whitelabel\Contracts\BrandProfiles;
use Cbox\Risk\Contracts\RiskScorer;
use Cbox\Risk\Enums\Outcome;
use Cbox\Risk\ValueObjects\RiskAssessment;
use Cbox\Risk\ValueObjects\RiskContext;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Support\RootGoogle;

/*
|--------------------------------------------------------------------------
| The code-by-email flows send Cbox ID's branded mail, not the framework's plain text
|--------------------------------------------------------------------------
| Every flow that proves "you have this inbox" with a code issues it through the
| framework's OTP service, and the service delivers through whichever channel is bound to
| `email`. These run the real flows — a Radar step-up on a vendor's sign-in page, approving
| a device, linking Google to an existing account at the platform root — with the REAL
| channel and a faked mailer, and hold that the code arrives as the brand's own
| translated mail, and that the code in that mail is the one the flow accepts.
*/

beforeEach(function (): void {
    app()->instance(BreachedPasswordCheck::class, new NeverBreachedCheck);
    Mail::fake();
});

function forceRisk(Outcome $outcome): void
{
    config(['risk.mode' => 'enforce']);

    app()->instance(RiskScorer::class, new class($outcome) implements RiskScorer
    {
        public function __construct(private readonly Outcome $outcome) {}

        public function assess(RiskContext $context): RiskAssessment
        {
            return new RiskAssessment(99.0, $this->outcome, []);
        }
    });
}

/** The last one-time-code mail sent to $email. */
function codeMailTo(string $email): OneTimeCodeMail
{
    $mail = Mail::sent(OneTimeCodeMail::class, fn (OneTimeCodeMail $mail): bool => $mail->hasTo($email))->last();

    expect($mail)->toBeInstanceOf(OneTimeCodeMail::class, "no one-time-code mail went to {$email}");

    /** @var OneTimeCodeMail $mail */
    return $mail;
}

it('binds the framework’s email code channel to the branded one', function (): void {
    expect(app(OtpChannels::class)->channel('email'))->toBeInstanceOf(BrandedEmailOtpChannel::class);
});

it('sends a vendor’s Radar step-up code in the vendor’s brand and the visitor’s language', function (): void {
    multiTenantDeployment();
    platformRootEnvironment();

    $vendor = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Northwind Traders',
        ownerEmail: 'owner@northwind.test',
        ownerName: 'Northwind Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));
    $environment = serveOnTestHost($vendor->environment);
    $environment->forceFill(['name' => 'northwind', 'settings' => ['brand_color' => '#0f766e']])->save();

    app(EnvironmentContext::class)->set(GenericEnvironment::of($environment->id));
    app(BrandImages::class)->store(BrandImageUpload::fromDataUri(BrandImage::Logo, pngDataUri()), null);
    app(BrandProfiles::class)->forEnvironment()?->forceFill(['app_name' => 'Northwind'])->save();

    $subject = app(Subjects::class)->create('erik@northwind.test', 'Erik', 'a-strong-password-1234');
    $organization = app(Organizations::class)->create(new NewOrganization('Northwind Retail', 'northwind-retail'));
    app(Memberships::class)->add($organization->id, $subject->id, MembershipRole::Member);

    forceRisk(Outcome::StepUp);

    test()->withCookie(LocaleResolver::COOKIE, 'sv')
        ->from(route('login'))
        ->post(route('login.attempt'), ['email' => 'erik@northwind.test', 'password' => 'a-strong-password-1234', 'identified' => true])
        ->assertRedirect(route('login.step-up'));

    $mail = codeMailTo('erik@northwind.test');
    $html = $mail->render();

    expect($mail->purpose)->toBe('login_step_up')
        ->and($mail->locale)->toBe('sv')
        ->and($mail->brand()->name)->toBe('Northwind')
        ->and($mail->brand()->accent)->toBe('#0f766e')
        ->and($mail->brand()->logo)->toContain('/brand-assets/')
        ->and($html)->toContain('<html lang="sv"')
        ->and($mail->envelope()->subject)->toBe('Din inloggningskod till Northwind')
        ->and($html)->toContain('Bekräfta att det är du')->not->toContain('Your verification code')
        ->and($html)->toContain($mail->code);

    // The code in the mail is the code the step-up accepts.
    test()->from(route('login.step-up'))->post(route('login.step-up.verify'), ['code' => $mail->code])
        ->assertRedirect(route('dashboard'));

    expect(Session::query()->where('user_id', $subject->id)->latest()->first()?->amr)->toContain('otp');
});

it('sends the device-approval code as the branded device mail, and approves on it', function (): void {
    installedDeployment();

    $subject = app(Subjects::class)->create('dev@acme.test', 'Dev User', 'super-secret-1234');
    $organization = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-device-mail'));
    app(Memberships::class)->add($organization->id, $subject->id, MembershipRole::Owner);
    session([PlatformAuth::SESSION_KEY => app(SessionManager::class)->start($subject->id, $organization->id, ['pwd'])->id]);

    $client = app(ClientRegistry::class)->register(new NewClient(
        name: 'TV App', type: ClientType::Confidential, grantTypes: ['urn:ietf:params:oauth:grant-type:device_code'], scopes: ['openid'],
    ))->client;
    $result = app(DeviceAuthorization::class)->request($client, ['openid']);
    lookUpDeviceCode($result->userCode);

    app(RadarPolicy::class)->setMode(RadarMode::Enforce);
    app(RadarRules::class)->create('Unusual', null, RadarAction::Challenge, RadarRuleScope::SignIn, [
        ['field' => 'email_domain', 'operator' => 'eq', 'value' => 'acme.test'],
    ]);

    approveDevice()->assertRedirect(route('device'));

    $mail = codeMailTo('dev@acme.test');

    expect($mail->purpose)->toBe('device_step_up')
        ->and($mail->kind())->toBe('device')
        ->and($mail->locale)->toBe('en')
        ->and($mail->render())->toContain('approve their device');

    inertiaRequest(fn (): TestResponse => test()->from(route('device'))->post(route('device.verify'), ['stepUpCode' => $mail->code]))
        ->assertRedirect(route('device'));

    expect(DeviceCode::query()->value('status'))->toBe(GrantPollStatus::Approved);
});

it('sends the account-linking code in the platform root’s brand, and links on it', function (): void {
    RootGoogle::deployment();

    $account = provisionAccount('owner@acme.example');
    $ownerId = (string) $account['subjectId'];

    RootGoogle::operatorSignedIn();
    $google = RootGoogle::turnOn();
    RootGoogle::signedOutBrowser();

    // The root environment's Branding page: a colour and an uploaded logo.
    $root = app(PlatformRoot::class)->model();
    Environment::query()->whereKey($root->id)->sole()->forceFill(['settings' => ['brand_color' => '#7c3aed']])->save();
    app(EnvironmentContext::class)->runAs($root, static function (): void {
        app(BrandImages::class)->store(BrandImageUpload::fromDataUri(BrandImage::Logo, pngDataUri()), null);
    });

    expect(parse_url((string) RootGoogle::signIn('owner@acme.example')->headers->get('Location'), PHP_URL_PATH))->toBe('/login/link');

    $hold = (string) test()->get('https://'.RootGoogle::HOST.'/login/link')->assertOk()->inertiaProps('hold');

    test()->from('https://'.RootGoogle::HOST.'/login/link')
        ->post('https://'.RootGoogle::HOST.'/login/link/code', ['hold' => $hold, 'channel' => 'email'])
        ->assertRedirect();

    $mail = codeMailTo('owner@acme.example');
    $html = $mail->render();

    expect($mail->purpose)->toBe('link_identity')
        ->and($mail->brand()->accent)->toBe('#7c3aed')
        // On the origin the deployment states about itself (`app.url`), as every mailed link is.
        ->and(parse_url((string) $mail->brand()->logo, PHP_URL_HOST))->toBe(parse_url((string) config('app.url'), PHP_URL_HOST))
        ->and($html)->toContain('#7c3aed')
        ->and($html)->toContain('Link a sign-in method')
        ->and($html)->toContain($mail->code)
        ->and($mail->envelope()->subject)->not->toContain($mail->code);

    test()->from('https://'.RootGoogle::HOST.'/login/link')
        ->post('https://'.RootGoogle::HOST.'/login/link', ['hold' => $hold, 'method' => 'email_code', 'answer' => $mail->code])
        ->assertRedirect();

    $links = app(PlatformRoot::class)->run(static fn (): array => IdentityLink::query()->where('user_id', $ownerId)->get()->all());

    expect($links)->toHaveCount(1)
        ->and($links[0]->connection_id)->toBe($google->id);
});
