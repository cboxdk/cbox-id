<?php

declare(strict_types=1);

use App\Mail\PortalLinkMail;
use App\Models\AdminPortalLink;
use App\Platform\Console\ConsoleScope;
use App\Platform\Console\OrganizationTabs;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Contracts\DnsResolver;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Federation\Testing\FakeDnsResolver;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia;

/**
 * AN ORGANIZATION'S OWN PAGE, AND THE END OF THE "ACTING ORGANIZATION".
 *
 * The environment console used to keep one organization in the session — chosen in the
 * console header — and sixteen pages quietly narrowed themselves to it. These assert what
 * replaced it: an organization's page at `/admin/organizations/{organization}`, one tab per
 * URL; lists narrowed by `?organization=` in their own address; and a lookup that only ever
 * reads.
 */
function anEnvironmentAdminWithOrganizations(): string
{
    // `/admin` exists only on a multi-tenant deployment ({@see \App\Http\Middleware\RequireMultiTenant}).
    multiTenantDeployment();

    platformRootEnvironment();

    $provisioned = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'hub-owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($provisioned->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($provisioned->environment->id));
    actAsEnvironmentAdmin($provisioned->owner->id, $provisioned->environment->id);

    return app(Organizations::class)->create(new NewOrganization('Tenant Co', 'tenant-co'))->id;
}

/** An organization in ANOTHER environment — real, and not this console's to reach. */
function anOrganizationElsewhere(): string
{
    $elsewhere = Environment::query()->create([
        'name' => 'Elsewhere', 'slug' => 'hub-elsewhere', 'status' => 'active', 'is_default' => false,
    ]);

    return app(EnvironmentContext::class)->runAs(
        GenericEnvironment::of($elsewhere->id),
        static fn (): string => app(Organizations::class)->create(new NewOrganization('Not Yours', 'not-yours'))->id,
    );
}

it('draws every tab of an organization\'s page under its own URL, with the header around it', function (): void {
    $orgId = anEnvironmentAdminWithOrganizations();

    $tabs = [
        OrganizationTabs::OVERVIEW => 'environment/organizations/tabs/overview',
        OrganizationTabs::MEMBERS => 'environment/organizations/tabs/members',
        OrganizationTabs::INVITATIONS => 'environment/organizations/tabs/invitations',
        OrganizationTabs::SSO => 'console/connections/index',
        OrganizationTabs::DIRECTORY_SYNC => 'console/directories/index',
        OrganizationTabs::DOMAINS => 'environment/organizations/tabs/domains',
        OrganizationTabs::ROLES => 'console/roles/index',
        OrganizationTabs::API_KEYS => 'environment/organizations/tabs/api-keys',
        OrganizationTabs::BRANDING => 'console/appearance',
        OrganizationTabs::POLICY => 'console/auth-policy',
        OrganizationTabs::SUPPORT => 'environment/organizations/tabs/support',
        OrganizationTabs::AUDIT => 'console/audit',
        OrganizationTabs::AUDIT_LOGS => 'console/audit-logs/index',
        OrganizationTabs::SETTINGS => 'environment/organizations/tabs/settings',
    ];

    foreach ($tabs as $tab => $component) {
        $this->get(route(OrganizationTabs::route($tab), $orgId))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component($component)
                ->where('organizationHub.id', $orgId)
                ->where('organizationHub.name', 'Tenant Co')
                // Nine tabs, the page's group lit — fourteen in one row ran off a laptop's
                // screen — and the group's own pages as a second row where it has several.
                ->where('organizationHub.tabs', fn (Collection $drawn): bool => $drawn->count() === 9
                    && $drawn->firstWhere('current', true)['key'] === OrganizationTabs::groupOf($tab))
                ->where('organizationHub.subTabs', fn (Collection $drawn): bool => $drawn->isEmpty()
                    || $drawn->firstWhere('current', true)['key'] === $tab));
    }

    // Every page has a tab to be found under: none is reachable only by URL.
    foreach (array_keys($tabs) as $tab) {
        expect(OrganizationTabs::groupOf($tab))->not->toBeNull("[{$tab}] is under no tab");
    }
})->group('security');

it('answers 404 for an organization that is not in this environment, on every tab', function (): void {
    anEnvironmentAdminWithOrganizations();
    $foreign = anOrganizationElsewhere();

    foreach ([OrganizationTabs::OVERVIEW, OrganizationTabs::SSO, OrganizationTabs::ROLES, OrganizationTabs::POLICY, OrganizationTabs::AUDIT] as $tab) {
        $this->get(route(OrganizationTabs::route($tab), $foreign))->assertNotFound();
        $this->get(route(OrganizationTabs::route($tab), '01JQZZZZZZZZZZZZZZZZZZZZZZ'))->assertNotFound();
    }

    // …and the writes that live under the organization's URL too: a foreign id never
    // reaches the action.
    $this->post(route('environment.organizations.portal-links.store', $foreign), ['intents' => ['sso']])->assertNotFound();
    $this->post(route('environment.connections.domains.store', $foreign), ['domain' => 'evil.example'])->assertNotFound();
})->group('security');

it('keeps the organization a page was about out of the next request', function (): void {
    // The hidden filter this replaced lived in the session. A URL's organization is THAT
    // request's: the environment-wide list after it is every organization's again.
    $orgId = anEnvironmentAdminWithOrganizations();
    $other = app(Organizations::class)->create(new NewOrganization('Other Co', 'other-co'))->id;
    app(Connections::class)->create($orgId, ConnectionType::Saml, 'Mine', []);
    app(Connections::class)->create($other, ConnectionType::Saml, 'Theirs', []);

    $this->get(route('environment.organizations.sso', $orgId))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('connections', fn (Collection $rows): bool => $rows->pluck('name')->all() === ['Mine']));

    $this->get(route('environment.connections'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('connections', fn (Collection $rows): bool => $rows->pluck('name')->sort()->values()->all() === ['Mine', 'Theirs'])
            ->where('organizationHub', null));

    expect(app(ConsoleScope::class)->organizationId())->toBeNull();
})->group('security');

it('says on the overview whether the organization is set up, from what is configured', function (): void {
    $orgId = anEnvironmentAdminWithOrganizations();

    $overview = fn () => $this->get(route('environment.organizations.show', $orgId))->assertOk();

    $overview()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('setup.0.key', 'sso')->where('setup.0.done', false)
        ->where('setup.1.key', 'domain')->where('setup.1.done', false)
        ->where('setup.2.key', 'directory')->where('setup.2.done', false));

    $connection = app(Connections::class)->create($orgId, ConnectionType::Saml, 'Corp', []);
    app(Connections::class)->activate($orgId, $connection->id);
    $domain = app(DomainVerification::class)->add($orgId, 'tenant.example');
    $domain->forceFill(['verified_at' => now()])->save();

    app(AuditLog::class)->record(new AuditEvent(action: 'member.added', actorType: ActorType::System, organizationId: $orgId));
    app(AuditLog::class)->record(new AuditEvent(action: 'somebody.elses', actorType: ActorType::System));

    $overview()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('setup.0.done', true)
        ->where('setup.1.done', true)
        ->where('recent', fn (Collection $rows): bool => $rows->pluck('action')->contains('member.added')
            && ! $rows->pluck('action')->contains('somebody.elses')));

    expect(Connection::query()->whereKey($connection->id)->exists())->toBeTrue();
});

it('mints an Admin Portal link from the header, on the flash channel, as the administrator', function (): void {
    $orgId = anEnvironmentAdminWithOrganizations();
    $actor = app(ConsoleScope::class)->actorId();

    $this->get(route('environment.organizations.show', $orgId))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('organizationHub.portalLink.href', route('environment.organizations.portal-links.store', $orgId)));

    $this->from(route('environment.organizations.show', $orgId))
        ->post(route('environment.organizations.portal-links.store', $orgId), ['intents' => ['sso']])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('portalUrl');

    $flash = session()->get(SessionKey::FLASH_DATA, []);

    expect(is_array($flash) ? ($flash['portalUrl'] ?? '') : '')->toContain('/setup/')
        ->and(AdminPortalLink::query()->where('organization_id', $orgId)->value('created_by'))->toBe($actor);
})->group('security');

it('opens a create form from an organization\'s page prefilled and locked to it', function (): void {
    $orgId = anEnvironmentAdminWithOrganizations();

    $this->get(route('environment.organizations.sso', $orgId))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('createHref', route('environment.connections.create', ['organization' => $orgId])));

    $this->get(route('environment.connections.create', ['organization' => $orgId]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('organization.selected.id', $orgId)
            ->where('organization.locked', true)
            ->where('organization.lookupHref', route('environment.lookup.organizations')));

    // Opened from the list, the form asks; a link naming no organization here prefills none.
    foreach ([[], ['organization' => anOrganizationElsewhere()]] as $query) {
        $this->get(route('environment.connections.create', $query))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('organization.selected', null)
                ->where('organization.locked', false));
    }
});

it('refuses a create form that names an organization outside this environment', function (): void {
    anEnvironmentAdminWithOrganizations();
    $foreign = anOrganizationElsewhere();

    createConnection(['name' => 'Smuggled', 'organization' => $foreign], 'environment.connections')
        ->assertSessionHasErrors(['organization' => 'That organization is not in this environment.']);

    expect(Connection::query()->where('name', 'Smuggled')->exists())->toBeFalse();
})->group('security');

it('narrows an environment-wide list by ?organization=, and empties it for an id from anywhere else', function (): void {
    $orgId = anEnvironmentAdminWithOrganizations();
    $other = app(Organizations::class)->create(new NewOrganization('Other Co', 'other-co'))->id;
    app(Connections::class)->create($orgId, ConnectionType::Saml, 'Mine', []);
    app(Connections::class)->create($other, ConnectionType::Saml, 'Theirs', []);

    $names = fn (array $query): array => collect((array) $this->get(route('environment.connections', $query))->assertOk()->inertiaProps('connections'))
        ->pluck('name')->sort()->values()->all();

    expect($names([]))->toBe(['Mine', 'Theirs'])
        ->and($names(['organization' => $other]))->toBe(['Theirs'])
        ->and($names(['organization' => anOrganizationElsewhere()]))->toBe([])
        ->and($names(['organization' => 'not-an-id']))->toBe([]);
})->group('security');

it('looks organizations up a page at a time, and only for an environment administrator', function (): void {
    anEnvironmentAdminWithOrganizations();

    foreach (range(1, ConsoleScope::LOOKUP_LIMIT + 5) as $i) {
        app(Organizations::class)->create(new NewOrganization("Tenant {$i}", "tenant-{$i}"));
    }

    $this->getJson(route('environment.lookup.organizations'))
        ->assertOk()
        ->assertJsonCount(ConsoleScope::LOOKUP_LIMIT, 'results')
        // Tenant Co plus the thirteen above: the total says how many more there are.
        ->assertJsonPath('total', ConsoleScope::LOOKUP_LIMIT + 6);

    $this->getJson(route('environment.lookup.organizations', ['q' => 'Tenant 3']))
        ->assertOk()
        ->assertJsonPath('results.0.name', 'Tenant 3');

    // The old address of the lookup answers with the new one.
    $this->get('/admin/acting-organization?q=Tenant')
        ->assertStatus(301)
        ->assertRedirect('/admin/lookup/organizations?q=Tenant');
});

it('never answers the lookup without an environment-admin session', function (): void {
    multiTenantDeployment();
    platformRootEnvironment();

    $provisioned = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'stranger-owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($provisioned->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($provisioned->environment->id));

    app(Organizations::class)->create(new NewOrganization('Secret Tenant', 'secret-tenant'));

    $search = $this->getJson(route('environment.lookup.organizations'));

    expect($search->status())->not->toBe(200)
        ->and($search->getContent())->not->toContain('Secret Tenant');
})->group('security');

it('keeps one organization\'s token vault on its own page, apart from the environment\'s', function (): void {
    // Two collections, not a wider and a narrower view of one: the environment's own
    // secrets are its vault page; an organization's are under that organization's address,
    // and its links stay there.
    $orgId = anEnvironmentAdminWithOrganizations();
    confirmEnvironmentStepUp();

    $this->from(route('environment.organizations.vault.create', $orgId))
        ->post(route('environment.organizations.vault.store', $orgId), [
            'name' => 'their-openai',
            'provider' => 'openai',
            'secret' => 'sk-live-org',
        ])
        ->assertSessionHasNoErrors();

    $this->get(route('environment.organizations.vault', $orgId))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('organizationHub.id', $orgId)
            ->where('environmentWide', false)
            ->where('createHref', route('environment.organizations.vault.create', $orgId))
            ->where('secrets', fn (Collection $rows): bool => $rows->pluck('name')->all() === ['their-openai']
                && str_starts_with((string) $rows->first()['href'], route('environment.organizations.vault', $orgId).'/')));

    $this->get(route('environment.vault'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('environmentWide', true)
            ->where('secrets', [])
            // The chip GOES to an organization's vault rather than narrowing this one.
            ->where('organizationFilter.hrefTemplate', route('environment.organizations.vault', '__organization__')));
})->group('security');

it('files every tab of an organization\'s page under Users & orgs › Organizations', function (): void {
    // The rail lands on the list, rows open the organization's page, and every tab of it —
    // including the environment-wide pages drawn there — keeps the reader in the same place.
    $orgId = anEnvironmentAdminWithOrganizations();

    $list = (array) $this->get(route('environment.organizations'))->assertOk()->inertiaProps('shell');

    $this->get(route('environment.organizations'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('organizations', fn (Collection $rows): bool => $rows->firstWhere('id', $orgId)['href'] === route('environment.organizations.show', $orgId)));

    foreach ([OrganizationTabs::OVERVIEW, OrganizationTabs::SSO, OrganizationTabs::AUDIT, OrganizationTabs::POLICY] as $tab) {
        $shell = (array) $this->get(route(OrganizationTabs::route($tab), $orgId))->assertOk()->inertiaProps('shell');

        expect($shell['activeArea'])->toBe($list['activeArea'], "the {$tab} tab lit another area of the rail");
    }
});

it('carries no acting organization in the console chrome any more', function (): void {
    anEnvironmentAdminWithOrganizations();

    $shell = (array) $this->get(route('environment.home'))->assertOk()->inertiaProps('shell');

    expect($shell)->not->toHaveKey('actingOrganization');
});

it('mints a link from the organization hub with intents, lifetime and the IT contact\'s address', function (): void {
    Mail::fake();
    $orgId = anEnvironmentAdminWithOrganizations();

    $this->get(route('environment.organizations.show', $orgId))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('organizationHub.portalLink.intents.0.value', 'sso')
            ->where('organizationHub.portalLink.intents.4.value', 'certificate_renewal')
            ->has('organizationHub.portalLink.lifetimes', 5)
            ->has('organizationHub.portalLink.locales'));

    $this->from(route('environment.organizations.show', $orgId))
        ->post(route('environment.organizations.portal-links.store', $orgId), [
            'intents' => ['sso', 'dsync'],
            'expires_in_minutes' => '4320',
            'email' => 'it@tenant.example',
            'locale' => 'fr',
        ])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('portalUrl');

    $link = AdminPortalLink::query()->where('organization_id', $orgId)->sole();

    expect($link->intents)->toBe(['sso', 'dsync'])
        ->and($link->emailed_to)->toBe('it@tenant.example')
        ->and((int) round(now()->diffInMinutes($link->expires_at)))->toBe(4320);

    Mail::assertSent(PortalLinkMail::class, fn (PortalLinkMail $mail): bool => $mail->locale === 'fr');

    // Nothing ticked is a field error, not a link.
    $this->from(route('environment.organizations.show', $orgId))
        ->post(route('environment.organizations.portal-links.store', $orgId), ['intents' => []])
        ->assertSessionHasErrors('intents');
});

it('lists the outstanding Admin Portal links on the overview, and revokes one as the administrator', function (): void {
    $orgId = anEnvironmentAdminWithOrganizations();
    $actor = app(ConsoleScope::class)->actorId();

    $this->post(route('environment.organizations.portal-links.store', $orgId), ['intents' => ['sso'], 'email' => 'it@tenant.example'])
        ->assertSessionHasNoErrors();
    $link = AdminPortalLink::query()->where('organization_id', $orgId)->sole();

    // A finished one is the audit log's to tell, not the overview's.
    AdminPortalLink::query()->create([
        'organization_id' => $orgId,
        'intents' => ['dsync'],
        'token_hash' => hash('sha256', 'finished'),
        'expires_at' => now()->addHour(),
        'consumed_at' => now(),
        'completed_at' => now(),
        'created_by' => $actor,
    ]);

    $this->get(route('environment.organizations.show', $orgId))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('portalLinks', 1)
            ->where('portalLinks.0.id', $link->id)
            ->where('portalLinks.0.intents', ['Enterprise SSO'])
            ->where('portalLinks.0.inUse', false)
            ->where('portalLinks.0.emailedTo', 'it@tenant.example')
            ->where('portalLinks.0.revokeHref', route('environment.organizations.portal-links.revoke', ['organization' => $orgId, 'link' => $link->id]))
            ->missing('portalLinks.0.url'));

    $this->from(route('environment.organizations.show', $orgId))
        ->delete(route('environment.organizations.portal-links.revoke', ['organization' => $orgId, 'link' => $link->id]))
        ->assertRedirect(route('environment.organizations.show', $orgId))
        ->assertSessionHas('status', 'Admin Portal link revoked.');

    expect($link->refresh()->revoked_at)->not->toBeNull();

    $entry = AuditEntry::query()->where('action', 'portal_link.revoked')->sole();

    expect($entry->actor_id)->toBe($actor)
        ->and($entry->actor_type)->toBe(ActorType::OrganizationMember)
        ->and($entry->target_id)->toBe($link->id);

    $this->get(route('environment.organizations.show', $orgId))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('portalLinks', 0));
})->group('security');

it('answers 404 for revoking another organization\'s Admin Portal link from this one\'s page', function (): void {
    $orgId = anEnvironmentAdminWithOrganizations();
    $other = app(Organizations::class)->create(new NewOrganization('Other Co', 'other-portal-co'))->id;

    $this->post(route('environment.organizations.portal-links.store', $other), ['intents' => ['sso']])->assertSessionHasNoErrors();
    $theirs = AdminPortalLink::query()->where('organization_id', $other)->sole();

    $this->delete(route('environment.organizations.portal-links.revoke', ['organization' => $orgId, 'link' => $theirs->id]))->assertNotFound();
    $this->delete(route('environment.organizations.portal-links.revoke', ['organization' => anOrganizationElsewhere(), 'link' => $theirs->id]))->assertNotFound();

    expect($theirs->refresh()->revoked_at)->toBeNull();
})->group('security');

/*
 * THE TXT RECORD'S NAME IS THE VERIFIER'S, NOT THE PAGE'S. The Domains tab used to say
 * "add this TXT record at acme.example" while the check looked it up at
 * `_cbox-id-challenge.acme.example` — a record published where the page said was never
 * found. The name now comes from the server, and publishing exactly what the page shows
 * is what verifies.
 */
it('shows the TXT record where the verifier looks for it, and publishing exactly that verifies', function (): void {
    $dns = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $dns);
    app()->forgetInstance(DomainVerification::class);

    $orgId = anEnvironmentAdminWithOrganizations();
    app(DomainVerification::class)->add($orgId, 'acme.example');

    $row = ((array) $this->get(route(OrganizationTabs::route(OrganizationTabs::DOMAINS), $orgId))->assertOk()->inertiaProps('domains'))[0];

    expect($row['recordName'])->toBe('_cbox-id-challenge.acme.example')
        ->and($row['token'])->not->toBe('');

    $dns->publish($row['recordName'], $row['token']);
    $this->post($row['urls']['verify'])->assertSessionHasNoErrors();

    expect(app(DomainVerification::class)->forOrganization($orgId)[0]->isVerified())->toBeTrue();
});

it('names the TXT record the same way on the organization console\'s own Domains page', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    app(DomainVerification::class)->add($org->id, 'Tenant.Example');

    $row = ((array) $this->get(route('domains'))->assertOk()->inertiaProps('domains'))[0];

    expect($row['recordName'])->toBe('_cbox-id-challenge.tenant.example');
});
