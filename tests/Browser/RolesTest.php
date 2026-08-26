<?php

declare(strict_types=1);

use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Models\Permission;
use Cbox\Id\Identity\Contracts\Subjects;
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

it('composes a role from the catalogue without leaving the list', function (): void {
    anOwnerLookingAtRoles();

    // A second key, declared and assignable and not yet held — so the row has something
    // to offer. The picker is the organization plane's own capability, and it is the one
    // thing on this page that is neither a link nor a form submit.
    app(PlatformRoot::class)->run(fn () => Permission::query()->create([
        'name' => 'reports:export',
        'description' => 'Export reports',
        'tenant_assignable' => true,
    ]));

    $page = visit('/roles');

    $page->assertSee('reports:view')
        ->assertDontSee('reports:export');

    // A combobox rather than a native select: the trigger is named, and the option is a
    // listbox row rather than a control a person can tab to.
    $page->click('[aria-label="Add a permission to the Support agent role"]')
        ->click('[role="option"]:has-text("reports:export")')
        ->assertSee('reports:export')
        ->assertNoJavaScriptErrors();
})->group('a11y');
