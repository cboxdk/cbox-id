<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\IntendedUrl;
use App\Platform\Invitations\Contracts\OrganizationInvitations;
use App\Platform\Invitations\ValueObjects\Inviter;
use App\Platform\Invitations\ValueObjects\NewInvitation;
use App\Platform\Organizations\OrganizationRequired;
use App\Platform\Organizations\PersonalOrganizations;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\ValueObjects\Subject;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;

/**
 * The Cbox realm's "no organization" screen: a signed-in person with no organization is
 * never sent on to an app or the console without one.
 */
function noOrgRealm(bool $switch = true): Environment
{
    config([
        'cbox-id.environments.base_domains' => ['cboxid.com'],
        'cbox-id.tenancy.multi_tenant' => true,
        'cbox-id.signup.personal_organization' => $switch,
    ]);

    $root = Environment::query()->create(['name' => 'Production', 'slug' => 'production', 'status' => 'active', 'is_default' => true]);
    app(EnvironmentContext::class)->set($root);

    return $root;
}

function noOrgPerson(string $email = 'pat@acme.test', bool $verified = true): Subject
{
    $subject = app(Subjects::class)->create($email, 'Pat Doe', 'supersecret123');

    if ($verified) {
        app(Subjects::class)->markEmailVerified($subject->id, $email);
    }

    signInAsSubject($subject->id);

    return $subject;
}

function noOrgClient(): string
{
    return app(ClientRegistry::class)->register(new NewClient(
        'Tax App',
        ClientType::Public,
        redirectUris: ['https://app.test/cb'],
        grantTypes: ['authorization_code', 'refresh_token'],
        scopes: ['openid', 'email'],
        firstParty: true,
    ))->client->client_id;
}

/** Acme, owned by somebody else, with a live invitation for `$email`. Returns the invitation id. */
function noOrgInvitation(string $email): array
{
    Mail::fake();

    $owner = app(Subjects::class)->create('owner@acme.test', 'Olga Owner', 'supersecret123');
    $acme = app(Organizations::class)->create(new NewOrganization('Acme', 'acme'));
    app(Memberships::class)->add($acme->id, $owner->id, MembershipRole::Owner);

    $sent = app(OrganizationInvitations::class)->send(new NewInvitation(
        organizationId: $acme->id,
        email: $email,
        role: MembershipRole::Member,
        inviter: new Inviter($owner->id, 'Olga Owner'),
    ));

    return ['organization' => $acme, 'invitationId' => $sent->invitation->id];
}

it('leaves a person with no organization alone while the switch is off, except at the realm\'s own apps', function (): void {
    noOrgRealm(switch: false);
    noOrgPerson();

    // The screen is off for somebody nothing sent to it...
    $this->get(route('organization.required'))->assertRedirect(route('dashboard'));

    // ...but a first-party app is never handed a person without an organization, whatever
    // the switch says (tests/Feature/HostedSignIn/ResumeAuthorizationTest.php walks it through).
    authorizeRequest(['client_id' => noOrgClient(), 'redirect_uri' => 'https://app.test/cb'])
        ->assertRedirect(route('organization.required'));
});

it('interrupts an authorization for the screen, and resumes it in a personal organization', function (): void {
    noOrgRealm();
    $person = noOrgPerson();
    $clientId = noOrgClient();

    authorizeRequest(['client_id' => $clientId, 'redirect_uri' => 'https://app.test/cb'])
        ->assertRedirect(route('organization.required'));

    $resume = session(IntendedUrl::KEY);
    expect($resume)->toContain('/oauth/authorize');

    $this->get(route('organization.required'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('auth/no-organization')
        ->where('account', 'pat@acme.test')
        ->where('invitations', [])
        ->where('needsConfirmation', false)
        ->where('personalHref', route('organization.required.personal')));

    $this->post(route('organization.required.personal'))->assertRedirect($resume);

    $organization = Organization::query()->sole();

    expect($organization->name)->toBe('Pat Doe')
        ->and(PersonalOrganizations::isPersonal($organization))->toBeTrue()
        ->and(app(Memberships::class)->activeRole($organization->id, $person->id))->toBe(MembershipRole::Owner)
        ->and(session(PlatformAuth::ORG_KEY))->toBe($organization->id)
        ->and(leftFor($this->get($resume)))->toStartWith('https://app.test/cb?code=');
});

it('answers a silent authorization with interaction_required rather than a screen', function (): void {
    noOrgRealm();
    noOrgPerson();

    $location = leftFor(authorizeRequest(['client_id' => noOrgClient(), 'redirect_uri' => 'https://app.test/cb', 'prompt' => 'none']));

    expect($location)->toStartWith('https://app.test/cb?')->toContain('error=interaction_required');
});

it('lists the invitations for a confirmed address and accepts one without the emailed link', function (): void {
    noOrgRealm();
    $invited = noOrgInvitation('pat@acme.test');
    $person = noOrgPerson();

    $this->get(route('organization.required'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('invitations', 1)
        ->where('invitations.0.organization', 'Acme')
        ->where('invitations.0.inviter', 'Olga Owner')
        ->where('invitations.0.acceptHref', route('organization.required.accept', $invited['invitationId'])));

    $this->post(route('organization.required.accept', $invited['invitationId']))->assertRedirect(route('dashboard'));

    expect(app(Memberships::class)->activeRole($invited['organization']->id, $person->id))->toBe(MembershipRole::Member)
        ->and(session(PlatformAuth::ORG_KEY))->toBe($invited['organization']->id)
        ->and(Organization::query()->count())->toBe(1)
        ->and(app(OrganizationInvitations::class)->openFor('pat@acme.test'))->toBe([])
        ->and(app(OrganizationRequired::class)->appliesTo(app(CurrentUser::class)))->toBeFalse();
});

it('shows no invitations to an unconfirmed address, and accepts none for it', function (): void {
    noOrgRealm();
    $invited = noOrgInvitation('pat@acme.test');
    $person = noOrgPerson(verified: false);

    $this->get(route('organization.required'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('invitations', [])
        ->where('needsConfirmation', true));

    $this->from(route('organization.required'))
        ->post(route('organization.required.accept', $invited['invitationId']))
        ->assertRedirect(route('organization.required'))
        ->assertSessionHasErrors('invitation');

    expect(app(Memberships::class)->of($invited['organization']->id, $person->id))->toBeNull();
});

it('refuses an invitation sent to somebody else', function (): void {
    noOrgRealm();
    $invited = noOrgInvitation('somebody.else@acme.test');
    $person = noOrgPerson();

    $this->from(route('organization.required'))
        ->post(route('organization.required.accept', $invited['invitationId']))
        ->assertSessionHasErrors('invitation');

    expect(app(Memberships::class)->of($invited['organization']->id, $person->id))->toBeNull();
});

it('has nothing to ask somebody who is in an organization', function (): void {
    noOrgRealm();
    $subject = app(Subjects::class)->create('pat@acme.test', 'Pat Doe', 'supersecret123');
    $acme = app(Organizations::class)->create(new NewOrganization('Acme', 'acme'));
    app(Memberships::class)->add($acme->id, $subject->id, MembershipRole::Member);
    signInAsSubject($subject->id);

    $this->get(route('organization.required'))->assertRedirect(route('dashboard'));
    $this->post(route('organization.required.personal'))->assertRedirect(route('dashboard'));

    expect(Organization::query()->count())->toBe(1)
        ->and(leftFor(authorizeRequest(['client_id' => noOrgClient(), 'redirect_uri' => 'https://app.test/cb'])))
        ->toStartWith('https://app.test/cb?code=');
});

it('sends the console landing of a person with no organization to the screen', function (): void {
    noOrgRealm();
    // The console answers on the realm's own host only; make that the host the suite uses.
    config(['cbox-id.tenancy.account_host' => (string) parse_url((string) config('app.url'), PHP_URL_HOST)]);
    noOrgPerson();

    $this->get(route('dashboard'))->assertRedirect(route('organization.required'));
});
