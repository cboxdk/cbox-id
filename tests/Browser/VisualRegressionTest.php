<?php

declare(strict_types=1);

use App\Platform\Sudo;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\Projects;
use Cbox\Id\Platform\PlatformRoot;

/**
 * WHAT THE CONSOLE LOOKS LIKE, HELD AS A PICTURE.
 *
 * Every other test in this directory asserts something a person named in advance: this
 * text, that control, no axe violation. A layout regression is the opposite shape — nobody
 * predicted it, and the page is still perfectly accessible and full of the right words
 * while being visibly broken. A card grid that collapses to one column, a rail that
 * doubles in width, a panel whose padding goes, a dark palette that loses its contrast:
 * every one of those passes the whole suite today.
 *
 * So a small, deliberate set of pages is compared against a committed baseline. Small
 * ON PURPOSE — a baseline per page per theme per width is a maintenance cost paid on every
 * intentional design change, and a suite that cries wolf gets its baselines regenerated
 * without being read, which is worse than not having it. These five are the distinct
 * LAYOUTS the console has, not five pages that happen to exist:
 *
 *   the public door · the card dashboard · a dense table · a long form · an empty state
 *
 * `assertScreenshotMatches()` does the work that makes this stable rather than flaky: it
 * kills transitions and animations, pins the font to one that ships everywhere, and waits
 * for `networkidle` and `readyState === complete` before it looks.
 *
 * WHAT IT CANNOT DO IS STOP THE CLOCK, and neither can this file. A page rendered with a
 * date on it — "Wed, Aug 26, 2026", "2 minutes ago" — produces a different picture
 * tomorrow, and a baseline that rots on a calendar is one people regenerate without
 * reading, which is exactly the habit that makes the whole idea worthless. The obvious fix
 * does not work either: `travelTo()` moves the clock in THIS process, and the page is
 * rendered by a separate server process that never hears about it.
 *
 * So the set below is chosen to be TIME-INVARIANT. None of these five pages renders a date
 * or a relative time. The audit trail and the activity log are the console's densest
 * tables and would have been the obvious things to pin — they are deliberately absent, and
 * that is the trade: a page whose picture cannot be stable does not get a picture. The
 * dashboard is absent for the same reason — its audit-export card renders a pending count
 * that varies run to run.
 *
 * WHEN ONE FAILS, LOOK AT THE DIFF BEFORE REGENERATING IT. That is the entire value here.
 * Regenerating because a test went red, without looking, is how a visual suite becomes a
 * rubber stamp.
 *
 * THE COMMITTED BASELINES ARE DRAWN ON LINUX, by `.github/workflows/visual-baselines.yml`,
 * because that is what CI judges with — a picture drawn on macOS/arm64 fails on Linux/x64
 * for reasons that have nothing to do with the change (text rasterises differently, and
 * the font this assertion pins is substituted). So running `--group=visual` on a Mac will
 * show diffs on every page and none of them mean anything. Locally, run it to LOOK at a
 * page; to change what is committed, run the workflow and commit what it uploads.
 */
beforeEach(function (): void {
    installedDeployment();
});

/** An owner of a fixed organization, so the pictures have the same words in them each run. */
function anOwnerForPictures(): void
{
    platformRootEnvironment();

    $subject = app(PlatformRoot::class)->run(function () {
        $subject = app(Subjects::class)->create('pictures@acme.test', 'Ada Lovelace', 'a-strong-unbreached-passphrase');
        app(Subjects::class)->markEmailVerified($subject->id, 'pictures@acme.test');

        $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-pictures'));
        app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
        app(Projects::class)->createForOrganization($org->id, 'Acme');

        return $subject;
    });

    signInAsMember($subject->id);

    // Registering an endpoint is behind a fresh-password window. That gate is held in
    // ConsoleStepUpTest; opening it here keeps this file about pixels.
    app(Sudo::class)->confirm();
}

it('draws the sign-in door the way it was drawn', function (): void {
    visit('/login')
        ->assertSee('Sign in')
        ->assertScreenshotMatches();
})->group('visual');

it('draws the sign-in door in the dark the way it was drawn', function (): void {
    visit('/login')
        ->inDarkMode()
        ->assertSee('Sign in')
        ->assertScreenshotMatches();
})->group('visual');

it('draws the console shell and its checklist the way they were drawn', function (): void {
    anOwnerForPictures();

    /*
     * The rail, the sub-nav, the topbar and a stack of cards — the frame every other
     * console page is drawn inside, so a regression here is a regression on all of them.
     *
     * NOT `/dashboard`, which was the obvious choice and is not stable: its audit-export
     * card renders a pending COUNT that differs between runs, so the picture disagreed
     * with itself twice in a row. Same category as a timestamp, and the same rule applies.
     */
    visit('/get-started')
        ->assertSee('Set up Acme')
        ->assertScreenshotMatches();
})->group('visual');

it('draws the console shell in the dark the way it was drawn', function (): void {
    anOwnerForPictures();

    /*
     * THE DARK PALETTE IS A SECOND SET OF TOKENS and nothing else measures it as a whole.
     * axe checks contrast per element against what it can compute; this catches a token
     * that changed shade and took a whole surface with it.
     */
    visit('/get-started')
        ->inDarkMode()
        ->assertSee('Set up Acme')
        ->assertScreenshotMatches();
})->group('visual');

it('draws a long form the way it was drawn', function (): void {
    anOwnerForPictures();

    // Fields, a fieldset of twenty-four checkboxes, and a submit — the densest layout in
    // the console and the one most likely to shift when a spacing token moves.
    visit('/webhooks/new')
        ->assertSee('New webhook')
        ->assertScreenshotMatches();
})->group('visual');

it('draws an empty state the way it was drawn', function (): void {
    anOwnerForPictures();

    // The first thing every new customer sees, on every list, and the layout with the
    // least text holding it up.
    visit('/webhooks')
        ->assertSee('Nothing is being notified yet')
        ->assertScreenshotMatches();
})->group('visual');

it('draws the console on a phone the way it was drawn', function (): void {
    anOwnerForPictures();

    /*
     * `resize()` RATHER THAN THE DEVICE PRESET — the preset emulates touch, under which
     * this harness completes no click and `script()` reads an unrendered document. Same
     * breakpoint, same layout, and it behaves. See ConsoleMobileTest for the whole story.
     */
    visit('/get-started')
        ->resize(375, 812)
        ->assertSee('Set up Acme')
        ->assertScreenshotMatches();
})->group('visual');
