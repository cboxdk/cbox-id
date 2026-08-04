<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use App\Platform\Enums\AttemptOutcome;
use App\Platform\PlatformAuth;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\BreachedPasswordCheck;
use Cbox\Id\Identity\Contracts\MagicLink;
use Cbox\Id\Identity\Contracts\Passkeys;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Identity\Exceptions\UnknownCredential;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Identity\Models\WebAuthnCredential;
use Cbox\Id\Identity\NeverBreachedCheck;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\Enums\AccountRole;
use Cbox\Id\Platform\Models\AccountMember;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

/**
 * The doors an SSO mandate did not reach.
 *
 * `SsoEnforcement::Required` means "SSO is the only way in", the console says exactly that
 * to the administrator who turns it on, and until this only the two PASSWORD doors
 * honoured it. A magic link, a passkey, an operator social button, an accepted invitation
 * and a workspace password reset all still opened a session — so the mandate was a claim
 * rather than a boundary, and an operator who trusted the sentence they were shown had
 * five ways around it.
 *
 * Every test here does the same three things in the same order, and the order is the
 * point: prove the door WORKS, turn the mandate on, prove the same door now refuses and
 * says why. A test that only asserted the refusal would pass just as well against a door
 * that was broken.
 */
beforeEach(function (): void {
    app()->instance(BreachedPasswordCheck::class, new NeverBreachedCheck);
});

/*
|--------------------------------------------------------------------------
| Fixtures
|--------------------------------------------------------------------------
*/

/**
 * An organization with an ACTIVE connection — everything a mandate needs to be both
 * enforceable and explainable — and the mandate itself deliberately left off.
 *
 * @return array{0: string, 1: string} organization id, the URL its people start SSO at
 */
function doorOrganization(string $name, string $slug): array
{
    $org = app(Organizations::class)->create(new NewOrganization($name, $slug));

    $connection = app(Connections::class)->create(
        $org->id,
        ConnectionType::Oidc,
        $name.' IdP',
        ['issuer' => 'https://idp.'.$slug.'.test', 'client_id' => 'abc', 'client_secret' => 's', 'signing_key' => 'k'],
    );
    app(Connections::class)->activate($org->id, $connection->id);

    return [$org->id, url("/sso/oidc/{$connection->id}/redirect")];
}

/**
 * A subject who belongs to one, holding a password nobody here will need.
 *
 * @return array{0: string, 1: string, 2: string} subject id, organization id, start URL
 */
function doorSubject(string $email, string $name, string $slug): array
{
    [$organizationId, $startUrl] = doorOrganization($name, $slug);

    $subject = app(Subjects::class)->create($email, 'Door', 'a-strong-unbreached-passphrase');
    app(Memberships::class)->add($organizationId, $subject->id, MembershipRole::Member);

    return [$subject->id, $organizationId, $startUrl];
}

/** Turn the mandate on, through the contract, so the session revocation runs with it. */
function doorMandate(string $organizationId): void
{
    app(AuthPolicies::class)->setForOrganization(
        $organizationId,
        new AuthPolicy(sso: SsoEnforcement::Required),
    );
}

/** Whether ANY live session remains for this subject. */
function doorHasLiveSession(string $subjectId): bool
{
    $sessions = app(SessionManager::class);

    return Session::query()->where('user_id', $subjectId)->get()
        ->contains(fn (Session $session): bool => $sessions->active($session->id) !== null);
}

/** A Passkeys stand-in that always asserts as one subject — the ceremony is proven upstream. */
function doorFakePasskeys(string $subjectId): void
{
    app()->instance(Passkeys::class, new class($subjectId) implements Passkeys
    {
        public function __construct(private readonly string $subjectId) {}

        public function register(string $userId, string $challenge, string $clientResponseJson, ?string $name = null): WebAuthnCredential
        {
            return new WebAuthnCredential(['user_id' => $userId, 'credential_id' => 'cred_door', 'name' => $name]);
        }

        public function authenticate(string $credentialId, string $challenge, string $clientResponseJson): string
        {
            return $credentialId === 'cred_door' ? $this->subjectId : throw new UnknownCredential('none');
        }

        public function credentialById(string $credentialId): ?WebAuthnCredential
        {
            return null;
        }
    });
}

/**
 * An account whose own organization mandates SSO, on the platform root.
 *
 * @return array{0: AccountMember, 1: string} the owner, and the organization's name
 */
function doorMandatedAccount(string $email): array
{
    platformRootDeployment();

    $provisioned = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Workspace Co',
        ownerEmail: $email,
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    $organizationId = (string) $provisioned->account->organization_id;

    // In the ROOT's scope: the account's organization lives there, and a policy written
    // under this host's ambient scope would be written for a different environment.
    app(PlatformRoot::class)->run(function () use ($organizationId): void {
        doorMandate($organizationId);
    });

    return [$provisioned->member, 'Workspace Co'];
}

/*
|--------------------------------------------------------------------------
| The tenant plane
|--------------------------------------------------------------------------
*/

it('refuses a redeemed magic link, and ends the session the redemption started', function (): void {
    [$subjectId, $organizationId, $startUrl] = doorSubject('magic@acme.test', 'Acme Inc', 'acme-magic');

    // The door works. Without this the refusal below would also pass against a door that
    // was simply broken.
    $this->get('/magic/'.app(MagicLink::class)->request('magic@acme.test'))
        ->assertRedirect(route('dashboard'));
    expect(session()->has(PlatformAuth::SESSION_KEY))->toBeTrue();

    forgetSubjectSession();
    doorMandate($organizationId);

    // Issued BEFORE the request boundary: nextRequest() drops the ambient environment
    // along with the scoped instances, and magic_links is environment-owned.
    $token = app(MagicLink::class)->request('magic@acme.test');

    nextRequest();

    $this->get('/magic/'.$token)->assertRedirect(route('login'));

    expect(session()->has(PlatformAuth::SESSION_KEY))->toBeFalse()
        // Redemption STARTS a framework session before the door ever sees it, so declining
        // to hand it to the browser is not enough: left alive it is a sign-in the subject
        // would find in their own session list after being told it did not happen.
        ->and(doorHasLiveSession($subjectId))->toBeFalse();

    // And the refusal arrives somewhere that can explain it — through the real route, so
    // the component mounts the way it does in production.
    nextRequest();
    $this->get(route('login'))
        ->assertSee('Acme Inc requires single sign-on')
        ->assertSee('That sign-in link worked')
        ->assertSee($startUrl);

    // Taken once: a refusal that survived would greet them again on their next visit.
    nextRequest();
    $this->get(route('login'))->assertDontSee('requires single sign-on');
})->group('security');

it('refuses a passkey sign-in, and says the passkey worked', function (): void {
    [$subjectId, $organizationId, $startUrl] = doorSubject('passkey@acme.test', 'Passkey Co', 'acme-passkey');
    doorFakePasskeys($subjectId);

    $this->postJson(route('passkeys.login.options'))->assertOk();
    $this->postJson(route('passkeys.login'), ['id' => 'cred_door'])
        ->assertOk()
        ->assertJsonPath('redirect', route('dashboard'));
    expect(session()->has(PlatformAuth::SESSION_KEY))->toBeTrue();

    forgetSubjectSession();
    doorMandate($organizationId);
    nextRequest();

    // A passkey is the strongest factor here and it is still refused: a mandate is about
    // WHICH DIRECTORY decides, not about how good the credential is.
    $this->postJson(route('passkeys.login.options'))->assertOk();
    $this->postJson(route('passkeys.login'), ['id' => 'cred_door'])
        ->assertOk()
        ->assertJsonPath('redirect', route('login'));

    expect(session()->has(PlatformAuth::SESSION_KEY))->toBeFalse();

    nextRequest();
    $this->get(route('login'))
        ->assertSee('Passkey Co requires single sign-on')
        ->assertSee('Your passkey worked')
        ->assertSee($startUrl);
})->group('security');

it('lets an invitation be accepted under a mandate, and still refuses the session', function (): void {
    [$organizationId, $startUrl] = doorOrganization('Invite Co', 'acme-invite');
    doorMandate($organizationId);

    $invitation = app(Invitations::class)->invite($organizationId, 'invitee@acme.test', MembershipRole::Member);

    $this->get(route('invitation.accept', $invitation->token))->assertRedirect(route('login'));

    expect(session()->has(PlatformAuth::SESSION_KEY))->toBeFalse();

    // The JOINING stands. The invitation was real and this person belongs here; what the
    // mandate refuses is the door, not the membership — and refusing the membership too
    // would leave them unable to enter by the door they are being sent to.
    $subject = app(Subjects::class)->findByEmail('invitee@acme.test');
    expect($subject)->not->toBeNull()
        ->and(app(Memberships::class)->forUser((string) $subject?->id))->toHaveCount(1);

    nextRequest();
    $this->get(route('login'))
        ->assertSee('Invite Co requires single sign-on')
        ->assertSee('Your invitation is accepted')
        ->assertSee($startUrl);
})->group('security');

/*
|--------------------------------------------------------------------------
| The account plane
|--------------------------------------------------------------------------
*/

it('refuses an account member\'s magic link while leaving the federated landing alone', function (): void {
    [$member] = doorMandatedAccount('magic-owner@acme.example');
    $subjectId = (string) $member->refresh()->subject_id;

    $this->get('/magic/'.app(MagicLink::class)->request('magic-owner@acme.example'))
        ->assertRedirect(route('login'));

    expect(session()->has(PlatformAuth::SESSION_KEY))->toBeFalse()
        ->and(doorHasLiveSession($subjectId))->toBeFalse();

    nextRequest();
    $this->get(route('login'))
        ->assertSee('Workspace Co requires single sign-on')
        ->assertSee('That sign-in link worked');

    // THE CONTROL, and the one that matters most: the door a mandate points AT must not
    // consult the mandate, or requiring SSO locks an organization out of the console it
    // just secured. Same subject, same landing, opposite answer.
    $assertion = app(SessionManager::class)->start($subjectId, null, ['sso']);

    expect(app(AccountAuth::class)->adoptFederated($assertion))->toBe(AttemptOutcome::Ok);
})->group('security');

/*
 * The account plane's own passkey ceremony and its own forgot/reset pair were two more
 * doors this file had to name. Both are gone: the ceremony enrolled a credential in a
 * second store that nothing enforced, and the reset wrote to the same SUBJECT the
 * console's own reset writes to. What is left is one door of each kind, covered above —
 * which is a stronger statement than two tests, because it is one implementation.
 */

it('activates an account invitation under a mandate, and still refuses the session', function (): void {
    [$owner] = doorMandatedAccount('invite-owner@acme.example');

    $members = app(AccountMembers::class);
    $invited = $members->invite((string) $owner->account_id, 'workspace-invitee@acme.example', AccountRole::Admin);

    $url = URL::signedRoute('account.invite.accept', ['member' => $invited->id]);

    livewireUpdate($url, 'auth.accept-account-invite', 'accept', updates: [
        'password' => 'a-strong-unbreached-passphrase',
    ])->assertOk();

    // Active, deliberately: an invited member is one no SSO assertion can land on, so
    // refusing the activation as well would send them to a door that cannot open.
    expect($members->find($invited->id)?->isActive())->toBeTrue()
        ->and(app(AccountAuth::class)->current())->toBeNull();

    nextRequest();
    $this->get(route('login'))
        ->assertSee('Workspace Co requires single sign-on')
        ->assertSee('Your invitation is accepted');
})->group('security');
