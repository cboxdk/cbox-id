<?php

declare(strict_types=1);

use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

/**
 * "INVITE YOUR TEAM" LEADS TO THE TEAM.
 *
 * The setup guide's first step and the Roles page's "console access" link were both
 * hard-coded to `members` — which is a CUSTOMER's administrators page. A tenant
 * organization's admin clicking either was redirected to projects, which redirected them to
 * the dashboard: the first step of the setup guide landed back where they started, with
 * nothing on screen to say why.
 *
 * So these FOLLOW THE REDIRECTS and assert the page that is finally drawn. Asserting the
 * href alone would have passed on the broken build too — `/members` is a perfectly good URL.
 */
beforeEach(function (): void {
    installedDeployment();
});

/** Follow a link the way a browser does and say which page it ends on. */
function landOn(string $href): TestResponse
{
    return test()->followingRedirects()->get($href)->assertOk();
}

/** The href of one setup step, as the guide renders it. */
function setupStepHref(string $key): string
{
    $steps = (array) (test()->get(route('get-started'))->assertOk()->inertiaProps()['steps'] ?? []);

    $step = collect($steps)->firstWhere('key', $key);

    expect($step)->not->toBeNull("the guide has no {$key} step");

    return (string) ($step['href'] ?? '');
}

it('sends a tenant organization\'s admin from "Invite your team" to its People page', function (): void {
    actingAsRole(MembershipRole::Owner);

    landOn(setupStepHref('invite-team'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('console/directory-members'));
});

it('sends the dashboard checklist\'s invite step to the same page', function (): void {
    actingAsRole(MembershipRole::Owner);

    $steps = (array) (test()->get(route('dashboard'))->assertOk()->inertiaProps()['checklist']['steps'] ?? []);
    $href = (string) (collect($steps)->firstWhere('key', 'invite-team')['href'] ?? '');

    landOn($href)
        ->assertInertia(fn (AssertableInertia $page) => $page->component('console/directory-members'));
});

it('sends the Roles page\'s "console access" link to the People page', function (): void {
    actingAsRole(MembershipRole::Owner);

    $href = (string) (test()->get(route('roles'))->assertOk()->inertiaProps()['consoleAccessHref'] ?? '');

    landOn($href)
        ->assertInertia(fn (AssertableInertia $page) => $page->component('console/directory-members'));
});

it('still sends a customer to its administrators', function (): void {
    ['subjectId' => $ownerId] = provisionAccount();
    signInAsMember($ownerId);

    landOn(setupStepHref('invite-team'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('console/members'));
});
