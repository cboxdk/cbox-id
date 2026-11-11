<?php

declare(strict_types=1);

use App\Mail\InvitationMail;
use App\Platform\NativeProduct\NativeProductMembers;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;

it('keeps an old invitation form bound to its displayed organization after another tab switches', function (): void {
    Mail::fake();
    $first = provisionAccount('first-team-owner@example.test');
    $second = provisionAccount('second-team-owner@example.test');
    $membership = app(PlatformRoot::class)->run(fn () => app(Memberships::class)->add($second['organization']->id, $first['subjectId'], MembershipRole::Member));
    grantNativeProductRoles($membership, ['admin']);
    signInAsMember($first['subjectId']);
    $firstSlug = orgSlug($first['organization']->id);
    $secondSlug = orgSlug($second['organization']->id);

    $this->get(route('orgs.settings.members', ['org' => $firstSlug, 'organization' => $first['organization']->id]))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('organization', $first['organization']->id));
    $this->post(route('orgs.switch'), ['organization' => $second['organization']->id])->assertRedirect();

    // The old tab's form names its organization in its ADDRESS, so it still invites into
    // the organization it showed, whatever another tab did to the session since. The
    // organization in the body still has to match the one in the address.
    $this->post(route('orgs.settings.members.invite', ['org' => $firstSlug]), ['organization' => $second['organization']->id, 'email' => 'mixed-tab@example.test', 'roles' => ['viewer']])->assertForbidden();
    $this->post(route('orgs.settings.members.invite', ['org' => $firstSlug]), ['email' => 'no-workspace@example.test', 'roles' => ['viewer']])->assertForbidden();
    Mail::assertNothingSent();

    $this->from(route('orgs.settings.members', ['org' => $firstSlug]))->post(route('orgs.settings.members.invite', ['org' => $firstSlug]), [
        'organization' => $first['organization']->id, 'email' => 'old-tab@example.test', 'roles' => ['viewer'],
    ])->assertSessionHasNoErrors();
    Mail::assertSent(InvitationMail::class, fn (InvitationMail $mail): bool => $mail->hasTo('old-tab@example.test'));
    expect(app(PlatformRoot::class)->run(fn () => DB::table('invitations')->where('email', 'old-tab@example.test')->value('organization_id')))
        ->toBe($first['organization']->id);

    $this->from(route('orgs.settings.members', ['org' => $secondSlug]))->post(route('orgs.settings.members.invite', ['org' => $secondSlug]), [
        'organization' => $second['organization']->id, 'email' => 'right-tab@example.test', 'roles' => ['viewer'],
    ])->assertSessionHasNoErrors();
    Mail::assertSent(InvitationMail::class, fn (InvitationMail $mail): bool => $mail->hasTo('right-tab@example.test'));
});

it('refuses member role writes without the displayed organization or with a foreign one', function (): void {
    $account = provisionAccount();
    [$member] = addMember($account['organization']->id, MembershipRole::Member, 'bound-row@example.test');
    grantNativeProductRoles($member, ['viewer']);
    signInAsMember($account['subjectId']);
    $roles = app(NativeProductMembers::class);
    $before = $roles->access($member);
    foreach ([[], ['organization' => 'foreign-workspace']] as $scope) {
        $this->patchJson(route('orgs.settings.members.role', $member->id), $scope + ['roles' => ['admin'], 'roles_version' => $before['roles_version']])->assertForbidden();
        $this->deleteJson(route('orgs.settings.members.remove', $member->id), $scope + ['roles_version' => $before['roles_version']])->assertForbidden();
    }
    expect($roles->access($member))->toBe($before);
});
