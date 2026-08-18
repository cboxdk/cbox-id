<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * AUTHORIZATION THAT EXPIRES WHEN ACCESS DOES, NOT WHEN THE USER NAVIGATES.
 *
 * These four pages asked their capability question in mount(), and Livewire runs mount()
 * ONCE. A page already open re-hydrates from its snapshot and goes straight to
 * render()/with(), so somebody downgraded out of the capability kept a working page:
 * their browser went on posting to /livewire/update and going on receiving the roster,
 * the environment keys, the API keys, the invoices, for as long as the tab stayed open.
 *
 * The question now also runs in boot(), which Livewire calls on the update path too.
 *
 * THIS IS A SHAPE TEST AND SAYS SO. The behaviour it guards — one more update after a
 * role changes underneath the holder — needs the console middleware to re-resolve
 * CurrentUser between the two requests, and Livewire's component testable calls the
 * component directly without it: a test written that way passes whether the guard is
 * there or not, which is worse than no test. What this catches is the regression that
 * actually happens: somebody moving the check back into mount(), or deleting it, in a
 * component whose own suite still passes because the initial GET is still refused.
 */
it('re-asks the capability question on the update path, not only at mount', function (string $component, string $capability): void {
    $source = file_get_contents(base_path($component));

    expect($source)->toBeString();

    $boot = preg_match('/public function boot\(ConsoleScope \$scope\): void\s*\{(.+?)\n    \}/s', (string) $source, $matches) === 1
        ? $matches[1]
        : null;

    // No custom messages on these: Pest's toContain is VARIADIC, so a "message" second
    // argument is a second needle — and a negated toContain with one passes trivially.
    // The dataset key names the component, which is what a failure needs anyway.
    expect($boot)->not->toBeNull();
    expect($boot)->toContain($capability);
    // The initial request keeps mount()'s redirect: a first request that fails this is
    // somebody arriving where they may not go, and the navigation-honesty test holds the
    // console to redirecting them rather than answering 403 on a link it rendered.
    expect($boot)->toContain('isLivewireRequest');
})->with([
    'the roster, which is PII' => ['resources/views/livewire/console/members.blade.php', 'canReadMembers'],
    'the organization API keys' => ['resources/views/livewire/console/api-keys.blade.php', 'canManageMembers'],
    'the environment API keys' => ['resources/views/livewire/console/environment-keys.blade.php', 'canManageEnvironments'],
    'billing' => ['modules/billing/resources/views/livewire/billing.blade.php', 'canReadBilling'],
])->group('security');
