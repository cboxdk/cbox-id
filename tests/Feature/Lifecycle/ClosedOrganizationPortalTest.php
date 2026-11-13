<?php

declare(strict_types=1);

use App\Platform\AdminPortal;
use App\Platform\Enums\PortalIntent;
use App\Platform\Enums\PortalScope;
use Cbox\Id\Federation\Models\VerifiedDomain;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;

/**
 * AN ADMIN PORTAL LINK OR SESSION OPENS NOTHING FOR A CLOSED ORGANIZATION.
 *
 * A setup link minted before the organization was suspended or archived still redeemed,
 * and an open setup session kept writing: domain claims, SSO connections, fresh SCIM
 * tokens that outlive the window. Closed, the organization's portal is closed with it;
 * reactivated, a session still inside its window works again.
 */
function cpOrg(): string
{
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-'.uniqid()))->id;
    grantFeature($org, 'cbox-id-sso');

    return $org;
}

function cpClose(string $org, string $how): void
{
    match ($how) {
        'suspended' => app(Organizations::class)->suspend($org, 'sub_operator'),
        'archived' => app(Organizations::class)->archive($org, 'sub_operator'),
    };
}

it('redeems no link for a closed organization (#41)', function (string $how): void {
    $org = cpOrg();
    $token = app(AdminPortal::class)->generate($org, PortalScope::only(PortalIntent::Sso), 'sub_creator');

    cpClose($org, $how);

    expect(app(AdminPortal::class)->redeem($token))->toBeNull();
})->with(['suspended', 'archived']);

it('ends an open setup session when its organization is closed, and writes nothing through it (#41)', function (string $how): void {
    $org = cpOrg();
    $token = app(AdminPortal::class)->generate($org, PortalScope::only(PortalIntent::Sso), 'sub_creator');
    expect(app(AdminPortal::class)->redeem($token))->not->toBeNull()
        ->and(app(AdminPortal::class)->sessionValid())->toBeTrue();

    cpClose($org, $how);

    expect(app(AdminPortal::class)->sessionValid())->toBeFalse();

    addPortalDomain('acme.com');

    expect(VerifiedDomain::query()->where('organization_id', $org)->exists())->toBeFalse();
})->with(['suspended', 'archived']);

it('reopens a session still inside its window once the organization is reactivated (#41)', function (): void {
    $org = cpOrg();
    $token = app(AdminPortal::class)->generate($org, PortalScope::only(PortalIntent::Sso), 'sub_creator');
    app(AdminPortal::class)->redeem($token);

    cpClose($org, 'suspended');
    app(Organizations::class)->reactivate($org, 'sub_operator');

    addPortalDomain('acme.com')->assertSessionHasNoErrors();

    expect(VerifiedDomain::query()->where('organization_id', $org)->exists())->toBeTrue();
});
