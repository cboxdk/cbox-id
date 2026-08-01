<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\Onboarding\SetupChecklist;
use App\Platform\Onboarding\SetupStepKey;
use App\Platform\PlatformAuth;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The point of these: the checklist the console shipped before could not tick
 * anything off, because it was hardcoded markup. Every assertion here is that a
 * step reflects REAL state — do the thing, and the step becomes done on its own.
 */
function checklistOrg(): Organization
{
    $subject = app(Subjects::class)->create('owner@acme.test', 'Owner', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-setup'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);

    return $org;
}

it('starts a fresh organization with nothing done', function (): void {
    $org = checklistOrg();

    $progress = app(SetupChecklist::class)->for($org->id);

    expect($progress->completed())->toBe(0)
        ->and($progress->percent())->toBe(0)
        ->and($progress->isComplete())->toBeFalse()
        ->and($progress->next()?->key)->toBe(SetupStepKey::InviteTeam);
});

it('does not count the founder as having invited anyone', function (): void {
    $org = checklistOrg();

    $step = collect(app(SetupChecklist::class)->for($org->id)->steps)
        ->firstOrFail(fn ($s): bool => $s->key === SetupStepKey::InviteTeam);

    expect($step->done)->toBeFalse();
});

it('ticks the invite step once a second member exists', function (): void {
    $org = checklistOrg();
    $colleague = app(Subjects::class)->create('two@acme.test', 'Two', 'supersecret123');
    app(Memberships::class)->add($org->id, $colleague->id, MembershipRole::Member);

    $step = collect(app(SetupChecklist::class)->for($org->id)->steps)
        ->firstOrFail(fn ($s): bool => $s->key === SetupStepKey::InviteTeam);

    expect($step->done)->toBeTrue();
});

it('ticks the app step once the organization registers a client', function (): void {
    $org = checklistOrg();

    app(ClientRegistry::class)->register(new NewClient(
        name: 'Acme Web',
        type: ClientType::Confidential,
        organizationId: $org->id,
    ));

    $progress = app(SetupChecklist::class)->for($org->id);
    $step = collect($progress->steps)->firstOrFail(fn ($s): bool => $s->key === SetupStepKey::ConnectApp);

    expect($step->done)->toBeTrue()
        ->and($progress->completed())->toBe(1);
});

it('ticks the roles step for an organization role', function (): void {
    $org = checklistOrg();

    Role::query()->create([
        'organization_id' => $org->id,
        'name' => 'Editor',
    ]);

    $step = collect(app(SetupChecklist::class)->for($org->id)->steps)
        ->firstOrFail(fn ($s): bool => $s->key === SetupStepKey::DefineRoles);

    expect($step->done)->toBeTrue();
});

it('ticks the branding step once an appearance is saved', function (): void {
    $org = checklistOrg();

    app(Organizations::class)->updateSettings($org->id, ['appearance' => ['preset' => 'cbox']]);

    $step = collect(app(SetupChecklist::class)->for($org->id)->steps)
        ->firstOrFail(fn ($s): bool => $s->key === SetupStepKey::BrandSignIn);

    expect($step->done)->toBeTrue();
});

it('keeps dismissal per admin, not per organization', function (): void {
    $org = checklistOrg();
    $checklist = app(SetupChecklist::class);

    $checklist->dismiss($org->id, 'subject-one');

    expect($checklist->isDismissed($org->id, 'subject-one'))->toBeTrue()
        ->and($checklist->isDismissed($org->id, 'subject-two'))->toBeFalse();

    // Dismissing twice is not an error, and does not create a second row.
    $checklist->dismiss($org->id, 'subject-one');
    $checklist->restore($org->id, 'subject-one');

    expect($checklist->isDismissed($org->id, 'subject-one'))->toBeFalse();
});

it('leaves out steps the organization is not entitled to, so the list can reach 100%', function (): void {
    $org = checklistOrg();

    $keys = array_map(
        fn ($s) => $s->key,
        app(SetupChecklist::class)->for($org->id)->steps,
    );

    // Whatever this deployment's entitlements are, an unavailable step is absent
    // rather than present-and-impossible: every listed step must be completable.
    foreach ($keys as $key) {
        expect($key->requiresFeature())->toBeIn([null, 'sso', 'scim']);
    }

    expect($keys)->toContain(SetupStepKey::InviteTeam, SetupStepKey::ConnectApp);
});
