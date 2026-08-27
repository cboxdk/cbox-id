<?php

declare(strict_types=1);

use Cbox\Id\Federation\Testing\InteractsWithFederation;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Testing\FakeAuditLog;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Authorization\Contracts\EntitlementWriter;
use Cbox\Id\Kernel\Authorization\Enums\EntitlementSource;
use Cbox\Id\Kernel\Authorization\ValueObjects\EntitlementInput;
use Cbox\Id\Kernel\Tenancy\Testing\InteractsWithTenancy;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Models\PlatformOperator;

uses(InteractsWithFederation::class, InteractsWithTenancy::class);

/** Sign in a fresh operator, whose reads are pinned to the default test plane. */
function detailOperatorSignIn(string $email = 'detail-op@platform.test'): PlatformOperator
{
    return actAsOperator($email);
}

it('shows a tenant\'s name, members, verified domain and entitlement in the current plane', function (): void {
    detailOperatorSignIn();

    $org = app(Organizations::class)->create(new NewOrganization('Acme Inc', 'acme-inc'));
    $member = app(Subjects::class)->create('member@acme.test', 'Member One', 'supersecret123');
    app(Memberships::class)->add($org->id, $member->id, MembershipRole::Owner);
    $this->makeVerifiedDomain($org->id, 'acme.test');
    app(EntitlementWriter::class)->set($org->id, new EntitlementInput('sso', ['enabled' => true]), EntitlementSource::Manual);

    $props = platformOrganization($org->id);

    // Asked of the PROPS rather than of the document: `assertSee('sso')` matched the word
    // wherever it appeared on a page that also carries an "SSO connection" panel and a
    // nav entry, so it could pass with the entitlement list empty.
    expect($props['organization']['name'])->toBe('Acme Inc')
        ->and(collect($props['members'])->pluck('email'))->toContain('member@acme.test')
        ->and(collect($props['members'])->pluck('role'))->toContain('owner')
        ->and(collect($props['domains'])->pluck('domain'))->toContain('acme.test')
        ->and(collect($props['entitlements'])->pluck('key'))->toContain('sso');
});

it('refuses a non-operator request with a 404', function (): void {
    detailOperatorSignIn();

    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme'));

    // The authority is re-asked of the LIVE session on every request, so dropping it mid
    // session is enough — no sign-in of another persona required.
    forgetSubjectSession();
    nextRequest();

    // Every door on the page, not only the read: each write is its own route now, and a
    // guard on the read alone would leave them open.
    $this->get(route('platform.organization', $org->id))->assertRedirect(route('login'));
    toggleTenantOrganization($org->id)->assertRedirect(route('login'));
    reparentOrganization($org->id, null)->assertRedirect(route('login'));
    createTenantOrganization()->assertRedirect(route('login'));

    expect(Organization::query()->find($org->id)->status)->toBe(OrganizationStatus::Active)
        ->and(Organization::query()->where('slug', 'acme-inc')->exists())->toBeFalse();
})->group('security');

it('returns 404 for an org that lives in another environment', function (): void {
    detailOperatorSignIn();

    // Create the org entirely inside a DIFFERENT plane. From the operator's current
    // (default) plane the scoped lookup returns null → deny-by-default 404, so the
    // page never leaks a tenant from another environment.
    $foreignId = $this->runAsEnvironment('other-env', fn (): string => app(Organizations::class)
        ->create(new NewOrganization('Foreign', 'foreign'))->id);

    $this->get(route('platform.organization', $foreignId))->assertNotFound();

    // AND THE WRITES. The scoped read is what makes the page refuse; the toggle and the
    // reparent take the same id from the URL and must ask the same question — a reparent
    // that did not would splice one plane's tenant into another's tree.
    toggleTenantOrganization($foreignId)->assertNotFound();
    reparentOrganization($foreignId, null)->assertNotFound();
});

it('suspends and reactivates the tenant from the detail page, recording audit', function (): void {
    $audit = new FakeAuditLog;
    app()->instance(AuditLog::class, $audit);
    $op = detailOperatorSignIn('audit-op@platform.test');

    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-audit'));

    toggleTenantOrganization($org->id)->assertSessionHasNoErrors();
    expect(Organization::query()->find($org->id)->status)->toBe(OrganizationStatus::Suspended);
    $audit->assertRecorded('organization.suspended', fn (AuditEvent $e): bool => $e->actorId === $op->id && $e->targetId === $org->id);

    // …and the page says so, or the toggle is a database write nothing reflects.
    expect(platformOrganization($org->id)['organization']['active'])->toBeFalse();

    toggleTenantOrganization($org->id);
    expect(Organization::query()->find($org->id)->status)->toBe(OrganizationStatus::Active);
    $audit->assertRecorded('organization.reactivated');
});

/**
 * A TENANT FROM ANOTHER PLANE MUST NOT BECOME SOMEBODY'S PARENT.
 *
 * `OrganizationHierarchy::move()` takes two ids and does not itself ask which environment
 * they belong to — organizations are environment-owned and the scope lives on the model's
 * queries, not on the closure table. So the parent id has to be resolved through the
 * scoped reader before anything moves, or a request from this page could splice one
 * plane's tenant under another's, in a tree neither console can then render.
 */
it('refuses to reparent a tenant under one from another environment', function (): void {
    detailOperatorSignIn();

    $mine = app(Organizations::class)->create(new NewOrganization('Mine', 'mine'));

    $foreignId = $this->runAsEnvironment('other-env', fn (): string => app(Organizations::class)
        ->create(new NewOrganization('Foreign', 'foreign-parent'))->id);

    reparentOrganization($mine->id, $foreignId)->assertNotFound();

    expect(Organization::query()->find($mine->id)->parent_id)->toBeNull();
})->group('security');

it('refuses a type the enum does not have', function (): void {
    detailOperatorSignIn();

    // The value reaches `OrganizationType::from()`, which throws on anything unknown — so
    // an unvalidated field here is a 500 on a form, not a bad row.
    createTenantOrganization(['type' => 'partner'])->assertSessionHasErrors('type');

    expect(Organization::query()->where('name', 'Acme Inc')->exists())->toBeFalse();
});
