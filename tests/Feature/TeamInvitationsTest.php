<?php

declare(strict_types=1);

use App\Mail\InvitationMail;
use App\Mail\OrganizationInviteMail;
use App\Platform\Invitations\Contracts\TeamInvitations;
use App\Platform\Invitations\Enums\InvitationRefusalReason;
use App\Platform\Invitations\Exceptions\InvitationRefused;
use App\Platform\Invitations\ValueObjects\Inviter;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Enums\InvitationStatus;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Invitation;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/*
|--------------------------------------------------------------------------
| Inviting onto a workspace's team — the same from the console and the workspace API
|--------------------------------------------------------------------------
|
| Two doors send this invitation: Workspace › Team in the console, and
| `POST /api/v1/organization/members` with a workspace key. Both go through
| TeamInvitations, so what the invitee receives, what the activity log says, and how a
| failure is answered are the same whichever door was used. Only the name signing the
| invitation (a person, or the key) and the actor on the log differ.
*/

beforeEach(function (): void {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    Mail::fake();
});

/** @return array{organization: Organization, ownerId: string, key: string, keyId: string} */
function teamWorkspace(): array
{
    ['organization' => $organization, 'subjectId' => $ownerId] = provisionAccount('owner@acme.example');
    $issued = app(OrganizationApiKeys::class)->issue($organization->id, 'Deploy bot', MembershipRole::Admin);

    return ['organization' => $organization, 'ownerId' => $ownerId, 'key' => $issued->plaintext, 'keyId' => $issued->key->id];
}

/** @return list<Invitation> */
function teamPending(string $organizationId): array
{
    return array_values((app(PlatformRoot::class)->run(
        fn () => app(Invitations::class)->pending($organizationId),
    ) ?? collect())->all());
}

function teamActivity(string $organizationId, string $action): ?AuditEntry
{
    return app(PlatformRoot::class)->run(fn () => AuditEntry::query()
        ->where('scope', $organizationId)
        ->where('action', $action)
        ->orderByDesc('sequence')
        ->first());
}

it('sends the same invitation from the console and from the workspace API', function (): void {
    ['organization' => $organization, 'ownerId' => $ownerId, 'key' => $key, 'keyId' => $keyId] = teamWorkspace();

    signInAsMember($ownerId);
    test()->from(route('members'))
        ->post(route('members.invite'), ['email' => 'console@acme.example', 'role' => 'developer'])
        ->assertSessionHasNoErrors();

    test()->withToken($key)->postJson('/api/v1/organization/members', ['email' => 'api@acme.example', 'role' => 'developer'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'invited');

    // The same mail — the TEAM's, never an organization member's — with the role in it,
    // signed by whoever sent it, and the same signed accept link.
    $mails = [];
    Mail::assertSent(OrganizationInviteMail::class, function (OrganizationInviteMail $mail) use (&$mails): bool {
        $mails[$mail->to[0]['address']] = $mail;

        return true;
    });
    Mail::assertNotSent(InvitationMail::class);

    expect($mails)->toHaveKeys(['console@acme.example', 'api@acme.example'])
        ->and($mails['console@acme.example']->role)->toBe('Developer')
        ->and($mails['api@acme.example']->role)->toBe('Developer')
        ->and($mails['console@acme.example']->inviter)->toBe('Owner')
        ->and($mails['api@acme.example']->inviter)->toBe('Deploy bot')
        ->and($mails['api@acme.example']->url)->toContain('/invite/')
        ->and($mails['api@acme.example']->url)->toContain('signature=');

    // The same rows, and the same line on the activity log — the actor is the difference.
    $rows = collect(teamPending($organization->id))->keyBy('email');

    expect($rows->keys()->sort()->values()->all())->toBe(['api@acme.example', 'console@acme.example'])
        ->and($rows['api@acme.example']->role)->toBe(MembershipRole::Developer)
        ->and($rows['console@acme.example']->role)->toBe(MembershipRole::Developer);

    $entries = app(PlatformRoot::class)->run(fn () => AuditEntry::query()
        ->where('scope', $organization->id)
        ->where('action', 'organization.member_invited')
        ->orderBy('sequence')
        ->get());

    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('context.role')->all())->toBe(['developer', 'developer'])
        ->and($entries[0]->actor_type)->toBe(ActorType::OrganizationMember)
        ->and($entries[0]->actor_id)->toBe($ownerId)
        ->and($entries[1]->actor_type)->toBe(ActorType::Service)
        ->and($entries[1]->actor_id)->toBe($keyId);
});

it('names the key that sent it on the page the invitee lands on', function (): void {
    ['organization' => $organization, 'key' => $key] = teamWorkspace();

    test()->withToken($key)->postJson('/api/v1/organization/members', ['email' => 'api@acme.example', 'role' => 'viewer'])
        ->assertCreated();

    $url = null;
    Mail::assertSent(OrganizationInviteMail::class, function (OrganizationInviteMail $mail) use (&$url): bool {
        $url = $mail->url;

        return true;
    });

    // The accept link is the team's: set a password, and you are signed in to the console.
    test()->get((string) $url)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/accept-invite')
            ->where('inviterName', 'Deploy bot')
            ->where('roleLabel', 'Viewer')
            ->where('organizationName', $organization->name));
});

it('lists, re-sends and withdraws a team invitation from the workspace API', function (): void {
    ['organization' => $organization, 'key' => $key, 'keyId' => $keyId] = teamWorkspace();

    $id = test()->withToken($key)->postJson('/api/v1/organization/members', ['email' => 'api@acme.example', 'role' => 'member'])
        ->assertCreated()->json('data.id');

    test()->withToken($key)->getJson('/api/v1/organization/invitations')
        ->assertOk()
        ->assertJsonPath('data.0.email', 'api@acme.example')
        ->assertJsonPath('data.0.role', 'member')
        ->assertJsonPath('data.0.invited_by', 'Deploy bot');

    // A fresh link; the old one stops working.
    $fresh = test()->withToken($key)->postJson("/api/v1/organization/invitations/{$id}/resend")
        ->assertOk()->json('data.id');

    expect($fresh)->not->toBe($id)
        ->and(teamPending($organization->id))->toHaveCount(1)
        ->and(teamActivity($organization->id, 'organization.member_invited')?->context['resent'] ?? null)->toBeTrue();

    // Once a minute, as on the console.
    test()->withToken($key)->postJson("/api/v1/organization/invitations/{$fresh}/resend")
        ->assertStatus(429)->assertJsonPath('error', 'too_soon');

    test()->withToken($key)->deleteJson("/api/v1/organization/invitations/{$fresh}")->assertNoContent();

    expect(teamPending($organization->id))->toBe([])
        ->and(teamActivity($organization->id, 'organization.invitation_revoked')?->actor_id)->toBe($keyId);

    // Gone is gone: the same id again, and the superseded one, are nothing to withdraw.
    test()->withToken($key)->deleteJson("/api/v1/organization/invitations/{$fresh}")
        ->assertNotFound()->assertJsonPath('error', 'not_found');
    test()->withToken($key)->deleteJson("/api/v1/organization/invitations/{$id}")->assertNotFound();
});

it('never reaches another workspace\'s invitation from the API', function (): void {
    ['key' => $key] = teamWorkspace();

    $other = provisionAccount('owner@other.example');
    $theirs = app(TeamInvitations::class)->send(
        $other['organization']->id,
        'theirs@other.example',
        MembershipRole::Member,
        new Inviter($other['subjectId'], 'Other owner'),
        AuditActor::organizationMember($other['subjectId']),
    );

    test()->withToken($key)->deleteJson("/api/v1/organization/invitations/{$theirs->id}")->assertNotFound();
    test()->withToken($key)->postJson("/api/v1/organization/invitations/{$theirs->id}/resend")->assertNotFound();
    test()->withToken($key)->getJson('/api/v1/organization/invitations')->assertOk()->assertJsonCount(0, 'data');

    expect(teamPending($other['organization']->id))->toHaveCount(1)
        ->and(teamPending($other['organization']->id)[0]->status)->toBe(InvitationStatus::Pending);
});

it('answers a failed mail the same from both doors: nothing is left behind', function (): void {
    ['organization' => $organization, 'ownerId' => $ownerId, 'key' => $key] = teamWorkspace();

    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP is down'));

    test()->withToken($key)->postJson('/api/v1/organization/members', ['email' => 'api@acme.example', 'role' => 'viewer'])
        ->assertStatus(503)
        ->assertJsonPath('error', 'mail_failed')
        ->assertJsonPath('message', InvitationRefused::mailFailed(invitationKept: false)->getMessage());

    signInAsMember($ownerId);
    test()->from(route('members'))
        ->post(route('members.invite'), ['email' => 'console@acme.example', 'role' => 'viewer'])
        ->assertSessionHasErrors(['email' => InvitationRefused::mailFailed(invitationKept: false)->getMessage()]);

    expect(teamPending($organization->id))->toBe([]);
});

it('refuses somebody already on the team with the same sentence from both doors', function (): void {
    ['ownerId' => $ownerId, 'key' => $key] = teamWorkspace();

    test()->withToken($key)->postJson('/api/v1/organization/members', ['email' => 'owner@acme.example', 'role' => 'viewer'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'email_taken')
        ->assertJsonPath('message', 'That person is already on this list.');

    signInAsMember($ownerId);
    test()->from(route('members'))
        ->post(route('members.invite'), ['email' => 'owner@acme.example', 'role' => 'viewer'])
        ->assertSessionHasErrors(['email' => 'That person is already on this list.']);

    Mail::assertNothingSent();
});

it('never invites an owner, whoever asks', function (): void {
    ['organization' => $organization, 'ownerId' => $ownerId, 'key' => $key] = teamWorkspace();

    // The doors validate the role first…
    test()->withToken($key)->postJson('/api/v1/organization/members', ['email' => 'x@acme.example', 'role' => 'owner'])
        ->assertStatus(422);

    // …and the service refuses it on its own, for any caller that skips them.
    expect(fn () => app(TeamInvitations::class)->send(
        $organization->id,
        'x@acme.example',
        MembershipRole::Owner,
        new Inviter($ownerId, 'Owner'),
        AuditActor::organizationMember($ownerId),
    ))->toThrow(function (InvitationRefused $refused): void {
        expect($refused->reason)->toBe(InvitationRefusalReason::RoleNotOffered)
            ->and($refused->getMessage())->toBe('Choose one of: Admin, Developer, Member, Viewer. Ownership is handed over with "Transfer ownership", never by invitation.');
    });

    expect(teamPending($organization->id))->toBe([]);
});

it('keeps an organization\'s invitation and a team invitation apart', function (): void {
    ['organization' => $organization, 'ownerId' => $ownerId] = teamWorkspace();

    $sent = app(TeamInvitations::class)->send(
        $organization->id,
        'team@acme.example',
        MembershipRole::Admin,
        new Inviter($ownerId, 'Owner'),
        AuditActor::organizationMember($ownerId),
    );

    // A team invitation is accepted on the team's own door (set a password, into the
    // console) — the tenant organization's accept page does not know it.
    $url = null;
    Mail::assertSent(OrganizationInviteMail::class, function (OrganizationInviteMail $mail) use (&$url): bool {
        $url = $mail->url;

        return true;
    });

    expect((string) $url)->toStartWith(URL::to('/invite/'))
        ->and(Str::contains((string) $url, '/invitations/'))->toBeFalse()
        ->and($sent->organization_id)->toBe($organization->id);
});
