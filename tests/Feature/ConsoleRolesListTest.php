<?php

declare(strict_types=1);

use Cbox\Id\AccessControl\Contracts\AppManifests;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Manifest\DeclaredPermission;
use Cbox\Id\AccessControl\Manifest\DeclaredRole;
use Cbox\Id\AccessControl\Manifest\Manifest;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Enums\MembershipRole;
use Inertia\Testing\AssertableInertia;

/*
| Console › Roles: the three kinds told apart — built-in, app and custom, admin & support —
| and where each is given to a person.
*/

it('reads the built-in roles\' abilities from the roles themselves', function (): void {
    actingAsRole(MembershipRole::Owner);

    $this->get(route('roles'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('builtIn.roles', count(MembershipRole::cases()))
            ->where('builtIn.abilities.0.holders', array_values(array_map(
                static fn (MembershipRole $role): string => $role->value,
                array_filter(MembershipRole::cases(), static fn (MembershipRole $role): bool => $role->canManageOrganization()),
            )))
            // An organization's console has no admin & support roles to point at.
            ->where('where.staffHref', null)
            ->whereType('where.membersHref', 'string'));
});

it('lists custom roles before each app\'s declared roles, with counts, and narrows by source', function (): void {
    [, $organization] = actingAsRole(MembershipRole::Owner);

    $client = app(ClientRegistry::class)->register(new NewClient(
        name: 'Ledger',
        grantTypes: ['client_credentials'],
        organizationId: $organization->id,
    ), AuditActor::system())->client;

    app(AppManifests::class)->sync($client->client_id, new Manifest(
        version: '1',
        permissions: [new DeclaredPermission('invoices:read', 'See invoices')],
        roles: [new DeclaredRole('auditor', 'Auditor', 'Reads everything.', ['invoices:read'])],
    ));

    app(Roles::class)->define($organization->id, 'Zebra team');

    $this->get(route('roles'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('roles.0.name', 'Zebra team')
            ->where('roles.0.declaredByApp', false)
            ->where('roles.0.permissionCount', 0)
            ->where('roles.0.holderCount', 0)
            ->where('roles.1.name', 'Auditor')
            ->where('roles.1.declaredByApp', true)
            ->where('roles.1.mayCompose', false)
            ->where('roles.1.permissionCount', 1)
            ->where('sourceCounts', ['custom' => 1, 'app' => 1]));

    $this->get(route('roles', ['source' => 'app']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('source', 'app')
            ->has('roles', 1)
            ->where('roles.0.name', 'Auditor'));
});
