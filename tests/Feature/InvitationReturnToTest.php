<?php

declare(strict_types=1);

use App\Mail\InvitationMail;
use App\Models\InvitationContext;
use App\Models\InvitationRoleGrant;
use App\Platform\Invitations\AppReturnTargets;
use App\Platform\Invitations\Contracts\OrganizationInvitations;
use App\Platform\Invitations\Enums\InvitationRefusalReason;
use App\Platform\Invitations\Exceptions\InvitationRefused;
use App\Platform\Invitations\ValueObjects\Inviter;
use App\Platform\Invitations\ValueObjects\NewInvitation;
use App\Platform\PlatformAuth;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Models\RoleAssignment;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\InvitationStatus;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Invitation;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;

/**
 * BACK TO THE APP THAT INVITED THEM — and nowhere else.
 *
 * An invitation can name the app it is for (`client_id`) and where in that app to land the
 * person afterwards (`return_to`). The address is checked against the ORIGINS the app has
 * registered as redirect URIs when the invitation is sent, and again when it is accepted:
 * an identity provider that redirects wherever it is told is a phishing kit with a trusted
 * domain on it.
 */
beforeEach(function (): void {
    installedDeployment();
    Mail::fake();
});

/** An app of this organization's, with one registered origin. */
function returnApp(string $organizationId, array $redirectUris = ['https://tasks.acme.test/auth/callback']): Client
{
    return app(ClientRegistry::class)->register(new NewClient(
        'Acme Tasks',
        redirectUris: $redirectUris,
        organizationId: $organizationId,
    ))->client;
}

it('sends the invitee back to the app after they accept', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    $app = returnApp($org->id);

    inviteToDirectory([
        'client_id' => $app->client_id,
        'return_to' => 'https://tasks.acme.test/welcome?team=acme',
    ])->assertSessionHasNoErrors();

    $invitation = Invitation::query()->where('email', 'newbie@acme.test')->firstOrFail();

    expect(InvitationContext::query()->where('invitation_id', $invitation->id)->first())
        ->client_id->toBe($app->client_id)
        ->return_to->toBe('https://tasks.acme.test/welcome?team=acme');

    // The mail and the page both say which app this is for.
    Mail::assertSent(InvitationMail::class, fn (InvitationMail $mail): bool => $mail->app === 'Acme Tasks');

    $token = mailedInvitationToken();
    app(PlatformAuth::class)->logout(request());

    $this->get(route('invitation.accept', $token))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/join-organization')
            ->where('confirmation.facts.4', ['label' => 'App', 'value' => 'Acme Tasks']));

    // Accepted over the page's own XHR, the answer is a FULL-PAGE visit to the app — an
    // XHR cannot follow a redirect to another origin.
    $this->withHeaders(['X-Inertia' => 'true'])
        ->post(route('invitation.accept.store', $token))
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'https://tasks.acme.test/welcome?team=acme');

    // Spent with the invitation, like the roles parked beside it.
    expect(InvitationContext::query()->where('invitation_id', $invitation->id)->exists())->toBeFalse();
});

it('redirects a plain form post straight to the app', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    $app = returnApp($org->id);

    inviteToDirectory(['client_id' => $app->client_id, 'return_to' => 'https://tasks.acme.test/welcome'])
        ->assertSessionHasNoErrors();

    $token = mailedInvitationToken();

    $this->post(route('invitation.accept.store', $token))->assertRedirect('https://tasks.acme.test/welcome');
});

it('refuses a return address that is not on one of the app\'s registered origins', function (string $returnTo, InvitationRefusalReason $reason): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    $app = returnApp($org->id);

    $refused = null;

    try {
        app(OrganizationInvitations::class)->send(new NewInvitation(
            organizationId: $org->id,
            email: 'newbie@acme.test',
            role: MembershipRole::Member,
            inviter: new Inviter(null, 'Owner'),
            clientId: $app->client_id,
            returnTo: $returnTo,
        ));
    } catch (InvitationRefused $e) {
        $refused = $e;
    }

    // THE REASON, not only the class: every refusal below throws the same exception, so a
    // test that asserted the class would pass with any one of these rules deleted.
    expect($refused?->reason)->toBe($reason)
        ->and(app(Invitations::class)->pending($org->id))->toHaveCount(0);

    Mail::assertNothingSent();
})->with([
    'another host' => ['https://evil.example/welcome', InvitationRefusalReason::ReturnToNotRegistered],
    'a lookalike subdomain' => ['https://tasks.acme.test.evil.example/', InvitationRefusalReason::ReturnToNotRegistered],
    'another port' => ['https://tasks.acme.test:8443/', InvitationRefusalReason::ReturnToNotRegistered],
    'credentials in the authority' => ['https://tasks.acme.test@evil.example/', InvitationRefusalReason::ReturnToMalformed],
    'plain http off loopback' => ['http://tasks.acme.test/welcome', InvitationRefusalReason::ReturnToMalformed],
    'scheme-relative' => ['//evil.example/welcome', InvitationRefusalReason::ReturnToMalformed],
    'relative' => ['/welcome', InvitationRefusalReason::ReturnToMalformed],
    'javascript' => ['javascript:alert(1)', InvitationRefusalReason::ReturnToMalformed],
]);

it('reports a refused return address against the field on the invite form', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    $app = returnApp($org->id);

    inviteToDirectory(['client_id' => $app->client_id, 'return_to' => 'https://evil.example/'])
        ->assertSessionHasErrors(['return_to' => 'The return address must be on one of Acme Tasks\'s registered redirect URI origins.']);

    inviteToDirectory(['return_to' => 'https://tasks.acme.test/'])
        ->assertSessionHasErrors(['client_id' => 'A return address needs the app it belongs to. Send the app\'s client ID with it.']);
});

it('will not return people to another organization\'s app', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    $other = app(Organizations::class)->create(new NewOrganization('Globex', 'globex-return'));
    $theirs = returnApp($other->id, ['https://globex.test/callback']);

    inviteToDirectory(['client_id' => $theirs->client_id, 'return_to' => 'https://globex.test/welcome'])
        ->assertSessionHasErrors(['client_id' => 'No app with that client ID is available to this organization.']);

    expect(app(Invitations::class)->pending($org->id))->toHaveCount(0);
});

it('accepts a loopback address on any port the app registered, for local development', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    $app = returnApp($org->id, ['http://localhost:5173/callback']);

    inviteToDirectory(['client_id' => $app->client_id, 'return_to' => 'http://localhost:5173/welcome'])
        ->assertSessionHasNoErrors();

    inviteToDirectory(['email' => 'second@acme.test', 'client_id' => $app->client_id, 'return_to' => 'http://localhost:3000/welcome'])
        ->assertSessionHasErrors('return_to');
});

it('re-checks the address at acceptance, and lands in the console when it no longer holds', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    $app = returnApp($org->id);

    inviteToDirectory(['client_id' => $app->client_id, 'return_to' => 'https://tasks.acme.test/welcome'])
        ->assertSessionHasNoErrors();

    // The app moves house in the week the invitation is live.
    $app->forceFill(['redirect_uris' => ['https://tasks.acme.example/auth/callback']])->save();

    $this->post(route('invitation.accept.store', mailedInvitationToken()))->assertRedirect(route('dashboard'));
});

it('takes a return address from the environment console too', function (): void {
    craftedEnvAdmin();
    $org = app(Organizations::class)->create(new NewOrganization('Tenant', 'tenant-return'));
    $app = returnApp($org->id);

    inviteOrganizationMember($org->id, ['client_id' => $app->client_id, 'return_to' => 'https://tasks.acme.test/welcome'])
        ->assertSessionHasNoErrors();

    $invitation = Invitation::query()->where('organization_id', $org->id)->firstOrFail();

    expect(InvitationContext::query()->where('invitation_id', $invitation->id)->value('return_to'))
        ->toBe('https://tasks.acme.test/welcome')
        // An environment administrator is named, not left anonymous as they were.
        ->and(InvitationContext::query()->where('invitation_id', $invitation->id)->value('invited_by_name'))
        ->toBe('Owner');
});

it('resends on a fresh link, carrying the roles and the way back to the app', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    $app = returnApp($org->id);
    $role = app(Roles::class)->define($org->id, 'Editor');

    inviteToDirectory([
        'accessRoles' => [$role->id],
        'client_id' => $app->client_id,
        'return_to' => 'https://tasks.acme.test/welcome',
    ])->assertSessionHasNoErrors();

    $first = mailedInvitationToken();
    $original = Invitation::query()->where('email', 'newbie@acme.test')->firstOrFail();

    test()->from(route('directory.members'))
        ->post(route('directory.members.invitations.resend', $original->id))
        ->assertSessionHas('status', 'Invitation sent again to newbie@acme.test.');

    $second = mailedInvitationToken();

    // Held down, the button does not become a mail cannon.
    test()->from(route('directory.members'))
        ->post(route('directory.members.invitations.resend', (string) Invitation::query()->where('status', InvitationStatus::Pending->value)->value('id')))
        ->assertSessionHas('error');

    expect($second)->not->toBe($first)
        ->and(app(Invitations::class)->byToken($first)?->isPending())->toBeFalse();

    $this->post(route('invitation.accept.store', $first))->assertRedirect(route('login'));
    $this->post(route('invitation.accept.store', $second))->assertRedirect('https://tasks.acme.test/welcome');

    $joiner = app(Subjects::class)->findByEmail('newbie@acme.test');

    expect(RoleAssignment::query()->where('user_id', $joiner?->id)->where('role_id', $role->id)->exists())->toBeTrue();
});

it('lets the environment console resend too', function (): void {
    craftedEnvAdmin();
    $org = app(Organizations::class)->create(new NewOrganization('Tenant', 'tenant-resend'));

    inviteOrganizationMember($org->id)->assertSessionHasNoErrors();
    $invitation = Invitation::query()->where('organization_id', $org->id)->firstOrFail();

    test()->from(route('environment.organizations.show', $org->id))
        ->post(route('environment.organizations.invitations.resend', [$org->id, $invitation->id]))
        ->assertSessionHas('status', 'Invitation sent again to newbie@acme.example.');

    Mail::assertSent(InvitationMail::class, 2);
});

it('withdraws only its own organization\'s invitations, and their parked roles with them', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    $other = app(Organizations::class)->create(new NewOrganization('Globex', 'globex-revoke'));
    $role = app(Roles::class)->define($other->id, 'Globex editor');

    $theirs = app(Invitations::class)->invite($other->id, 'theirs@globex.test', MembershipRole::Member);
    InvitationRoleGrant::query()->create([
        'invitation_id' => $theirs->invitation->id,
        'organization_id' => $other->id,
        'email' => 'theirs@globex.test',
        'role_id' => $role->id,
    ]);

    // Named in the URL of THIS organization's page. The cleanup used to delete by
    // invitation id alone, so it reached across organizations even where the revoke did not.
    test()->from(route('directory.members'))
        ->delete(route('directory.members.invitations.revoke', $theirs->invitation->id))
        ->assertSessionHas('error', 'That invitation has already been accepted, withdrawn or has expired.');

    expect(app(Invitations::class)->byToken($theirs->token)?->isPending())->toBeTrue()
        ->and(InvitationRoleGrant::query()->where('invitation_id', $theirs->invitation->id)->exists())->toBeTrue();
});

it('refuses to invite somebody who is already a member', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    $already = app(Subjects::class)->create('already@acme.test', 'Already');
    app(Memberships::class)->add($org->id, $already->id, MembershipRole::Member);

    inviteToDirectory(['email' => 'already@acme.test'])
        ->assertSessionHasErrors(['email' => 'That person is already a member of this organization.']);
});

it('normalises origins the way a browser compares them', function (): void {
    expect(AppReturnTargets::origin('HTTPS://Tasks.Acme.TEST:443/x'))->toBe('https://tasks.acme.test')
        ->and(AppReturnTargets::origin('https://tasks.acme.test:8443/'))->toBe('https://tasks.acme.test:8443')
        ->and(AppReturnTargets::origin('http://127.0.0.1:8000/'))->toBe('http://127.0.0.1:8000')
        ->and(AppReturnTargets::origin("https://tasks.acme.test/\nSet-Cookie:x"))->toBeNull()
        ->and(AppReturnTargets::origin('https://tasks.acme.test\\@evil.example/'))->toBeNull()
        ->and(AppReturnTargets::origin('com.acme.tasks:/callback'))->toBeNull();
});

/** The token in the newest invitation mail's link. */
function mailedInvitationToken(): string
{
    $mail = Mail::sent(InvitationMail::class)->last();

    expect($mail)->not->toBeNull('no invitation was mailed');

    preg_match('#/invitations/([^/]+)/accept#', (string) $mail?->url, $matches);

    return $matches[1] ?? '';
}
