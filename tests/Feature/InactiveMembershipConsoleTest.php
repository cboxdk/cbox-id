<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Enums\MembershipStatus;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('refuses suspended and invited membership authority on the next console request', function (MembershipStatus $status): void {
    [$subjectId, $organization] = actingAsRole(MembershipRole::Admin);
    $this->get(route('directory.members'))->assertOk();

    app(Memberships::class)->of($organization->id, $subjectId)->update(['status' => $status]);

    nextRequest();
    $this->get(route('directory.members'))->assertForbidden();
    expect(app(CurrentUser::class)->organizationId())->toBeNull()
        ->and(app(CurrentUser::class)->isAdmin())->toBeFalse();
})->with([MembershipStatus::Suspended, MembershipStatus::Invited])->group('security');

it('does not retarget a revoked organization selection to another membership', function (): void {
    [$subjectId, $organization] = actingAsRole(MembershipRole::Admin);
    $other = app(Organizations::class)->create(new NewOrganization('Other', 'other-membership'));
    app(Memberships::class)->add($other->id, $subjectId, MembershipRole::Admin);
    $this->get(route('directory.members'))->assertOk();

    app(Memberships::class)->remove($organization->id, $subjectId);

    nextRequest();
    $this->get(route('directory.members'))->assertForbidden();
    expect(app(CurrentUser::class)->organizationId())->toBeNull();
    $this->post(route('directory.members.invite'), ['email' => 'unintended@example.test', 'role' => 'member'])
        ->assertForbidden();
    $this->assertDatabaseMissing('invitations', ['email' => 'unintended@example.test']);
})->group('security');

it('refuses a suspended organization membership behind an environment admin session', function (): void {
    crudSetup();
    $this->get(route('environment.home'))->assertOk();
    $membership = app(EnvironmentAdminAuth::class)->membership();
    expect($membership)->not->toBeNull();
    app(PlatformRoot::class)->run(fn () => $membership->update(['status' => MembershipStatus::Suspended]));

    nextRequest();
    $this->get(route('environment.home'))->assertRedirect();
    expect(app(EnvironmentAdminAuth::class)->check())->toBeFalse();
})->group('security');

it('resolves the membership of the administered environment instead of the first team', function (): void {
    $other = provisionAccount('other-owner@example.test');
    $admin = crudSetup();
    app(PlatformRoot::class)->run(function () use ($other, $admin): void {
        app(Memberships::class)->add($other['organization']->id, $admin['subjectId'], MembershipRole::Viewer)
            ->update(['created_at' => now()->subYears(2)]);
    });

    nextRequest();
    $this->get(route('environment.home'))->assertOk();
    expect(app(EnvironmentAdminAuth::class)->membership()?->organization_id)->toBe($admin['member']->organization_id);
});

it('lets a native Reader enter the selected environment but refuses writes and removed row access', function (): void {
    $owner = crudSetup();
    [$reader, $subject] = addMember($owner['member']->organization_id, MembershipRole::Admin, 'row-reader@example.test');
    grantNativeProductRoles($reader, ['viewer']);
    app(PlatformRoot::class)->run(fn () => app(Memberships::class)->setEnvironmentAccess($reader->organization_id, $subject, false, [$owner['envId']]));
    actAsEnvironmentAdmin($subject, $owner['envId']);

    nextRequest();
    $this->get(route('environment.home'))->assertOk();
    expect(app(EnvironmentAdminAuth::class)->canRead())->toBeTrue()
        ->and(app(EnvironmentAdminAuth::class)->check())->toBeFalse();
    $this->post('/admin/organizations', ['name' => 'Forbidden organization'])->assertForbidden();

    app(PlatformRoot::class)->run(fn () => app(Memberships::class)->setEnvironmentAccess($reader->organization_id, $subject, false, []));
    nextRequest();
    $this->get(route('environment.home'))->assertRedirect();
    expect(app(EnvironmentAdminAuth::class)->canRead())->toBeFalse();
})->group('security');
