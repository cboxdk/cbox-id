<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Support\Facades\File;

/**
 * THE CONSOLE SHELLS MUST FRAME A PAGE THE SAME WAY.
 *
 * The console was built twice, and most of what survived the fold is chrome: layouts that
 * take the same contract and render the same shared components at different sizes.
 * Measured when this test was written: the organization shell framed its content at 72rem
 * with `p-6 lg:p-8`, the environment shell at `max-w-5xl` (64rem) with `px-5 py-8` — one
 * page, two widths, a table that fits on one plane and wraps on the other.
 *
 * THE PAIR HAS CHANGED but the hazard has not. Both planes are framed by ONE React layout
 * now, so they cannot disagree with each other; what they CAN disagree with is the blade
 * shell still framing the pages that have not ported, which sit in the same console behind
 * the same rail. A person clicking between them sees the seam.
 *
 * Asserted on the container declaration itself rather than by rendering, because the
 * failure is a static difference between two files and this names it precisely when it
 * reappears. A rendered assertion would report "something looks wrong" instead.
 */
/**
 * THE PARITY PARTNER IS GONE, and the constant it was checked against is what remains.
 *
 * This compared the React shell's content container to `layouts/app.blade.php`'s, because
 * for the length of the port two shells framed the same pages and a reader moving between
 * a ported and an unported one would have seen the column change width under them. There
 * is one shell now. Comparing it to nothing would pass forever, so what is asserted is the
 * frame ITSELF: one padding scale, one maximum width, stated once.
 *
 * The numbers are not arbitrary and are not this test's to choose — `72rem` is the reading
 * measure the console's tables and forms are laid out against, and `p-6 lg:p-8` is the
 * gutter every page sits in. They are here so that changing them is a decision somebody
 * makes rather than a class somebody edits.
 */
it('frames every page at the console one width and padding', function (): void {
    preg_match(
        '#<main[^>]*>\s*(?:\{/\*.*?\*/\}\s*)*<div([^>]*)>#s',
        File::get(base_path('resources/js/layouts/ConsoleLayout.tsx')),
        $container,
    );

    expect($container)->not->toBeEmpty('could not find the content container in the console shell');

    expect($container[1])->toContain('p-6 lg:p-8')
        ->and($container[1])->toContain('72rem');
})->group('ux');

/**
 * BOTH SHELLS MUST ACTUALLY EMIT A MOBILE NAVIGATION.
 *
 * `components/mobile-nav` calls itself "shared by every console shell" and was used by
 * the environment shell alone; the organization shell hand-rolled a hamburger drawer
 * beside it. Two interaction models over what are now the same page components — and the
 * shared one is the house pattern, a bar pinned in the thumb arc rather than a control
 * stranded in the top corner.
 *
 * This asserts the rendered markup, not the source, because the failure it guards against
 * is a shell that emits NO mobile navigation at all — which every existing test passes
 * happily, since a suite cannot see whether a control is drawn. It is not a substitute for
 * looking at the page on a phone; it is the part of that which can be automated.
 */
it('emits the shared mobile navigation on the organization plane', function (): void {
    // A real console session — `actingAsRole` sets the CurrentUser but not the session
    // key the console's own guard reads, so the dashboard answers 302 under it.
    $subject = app(Subjects::class)
        ->create('shell@acme.test', 'Shell Owner', 'supersecret123');
    $org = app(Organizations::class)
        ->create(new NewOrganization('Acme', 'acme-shell'));
    app(Memberships::class)
        ->add($org->id, $subject->id, MembershipRole::Owner);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);

    /*
     * ASKED OF THE SERVER, on the plane that is now React.
     *
     * The markers this looked for — `data-cbox-mobile-nav`, the bottom-bar classes — were
     * the blade component's, and the bottom bar is one React component inside a shared
     * layout now, so BOTH planes draw it or neither does. What the server still decides,
     * and what the bug was, is whether this plane is handed the nav the bar is built from:
     * a bottom bar with nothing in it is the same missing control by a shorter route.
     *
     * That it is DRAWN from these areas is held in tests/Browser, which is the only place
     * that can see it.
     */
    $shell = (array) $this->get(route('dashboard'))->assertOk()->inertiaProps('shell');

    expect($shell['areas'])->not->toBe([])
        ->and($shell['activeArea'])->not->toBeNull();
})->group('ux');

/**
 * AND THE OTHER PLANE, which the test above named and never visited.
 *
 * It issued one GET to `route('dashboard')` — the organization plane — while its name
 * promised both, so deleting `<x-mobile-nav>` from `layouts/environment.blade.php` left it
 * green. That is "green tests, invisible UI" re-opened on the plane the test names, and a
 * suite cannot see whether a control is drawn unless it looks at the plane it is drawn on.
 */
it('emits the shared mobile navigation on the environment plane too', function (): void {
    // The whole `/admin` prefix carries `multi.tenant` and 404s otherwise: a single-tenant
    // install has one environment, it is the platform root, and it belongs to no account.
    multiTenantDeployment();
    actAsEnvironmentAdminOfATenant();

    /*
     * THE SAME SHELL, asked of the SERVER rather than of the markup.
     *
     * Both planes render one React `ConsoleLayout` now, and the bottom bar is one component
     * inside it — so "does this plane draw it" is no longer a question about this response,
     * which carries a mount point. What the server still decides, and what the bug was, is
     * whether this plane is handed the NAV THE BAR IS BUILT FROM: the environment shell
     * returned its own payload and could return an empty one, and a bottom bar with nothing
     * in it is the same missing control by a shorter route.
     *
     * That it is DRAWN from these areas is held in tests/Browser, which is the only place
     * that can see it.
     */
    $shell = (array) $this->get(route('environment.home'))->assertOk()->inertiaProps('shell');

    expect($shell['areas'])->not->toBe([])
        ->and($shell['activeArea'])->not->toBeNull();
})->group('ux');

/**
 * BOTH PLANES HAND THE CHROME A PERSON.
 *
 * `auth.user` is what the layout reads to decide whether the console has a FRAME at all —
 * a rail, a sub-nav, a bottom bar, an account menu, a way to sign out. It is built from the
 * subject session, and a subject belongs to one environment's user pool, so an account
 * member administering a tenant's environment is not in it: on that plane the prop answered
 * "guest", and every page rendered as a bare document with no navigation on it.
 *
 * Nothing went red. The pages were all 200 and their own props were all correct — the
 * chrome is a SHARED prop, and no page's test looks at it. This is that test.
 */
it('names the acting person on both planes, so the console has a frame', function (string $plane): void {
    if ($plane === 'environment') {
        multiTenantDeployment();
        actAsEnvironmentAdminOfATenant();
        $page = test()->get(route('environment.home'));
    } else {
        [$subjectId] = actingAsRole(MembershipRole::Owner);
        expect($subjectId)->not->toBe('');
        $page = test()->get(route('dashboard'));
    }

    $user = $page->assertOk()->inertiaProps('auth.user');

    expect($user)->not->toBeNull('this plane renders with no navigation at all')
        // Named, not merely present: the account menu and the rail's own item are labelled
        // with it, and a blank one is a control nobody can find.
        ->and((string) $user['name'])->not->toBe('');
})->with(['organization', 'environment'])->group('ux');
