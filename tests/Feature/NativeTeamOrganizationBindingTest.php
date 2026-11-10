<?php

declare(strict_types=1);

use App\Mail\InvitationMail;
use App\Platform\NativeProduct\NativeProductMembers;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;

it('keeps an old invitation form bound to its displayed organization after another tab switches', function (): void {
    Mail::fake();
    $first = provisionAccount('first-team-owner@example.test');
    $second = provisionAccount('second-team-owner@example.test');
    $membership = app(PlatformRoot::class)->run(fn () => app(Memberships::class)->add($second['organization']->id, $first['subjectId'], MembershipRole::Member));
    grantNativeProductRoles($membership, ['admin']);
    signInAsMember($first['subjectId']);
    $this->get(route('members', ['organization' => $first['organization']->id]))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('organization', $first['organization']->id));
    $this->post(route('organization.switch'), ['organization' => $second['organization']->id])->assertRedirect();

    $this->post(route('members.invite'), ['organization' => $first['organization']->id, 'email' => 'old-tab@example.test', 'roles' => ['viewer']])->assertForbidden();
    $this->post(route('members.invite'), ['email' => 'no-workspace@example.test', 'roles' => ['viewer']])->assertForbidden();
    $this->get(route('members', ['organization' => $first['organization']->id]))->assertForbidden();
    Mail::assertNothingSent();

    $this->from(route('members', ['organization' => $second['organization']->id]))->post(route('members.invite'), [
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
        $this->patchJson(route('members.role', $member->id), $scope + ['roles' => ['admin'], 'roles_version' => $before['roles_version']])->assertForbidden();
        $this->deleteJson(route('members.remove', $member->id), $scope + ['roles_version' => $before['roles_version']])->assertForbidden();
    }
    expect($roles->access($member))->toBe($before);
});
