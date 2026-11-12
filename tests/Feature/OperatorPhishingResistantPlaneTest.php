<?php

declare(strict_types=1);

use App\Platform\Actions\ActionRefused;
use App\Platform\Operators\OperatorAssurance;
use App\Platform\Operators\OperatorGate;
use App\Platform\Operators\OperatorStanding;
use App\Platform\Passkeys\PasskeyMethods;
use App\Platform\RootSignInGuard;
use App\Platform\StepUpPrompt;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\MfaMandate;
use Cbox\Id\Identity\Contracts\Passkeys;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\WebAuthnCredential;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\Models\PlatformOperator;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/*
|--------------------------------------------------------------------------
| The operator plane asks for a passkey (`cbox-id.operators.phishing_resistant`)
|--------------------------------------------------------------------------
|
| Off, nothing changes: a password and a time-based code open `/admin`. On, the plane
| opens only on a passkey proof that is recent enough, a code never counts, a dangerous
| action needs a fresh proof, and a locked-out operator gets back in through a one-time
| enrolment link issued from the command line.
*/

function operatorRuleOn(): void
{
    config()->set('cbox-id.operators.phishing_resistant', true);
}

/** An operator with no factor at all yet. */
function bareOperator(string $email = 'op@platform.test'): PlatformOperator
{
    platformRootDeployment();

    return app(PlatformOperators::class)->create($email, 'a-strong-operator-pass', 'Operator');
}

function operatorPasskey(PlatformOperator $operator, string $credentialId = 'cred-op'): void
{
    app(PlatformRoot::class)->run(fn () => WebAuthnCredential::query()->create([
        'user_id' => $operator->subject_id,
        'credential_id' => $credentialId,
        'public_key' => 'pk',
        'name' => 'Key',
        'sign_count' => 0,
    ]));
}

function operatorTotp(PlatformOperator $operator): void
{
    app(PlatformRoot::class)->run(function () use ($operator): void {
        $enrollment = app(Mfa::class)->enrollTotp($operator->subject_id, $operator->email);
        confirmTotpForTest($operator->subject_id, $enrollment->secret);
    });
}

/** Confirm with the operator's passkey, as the dialog does. */
function confirmOperatorPasskey(PlatformOperator $operator, ?string $as = null): TestResponse
{
    fakePasskeys($as ?? (string) $operator->subject_id);

    test()->postJson(route('operator.sudo.options'))->assertOk()
        ->assertJsonPath('userVerification', 'required')
        ->assertJsonPath('allowCredentials.0.id', 'cred-op');

    return test()->postJson(route('operator.sudo.confirm'), ['id' => 'cred-op', 'response' => []]);
}

it('changes nothing while the rule is off', function (): void {
    actAsOperator();

    $this->get(route('platform.environments'))->assertOk();
});

it('holds an operator who has only an authenticator app until they add a passkey', function (): void {
    operatorRuleOn();
    $operator = actAsOperator();

    expect(app(MfaMandate::class)->requiresEnrolment((string) $operator->subject_id))->toBeTrue();

    $this->get(route('platform.environments'))->assertRedirect(route('account'));
    $this->get(route('account'))->assertOk();
});

it('asks the factor an operator already holds before letting them enrol a passkey', function (): void {
    operatorRuleOn();
    $operator = bareOperator();
    operatorTotp($operator);

    // No session has cleared the code yet: enrolment is not "required", so the sign-in
    // door still asks for the code instead of waving a password through to enrolment.
    expect(app(MfaMandate::class)->requiresEnrolment((string) $operator->subject_id))->toBeFalse();
});

it('does not open the plane to a password and a code, and opens it after a passkey confirmation', function (): void {
    operatorRuleOn();
    $operator = bareOperator();
    operatorTotp($operator);
    operatorPasskey($operator);
    signInAsSubject((string) $operator->subject_id, ['pwd', 'otp', 'mfa']);

    expect(app(OperatorAssurance::class)->standing())->toBe(OperatorStanding::NeedsPasskey);

    // A document navigation goes to the full-page screen…
    $this->get(route('platform.environments'))->assertRedirect(route('operator.sudo'));
    $this->get(route('operator.sudo'))->assertOk();

    // …and a request a script made is refused in place, naming the passkey endpoints.
    $this->getJson(route('platform.environments'))
        ->assertForbidden()
        ->assertHeader(StepUpPrompt::HEADER, route('operator.sudo.confirm'))
        ->assertJsonPath('step_up.plane', 'operator')
        ->assertJsonPath('step_up.passkey.options', route('operator.sudo.options'));

    confirmOperatorPasskey($operator)->assertOk()->assertJsonPath('confirmed', true);

    nextRequest();
    $this->get(route('platform.environments'))->assertOk();
});

it('refuses a confirmation made with somebody else\'s passkey', function (): void {
    operatorRuleOn();
    $operator = bareOperator();
    operatorPasskey($operator);
    signInAsSubject((string) $operator->subject_id, ['pwd', 'otp', 'mfa']);

    confirmOperatorPasskey($operator, as: 'usr_somebody_else')->assertStatus(422);

    nextRequest();
    $this->get(route('platform.environments'))->assertRedirect(route('operator.sudo'));
});

it('opens the plane on a passkey sign-in, then asks again when idle and when the proof is old', function (): void {
    operatorRuleOn();
    config()->set('cbox-id.sessions.ttl_minutes', 480);
    config()->set('cbox-id.sessions.idle_minutes', 240);
    $operator = bareOperator();
    operatorPasskey($operator);
    signInAsSubject((string) $operator->subject_id, PasskeyMethods::used());

    $this->get(route('platform.environments'))->assertOk();

    // Idle: sixteen minutes with nothing on the plane.
    $this->travel(16)->minutes();
    nextRequest();
    expect(app(OperatorAssurance::class)->standing())->toBe(OperatorStanding::Idle);
    $this->get(route('platform.environments'))->assertRedirect(route('operator.sudo'));

    confirmOperatorPasskey($operator)->assertOk();

    // Absolute: used every ten minutes, so never idle, and still asked after an hour.
    foreach (range(1, 6) as $step) {
        $this->travel(10)->minutes();
        nextRequest();
        $this->get(route('platform.environments'))->assertOk();
    }

    $this->travel(2)->minutes();
    nextRequest();
    expect(app(OperatorAssurance::class)->standing())->toBe(OperatorStanding::Expired);
    $this->get(route('platform.environments'))->assertRedirect(route('operator.sudo'));
});

it('does not count a passkey used without user verification', function (): void {
    operatorRuleOn();
    config()->set('cbox-id.webauthn.user_verification', false);
    $operator = bareOperator();
    operatorPasskey($operator);

    expect(PasskeyMethods::used())->not->toContain('mfa');

    signInAsSubject((string) $operator->subject_id, PasskeyMethods::used());

    expect(app(OperatorAssurance::class)->standing())->toBe(OperatorStanding::Unverifiable);
    $this->get(route('platform.environments'))->assertRedirect(route('operator.sudo'));
});

it('asks for a fresh passkey before a critical operator action', function (): void {
    operatorRuleOn();
    $operator = bareOperator();
    operatorPasskey($operator);
    $other = app(PlatformOperators::class)->create('second@platform.test', 'a-strong-operator-pass', 'Second');
    signInAsSubject((string) $operator->subject_id, PasskeyMethods::used());

    // Six minutes on: still admitted to the plane, no longer fresh.
    $this->travel(6)->minutes();
    nextRequest();
    $this->get(route('platform.operators'))->assertOk();

    $this->withHeaders(['X-Inertia' => 'true'])
        ->post(route('platform.operators.toggle', ['operator' => $other->id]))
        ->assertForbidden()
        ->assertJsonPath('step_up.plane', 'operator');

    expect($other->fresh()?->isActive())->toBeTrue();

    $this->flushHeaders();
    confirmOperatorPasskey($operator)->assertOk();

    nextRequest();
    $this->from(route('platform.operators'))
        ->post(route('platform.operators.toggle', ['operator' => $other->id]))
        ->assertRedirect();

    expect($other->fresh()?->isActive())->toBeFalse();
});

it('keeps passkeys on in the root while an operator relies on one', function (): void {
    $operator = bareOperator();
    operatorPasskey($operator);

    $guard = app(RootSignInGuard::class);

    // Off: a password is a way in, so turning passkeys off strands nobody.
    $guard->assertOperatorsKeepAWayIn(['passkeys' => false]);

    operatorRuleOn();

    expect(fn () => $guard->assertOperatorsKeepAWayIn(['passkeys' => false]))
        ->toThrow(ActionRefused::class);

    $guard->assertOperatorsKeepAWayIn(['magic_link' => false]);
});

it('issues a one-time enrolment link that adds a passkey and then stops working', function (): void {
    operatorRuleOn();
    $operator = bareOperator('locked@platform.test');

    expect(Artisan::call('cbox-id:operator:passkey-link', ['email' => 'locked@platform.test']))->toBe(0);
    expect(preg_match('~/login/direct/passkey/([a-f0-9]{64})~', Artisan::output(), $match))->toBe(1);

    $base = route('login.direct.passkey', ['token' => $match[1]]);

    $this->get($base)->assertOk();

    app()->instance(Passkeys::class, new class implements Passkeys
    {
        public function register(string $userId, string $challenge, string $clientResponseJson, ?string $name = null): WebAuthnCredential
        {
            return WebAuthnCredential::query()->create(['user_id' => $userId, 'credential_id' => 'cred-new', 'public_key' => 'pk', 'name' => $name, 'sign_count' => 0]);
        }

        public function authenticate(string $credentialId, string $challenge, string $clientResponseJson): string
        {
            throw new RuntimeException('not used');
        }

        public function credentialById(string $credentialId): ?WebAuthnCredential
        {
            return null;
        }
    });

    $this->postJson($base.'/register/options')->assertOk()
        ->assertJsonPath('authenticatorSelection.userVerification', 'required');
    $this->postJson($base.'/register', ['id' => 'cred-new', 'response' => []])->assertOk()->assertJsonPath('ok', true);

    expect(app(OperatorAssurance::class)->hasPasskey((string) $operator->subject_id))->toBeTrue();

    // Spent.
    $this->get($base)->assertNotFound();
    $this->postJson($base.'/register/options')->assertNotFound();

    $actions = app(PlatformRoot::class)->run(fn () => AuditEntry::query()->where('action', 'like', 'operator.passkey_link.%')->pluck('action')->all());

    expect($actions)->toContain('operator.passkey_link.issued', 'operator.passkey_link.used');
});

it('issues no link for somebody who is not an active operator', function (): void {
    platformRootDeployment();

    expect(Artisan::call('cbox-id:operator:passkey-link', ['email' => 'nobody@platform.test']))->toBe(1);
});

it('guards the root environment\'s own console the same way', function (): void {
    multiTenantDeployment();
    config()->set('cbox-id.environments.base_domains', ['cboxid.com']);
    operatorRuleOn();
    $operator = bareOperator();
    operatorTotp($operator);
    operatorPasskey($operator);
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified((string) $operator->subject_id, $operator->email));
    signInAsSubject((string) $operator->subject_id, ['pwd', 'otp', 'mfa']);

    $page = 'https://cboxid.com/admin/environment/social-sign-in';

    $this->get($page)->assertRedirect('https://cboxid.com/admin/confirm');

    fakePasskeys((string) $operator->subject_id);
    $this->postJson('https://cboxid.com/admin/confirm/options')->assertOk();
    $this->postJson('https://cboxid.com/admin/confirm', ['id' => 'cred-op', 'response' => []])->assertOk();
    nextRequest();
    $this->get($page)->assertOk();

    // Changing how everybody signs in is critical: the proof must be fresh, and a password
    // step-up is not offered in its place.
    $this->travel(6)->minutes();
    nextRequest();

    $this->postJson($page, ['provider' => 'google', 'clientId' => 'x', 'clientSecret' => 'y', 'forEnvironment' => true])
        ->assertForbidden()
        ->assertJsonPath('step_up.plane', 'operator')
        ->assertJsonPath('step_up.passkey.options', 'https://cboxid.com/admin/confirm/options');
});

it('asks for a fresh passkey before a new SSO connection or a re-enabled social provider in the root', function (): void {
    multiTenantDeployment();
    config()->set('cbox-id.environments.base_domains', ['cboxid.com']);
    operatorRuleOn();
    $operator = bareOperator();
    operatorTotp($operator);
    operatorPasskey($operator);
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified((string) $operator->subject_id, $operator->email));
    signInAsSubject((string) $operator->subject_id, ['pwd', 'otp', 'mfa']);

    fakePasskeys((string) $operator->subject_id);
    $this->postJson('https://cboxid.com/admin/confirm/options')->assertOk();
    $this->postJson('https://cboxid.com/admin/confirm', ['id' => 'cred-op', 'response' => []])->assertOk();

    // A social provider that was turned off, in the root.
    $provider = app(PlatformRoot::class)->run(function () {
        $connection = app(Connections::class)->create(null, ConnectionType::Oidc, 'Google', [
            'issuer' => 'https://accounts.google.com', 'client_id' => 'x', 'provider' => 'google',
        ]);

        return $connection->id;
    });

    $this->travel(6)->minutes();
    nextRequest();

    $before = app(PlatformRoot::class)->run(fn () => Connection::query()->count());

    $this->postJson('https://cboxid.com/admin/environment/social-sign-in/'.$provider.'/enable')
        ->assertForbidden()
        ->assertJsonPath('step_up.plane', 'operator');

    $this->postJson('https://cboxid.com/admin/environment/single-sign-on', ['name' => 'Okta', 'type' => 'oidc', 'issuer' => 'https://idp.example.test', 'client_id' => 'x', 'client_secret' => 'y', 'environmentWide' => true])
        ->assertForbidden()
        ->assertJsonPath('step_up.plane', 'operator');

    expect(app(PlatformRoot::class)->run(fn () => Connection::query()->count()))->toBe($before);

    // The same two are ordinary writes where the rule does not govern.
    expect(OperatorGate::guards('sso.connections.create', false))->toBeTrue()
        ->and(OperatorGate::guards('signin.social.enable', false))->toBeTrue()
        ->and(OperatorGate::guards('sso.domains.add', false))->toBeFalse();
});

it('does not offer the passkey prompt on the console when passkeys live on a login host', function (): void {
    operatorRuleOn();
    $operator = bareOperator();
    operatorPasskey($operator);
    signInAsSubject((string) $operator->subject_id, ['pwd', 'otp', 'mfa']);

    config()->set('cbox-id.hosts.login', 'login.cboxid.com');
    nextRequest();

    $assurance = app(OperatorAssurance::class);

    expect($assurance->confirmsHere())->toBeFalse()
        ->and($assurance->elsewhereMessage())->toContain('login.cboxid.com');

    // A proof recorded by the console's own sign-in (the id token said phr) opens the plane.
    $assurance->confirm(now()->getTimestamp());

    expect($assurance->standing())->toBe(OperatorStanding::Admitted)
        ->and($assurance->fresh())->toBeTrue();
});
