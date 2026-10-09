<?php

declare(strict_types=1);

use App\Platform\FrontendApi\LoginTickets;
use App\Platform\PlatformAuth;
use App\Platform\Sudo;
use Cbox\Id\FrontendApi\Contracts\PublishableKeys;
use Cbox\Id\FrontendApi\Enums\KeyMode;
use Cbox\Id\FrontendApi\FrontendApiServiceProvider;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\SmsFactorPolicies;
use Cbox\Id\Identity\Contracts\SmsFactors;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Enums\MfaRequirement;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Identity\ValueObjects\SmsFactorPolicy;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Crypto\TotpAuthenticator;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Otp\Sms\Contracts\SmsSender;
use Cbox\Id\Otp\Sms\Contracts\SmsSendGuard;
use Cbox\Id\Otp\Sms\Senders\ArraySmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| SMS as a second factor, through every door a person meets it at.
|--------------------------------------------------------------------------
|
| The framework proves the factor itself (sealing, policy, caps). These prove the app's
| doors: the hosted challenge, My account, the embedded channel, the MFA mandate's hold —
| and that a phone number is never the reason somebody gets in on a password alone.
*/

beforeEach(function (): void {
    installedDeployment();
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    Cache::flush();

    // No cooldown between texts here: several tests enrol and then challenge the same
    // number within a second. The cooldown has its own tests in the framework.
    config()->set('cbox-id.sms.limits.cooldown_seconds', 0);
    app()->forgetInstance(SmsSendGuard::class);

    $this->sms = new ArraySmsSender;
    app()->instance(SmsSender::class, $this->sms);
});

function smsfPolicy(bool $enabled = true, array $countries = ['DK']): void
{
    app(SmsFactorPolicies::class)->setForEnvironment(new SmsFactorPolicy($enabled, $countries));
}

/** A person with a password, in an organization as $role. */
function smsfPerson(string $email = 'sam@acme.test', MembershipRole $role = MembershipRole::Member): string
{
    $subject = app(Subjects::class)->create($email, 'Sam Sms', 'a-strong-unbreached-passphrase');
    app(Subjects::class)->markEmailVerified($subject->id, $email);
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-'.substr(md5($email), 0, 8)));
    app(Memberships::class)->add($org->id, $subject->id, $role);

    return $subject->id;
}

/** Enrol and confirm a number for $subjectId through the factor itself. */
function smsfEnrol(string $subjectId, ArraySmsSender $sms, string $number = '+4512345678'): void
{
    app(SmsFactors::class)->beginEnrolment($subjectId, $number);
    expect(app(SmsFactors::class)->confirmEnrolment($subjectId, (string) $sms->latestCode()))->toBeTrue();
}

function smsfSignIn(string $email = 'sam@acme.test'): TestResponse
{
    return test()->from(route('login'))->post(route('login.attempt'), ['email' => $email, 'password' => 'a-strong-unbreached-passphrase']);
}

/** The signed-in person's own session, the way a real sign-in leaves it, behind sudo. */
function smsfSignedIn(string $subjectId): void
{
    $membership = app(Memberships::class)->forUser($subjectId)->first();
    $session = app(SessionManager::class)->start($subjectId, $membership?->organization_id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(Sudo::class)->confirm();
}

// ------------------------------------------------------------- the hosted challenge

it('holds a password sign-in for a phone number and completes it with the texted code', function (): void {
    smsfPolicy();
    $id = smsfPerson();
    smsfEnrol($id, $this->sms);

    smsfSignIn()->assertRedirect(route('mfa'));
    expect(session()->has(PlatformAuth::SESSION_KEY))->toBeFalse();

    $page = $this->get(route('mfa'))->assertOk()->inertiaProps();
    expect($page['factors'])->toBe(['totp' => false, 'sms' => true]);

    // Opening the page sends nothing: the text is a button.
    $this->sms->assertSentCount(1);

    $this->from(route('mfa'))->post(route('mfa.sms.send'))->assertRedirect(route('mfa'))->assertSessionHasNoErrors();
    expect(flashed('smsSentTo'))->toBe('+45 ******78');
    $this->sms->assertSentCount(2);

    $this->from(route('mfa'))->post(route('mfa.sms.verify'), ['smsCode' => (string) $this->sms->latestCode()])
        ->assertRedirect(route('dashboard'));

    $session = Session::query()->findOrFail(session(PlatformAuth::SESSION_KEY));

    // `sms`, not `otp`: a relying party can tell a texted code from an authenticator.
    expect($session->amr)->toBe(['pwd', 'sms', 'mfa']);
})->group('security');

it('offers the authenticator first and SMS beside it when a person has both', function (): void {
    smsfPolicy();
    $id = smsfPerson();
    smsfEnrol($id, $this->sms);
    $enrolment = app(Mfa::class)->enrollTotp($id, 'sam@acme.test');
    app(Mfa::class)->confirmTotp($id, app(TotpAuthenticator::class)->codeAt($enrolment->secret, time() - 30));

    smsfSignIn()->assertRedirect(route('mfa'));

    expect($this->get(route('mfa'))->inertiaProps()['factors'])->toBe(['totp' => true, 'sms' => true]);
});

it('does not hold anybody for a number the environment no longer accepts', function (): void {
    smsfPolicy();
    $id = smsfPerson();
    smsfEnrol($id, $this->sms);

    // SMS switched off: the platform will not text the number, so holding the person for
    // it would strand them. With no mandate, the password is the sign-in.
    smsfPolicy(false);

    smsfSignIn()->assertRedirect(route('dashboard'));
    expect(app(SmsFactors::class)->isEnrolled($id))->toBeTrue();
});

it('counts a wrong texted code against the account lockout, and refuses a send once locked', function (): void {
    smsfPolicy();
    $id = smsfPerson();
    smsfEnrol($id, $this->sms);
    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(lockoutThreshold: 3));

    smsfSignIn()->assertRedirect(route('mfa'));
    $this->from(route('mfa'))->post(route('mfa.sms.send'));

    foreach (range(1, 3) as $ignored) {
        $this->from(route('mfa'))->post(route('mfa.sms.verify'), ['smsCode' => '000000'])->assertSessionHasErrors('smsCode');
    }

    expect(DB::table('audit_logs')->where('action', 'user.sign_in_failed')->where('target_id', $id)->count())->toBe(3);

    // Locked: even the right code is refused, and no further text goes out.
    $sent = count($this->sms->messages);
    $this->from(route('mfa'))->post(route('mfa.sms.send'))->assertSessionHasErrors('smsCode');
    expect($this->sms->messages)->toHaveCount($sent)
        ->and(session()->has(PlatformAuth::SESSION_KEY))->toBeFalse();
})->group('security');

it('says to wait when the send caps refuse, and texts nothing', function (): void {
    smsfPolicy();
    $id = smsfPerson();
    smsfEnrol($id, $this->sms);
    config()->set('cbox-id.sms.limits.per_number_per_day', 1);
    app()->forgetInstance(SmsSendGuard::class);

    smsfSignIn()->assertRedirect(route('mfa'));

    $this->from(route('mfa'))->post(route('mfa.sms.send'))
        ->assertSessionHasErrors(['smsCode' => __('auth.mfa.sms.wait')]);

    $this->sms->assertSentCount(1);
    expect(AuditEntry::query()->where('action', 'sms.refused')->exists())->toBeTrue();
});

it('sends nobody to the challenge without a pending sign-in', function (): void {
    $this->post(route('mfa.sms.send'))->assertRedirect(route('login'));
    $this->sms->assertNothingSent();
});

it('writes the challenge page and the text in the person\'s language', function (): void {
    smsfPolicy();
    $id = smsfPerson();
    smsfEnrol($id, $this->sms);

    smsfSignIn();
    $this->withHeader('Accept-Language', 'da')->from(route('mfa'))->post(route('mfa.sms.send'));

    expect($this->sms->latest()?->body)->toContain('bekræftelseskode');
});

// ------------------------------------------------------------- My account

it('adds a phone number from My account, proving it with a code, and hands out recovery codes', function (): void {
    smsfPolicy();
    $id = smsfPerson();
    smsfSignedIn($id);

    expect(accountSecurity()['smsFactor'])->toMatchArray(['enrolled' => false, 'pending' => false, 'blocked' => false, 'countries' => ['DK']]);

    inertiaRequest(fn () => $this->from(route('account'))->post(route('account.mfa.sms.enrol'), ['phone' => '12 34 56 78', 'country' => 'dk']))
        ->assertSessionHasNoErrors();

    expect(flashed('smsEnrolmentSentTo'))->toBe('+45 ******78')
        ->and(accountSecurity()['smsFactor'])->toMatchArray(['pending' => true, 'maskedNumber' => '+45 ******78']);

    inertiaRequest(fn () => $this->from(route('account'))->post(route('account.mfa.sms.confirm'), ['smsCode' => '000000']))
        ->assertSessionHasErrors('smsCode');

    inertiaRequest(fn () => $this->from(route('account'))->post(route('account.mfa.sms.confirm'), ['smsCode' => (string) $this->sms->latestCode()]))
        ->assertSessionHasNoErrors();

    expect(flashed('recoveryCodes'))->toHaveCount(10)
        ->and(accountSecurity()['smsFactor'])->toMatchArray(['enrolled' => true, 'maskedNumber' => '+45 ******78'])
        ->and(app(SmsFactors::class)->isEnrolled($id))->toBeTrue();
});

it('says why a number is refused, without storing or sending anything', function (): void {
    smsfPolicy(countries: ['DK']);
    $id = smsfPerson();
    smsfSignedIn($id);

    inertiaRequest(fn () => $this->from(route('account'))->post(route('account.mfa.sms.enrol'), ['phone' => '+46 70 123 45 67']))
        ->assertSessionHasErrors(['phone' => 'Text messages cannot be sent to numbers in that country.']);

    $this->sms->assertNothingSent();
    expect(app(SmsFactors::class)->details($id))->toBeNull();
});

it('hides the panel where SMS is not offered, and keeps an owner from making SMS their only factor', function (): void {
    $member = smsfPerson();
    smsfSignedIn($member);
    expect(accountSecurity()['smsFactor'])->toBeNull();

    smsfPolicy();
    $owner = smsfPerson('owner@acme.test', MembershipRole::Owner);
    smsfSignedIn($owner);

    expect(accountSecurity()['smsFactor']['blocked'])->toBeTrue();

    inertiaRequest(fn () => $this->from(route('account'))->post(route('account.mfa.sms.enrol'), ['phone' => '+4512345678']))
        ->assertSessionHasErrors('phone');
    $this->sms->assertNothingSent();
});

it('removes your own number through the account action, as you', function (): void {
    smsfPolicy();
    $id = smsfPerson();
    smsfEnrol($id, $this->sms);
    smsfSignedIn($id);

    inertiaRequest(fn () => $this->from(route('account'))->delete(route('account.mfa.sms.destroy')))
        ->assertSessionHasNoErrors();

    $entry = AuditEntry::query()->where('action', 'user.mfa_sms_removed')->sole();

    expect(app(SmsFactors::class)->details($id))->toBeNull()
        ->and($entry->actor_id)->toBe($id)
        ->and($entry->target_id)->toBe($id);

    // Nothing left to remove: the same answer, and nothing more on the trail.
    inertiaRequest(fn () => $this->from(route('account'))->delete(route('account.mfa.sms.destroy')))->assertSessionHasNoErrors();
    expect(AuditEntry::query()->where('action', 'user.mfa_sms_removed')->count())->toBe(1);
});

it('lets a person held by the MFA mandate enrol a phone number', function (): void {
    smsfPolicy();
    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(mfa: MfaRequirement::Required));
    $id = smsfPerson();
    smsfSignedIn($id);

    $this->get('/dashboard')->assertRedirect(route('account'));

    // The SUBMITS reach their controller — before, every enrolment POST was bounced back
    // to the page that held the person, so a required second factor could not be set up.
    inertiaRequest(fn () => $this->from(route('account'))->post(route('account.mfa.sms.enrol'), ['phone' => '+4512345678']))
        ->assertSessionHasNoErrors();
    inertiaRequest(fn () => $this->from(route('account'))->post(route('account.mfa.sms.confirm'), ['smsCode' => (string) $this->sms->latestCode()]))
        ->assertSessionHasNoErrors();

    $this->get('/dashboard')->assertOk();
})->group('security');

it('holds an administrator whose only factor is SMS until they add a stronger one', function (): void {
    app(SmsFactorPolicies::class)->setForEnvironment(new SmsFactorPolicy(true, ['DK'], privilegedNeedStrongerFactor: false));
    $id = smsfPerson('admin@acme.test', MembershipRole::Admin);
    smsfEnrol($id, $this->sms);

    // The environment then decides SMS cannot stand alone for administrators.
    smsfPolicy();
    smsfSignedIn($id);

    $this->get('/dashboard')->assertRedirect(route('account'));
    expect(accountSecurity()['smsFactor']['needsStrongerFactor'])->toBeTrue();

    $enrolment = app(Mfa::class)->enrollTotp($id, 'admin@acme.test');
    app(Mfa::class)->confirmTotp($id, app(TotpAuthenticator::class)->codeAt($enrolment->secret, time()));

    $this->get('/dashboard')->assertOk();
})->group('security');

// ------------------------------------------------------------- the embedded channel

it('lists the factors, texts the code and completes an embedded sign-in with it', function (): void {
    $this->app['config']->set('cbox-id.frontend_api.enabled', true);
    (new FrontendApiServiceProvider($this->app))->boot();
    $key = app(PublishableKeys::class)->issue('Site', KeyMode::Test, ['https://app.acme.test']);
    $headers = ['X-Cbox-Publishable-Key' => $key->key, 'Origin' => 'https://app.acme.test'];

    smsfPolicy();
    $id = smsfPerson();
    smsfEnrol($id, $this->sms);

    $first = $this->withHeaders($headers)->postJson('/frontend/v1/sign-in', [
        'email' => 'sam@acme.test', 'password' => 'a-strong-unbreached-passphrase',
    ])->assertOk();

    expect($first->json('status'))->toBe('mfa_required')
        ->and($first->json('factors'))->toBe(['sms', 'recovery_code'])
        ->and($first->json())->not->toHaveKey('to');

    $this->withHeaders($headers)->postJson('/frontend/v1/sign-in/factor/sms', ['mfa_token' => $first->json('mfa_token')])
        ->assertOk()
        ->assertJsonPath('status', 'sent')
        ->assertJsonPath('to', '+45 ******78');

    $second = $this->withHeaders($headers)->postJson('/frontend/v1/sign-in/factor', [
        'mfa_token' => $first->json('mfa_token'),
        'method' => 'sms',
        'code' => (string) $this->sms->latestCode(),
    ])->assertOk();

    $ticket = app(LoginTickets::class)->redeem($second->json('login_ticket'), app(EnvironmentContext::class)->requireEnvironment()->environmentKey());

    expect($ticket?->subject_id)->toBe($id)
        ->and($ticket?->amr)->toBe(['pwd', 'sms', 'mfa']);
})->group('security');

it('spends one of the ticket\'s attempts on every embedded send', function (): void {
    $this->app['config']->set('cbox-id.frontend_api.enabled', true);
    (new FrontendApiServiceProvider($this->app))->boot();
    $key = app(PublishableKeys::class)->issue('Site', KeyMode::Test, ['https://app.acme.test']);
    $headers = ['X-Cbox-Publishable-Key' => $key->key, 'Origin' => 'https://app.acme.test'];

    smsfPolicy();
    $id = smsfPerson();
    smsfEnrol($id, $this->sms);

    $token = $this->withHeaders($headers)->postJson('/frontend/v1/sign-in', [
        'email' => 'sam@acme.test', 'password' => 'a-strong-unbreached-passphrase',
    ])->json('mfa_token');

    foreach (range(1, 5) as $ignored) {
        $this->withHeaders($headers)->postJson('/frontend/v1/sign-in/factor/sms', ['mfa_token' => $token])->assertOk();
    }

    // Five texts is all one proved password buys through this door.
    $this->withHeaders($headers)->postJson('/frontend/v1/sign-in/factor/sms', ['mfa_token' => $token])
        ->assertUnauthorized()
        ->assertJsonPath('status', 'invalid');

    $this->sms->assertSentCount(6);
})->group('security');
