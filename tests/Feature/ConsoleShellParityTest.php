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
 * THE TWO CONSOLE SHELLS MUST FRAME A PAGE THE SAME WAY.
 *
 * The console was built twice, and most of what survived the fold is chrome: two layouts
 * that take the same contract and render the same shared components at different sizes.
 * Measured before this test: the organization shell framed its content at 72rem with
 * `p-6 lg:p-8`, the environment shell at `max-w-5xl` (64rem) with `px-5 py-8`.
 *
 * That was invisible while the two planes had two sets of pages. It stopped being
 * invisible when the pages became shared: `permissions`, `audit-streams`, `usage` and the
 * twenty capability pages are ONE component each now, so a width difference is one page
 * with two layouts — a table that fits on one plane and wraps on the other.
 *
 * Asserted on the container declaration itself rather than by rendering, because the
 * failure is a static difference between two files and this names it precisely when it
 * reappears. A rendered assertion would report "something looks wrong" instead.
 */
it('frames content identically in both console shells', function (): void {
    $shells = [
        'organization' => resource_path('views/components/layouts/app.blade.php'),
        'environment' => resource_path('views/components/layouts/environment.blade.php'),
    ];

    $containers = [];

    foreach ($shells as $plane => $path) {
        preg_match('#<main[^>]*>\s*(?:\{\{--.*?--\}\}\s*)*<div([^>]*)>#s', File::get($path), $m);

        expect($m)->not->toBeEmpty("could not find the content container in the {$plane} shell");

        // Normalise attribute order and whitespace — the assertion is about the frame the
        // reader sees, not about how the attributes happen to be typed.
        $attrs = preg_split('/\s+/', trim($m[1])) ?: [];
        sort($attrs);
        $containers[$plane] = implode(' ', $attrs);
    }

    expect($containers['environment'])->toBe(
        $containers['organization'],
        'the two console shells frame the same shared pages differently',
    );
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
it('emits the shared mobile navigation on both console planes', function (): void {
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

    $html = (string) $this->get(route('dashboard'))->assertOk()->getContent();

    // The bottom bar's own signature: pinned to the viewport foot, below `lg` only.
    expect($html)->toContain('lg:hidden fixed bottom-0')
        ->and($html)->toContain('Open navigation');
})->group('ux');
