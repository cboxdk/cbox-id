<?php

declare(strict_types=1);

use Cbox\Id\AccessControl\Contracts\AppManifests;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Manifest\ManifestParser;
use Cbox\Id\AccessControl\Models\Permission;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\Projects;
use Cbox\Id\Platform\PlatformRoot;

/**
 * THE ANSWER TO "WHICH CLAIM DO I READ", WHERE IT IS ACTUALLY GIVEN.
 *
 * The roles page tells a developer that a role is a label stamped into the token, and then
 * shows the token: the real claim names, with this environment's own role in them. Under
 * Volt that was in the response and a feature test could assert the string. It is drawn by
 * React now, so a request sees `<div id="app">` and nothing else — and a test that kept
 * asserting on the response would have gone on passing with the whole panel deleted.
 *
 * So it is asserted here, in a browser that renders it. The feature suite holds the SERVER
 * half — that the sample role reaches the page as a prop — and this holds the half that is
 * the actual promise: that somebody reading this screen is told `roles`, `permissions` and
 * `groups`, and does not have to guess.
 */
beforeEach(function (): void {
    installedDeployment();
});

/** An owner of an organization with one role and one permission on it. */
function anOwnerLookingAtRoles(): void
{
    platformRootEnvironment();

    $subject = app(PlatformRoot::class)->run(function () {
        $subject = app(Subjects::class)->create('roles-browser@acme.test', 'Owner', 'a-strong-unbreached-passphrase');
        $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-roles-browser'));
        app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
        app(Projects::class)->createForOrganization($org->id, 'Acme');

        $role = app(Roles::class)->define($org->id, 'Support agent');
        $permission = Permission::query()->create([
            'name' => 'reports:view',
            'description' => 'View reports',
            'tenant_assignable' => true,
        ]);
        app(Roles::class)->attachPermission($role->id, $permission->id, $org->id);

        return $subject;
    });

    signInAsMember($subject->id);
}

it('shows the claim shape an app receives, with this environment\'s own role in it', function (): void {
    anOwnerLookingAtRoles();

    $page = visit('/roles');

    $page->assertSee('Support agent')
        // Closed to begin with: it is reference material for a developer, not something
        // an administrator managing access has to scroll past.
        ->assertDontSee('"roles"')
        ->click('What your app receives')
        // THE REAL CLAIM NAMES. The two commonest guesses — `scope`, and a nested
        // `authorization` object — are both wrong, and this is the only place the console
        // says so.
        ->assertSee('"roles"')
        ->assertSee('"permissions"')
        ->assertSee('"reports:view"')
        // And the question people actually arrive with: an app that expects "groups" is
        // asking for these same roles under the name its ecosystem uses.
        ->assertSee('"groups"')
        ->assertSee('If your app expects')
        // The distinction the page exists to make, against the claim it is confused with:
        // `scope` is what the APP was allowed to ask for, not what this PERSON may do.
        ->assertSee('claim is a different thing')
        ->assertNoJavaScriptErrors();
})->group('a11y');

it('opens a role from its row and composes it from the grouped catalogue', function (): void {
    anOwnerLookingAtRoles();

    // A second key, declared and assignable and not yet held — so the role's page has
    // something to offer.
    app(PlatformRoot::class)->run(fn () => Permission::query()->create([
        'name' => 'reports:export',
        'description' => 'Export reports',
        'tenant_assignable' => true,
    ]));

    // The row IS the link: no "Manage" button, no picker inside it.
    $page = visit('/roles');

    $page->assertSee('Support agent')
        ->assertSee('Custom roles')
        ->click('a.idc-row:has-text("Support agent")');

    // Grouped by resource, each permission in words with its key beside it.
    $page->assertSee('Reports')
        ->assertSee('View reports')
        ->assertSee('reports:export')
        ->assertSee('Granted: 1 of')
        ->click('label:has-text("Export reports")')
        ->assertSee('Granted: 2 of')
        ->assertNoJavaScriptErrors();
})->group('a11y');

it('draws "All apps" as the chosen default on the new-role form, not the picker\'s placeholder', function (): void {
    anOwnerLookingAtRoles();

    // Radix reserves the empty string, so an option carrying `''` is never drawn as chosen:
    // the trigger read "Select…" over a default that was in fact every app.
    visit('/roles/new')
        ->assertSee('Applies to')
        ->assertScript('Array.from(document.querySelectorAll(".cbx-select")).map((b) => b.textContent.trim()).includes("All apps")', true)
        ->assertScript('Array.from(document.querySelectorAll(".cbx-select")).some((b) => b.textContent.includes("Select…"))', false)
        ->assertNoJavaScriptErrors();
});

it('activates only the app role definition the administrator actually reviewed', function (): void {
    ['envId' => $environmentId] = crudSetup();
    $manifests = app(AppManifests::class);
    $parser = app(ManifestParser::class);
    $publish = function (array $permissions) use ($manifests, $parser): void {
        $manifests->sync('browser-sites', $parser->parse([
            'version' => 'test',
            'permissions' => [['key' => 'sites:read'], ['key' => 'sites:publish'], ['key' => 'sites:delete']],
            'roles' => [['key' => 'editor', 'name' => 'Site editor', 'permissions' => $permissions]],
        ]));
    };
    $publish(['sites:read']);
    $publish(['sites:read', 'sites:publish']);
    $role = Role::query()->where('client_id', 'browser-sites')->where('key', 'editor')->sole();
    $page = visit(route('environment.roles.show', $role->id));
    $page->assertSee('sites:publish')->click('button:has-text("Activate reviewed definition")')
        ->fill('.cbx-dialog input.input', 'Site editor');
    // The browser's requests ran in this process, on the app host, and left its
    // environment context there; the publish below is this environment's app.
    app(EnvironmentContext::class)->set(GenericEnvironment::of($environmentId));
    $publish(['sites:read', 'sites:publish', 'sites:delete']);
    $page->click('.cbx-dialog button:has-text("Activate definition")')
        ->assertSee('The pending role definition has changed or is no longer available. Review it again before activating.')
        ->assertNoJavaScriptErrors();
    expect($role->refresh()->pending_definition_version)->not->toBeNull();
    $page->assertSee('sites:delete')->click('button:has-text("Activate reviewed definition")')
        ->fill('.cbx-dialog input.input', 'Site editor')->click('.cbx-dialog button:has-text("Activate definition")')
        ->assertDontSee('Activate reviewed definition')->assertNoJavaScriptErrors();
    expect($role->refresh()->pending_definition_version)->toBeNull();
});
