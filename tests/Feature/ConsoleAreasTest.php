<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * Invariants of the console's information architecture. All three were broken in
 * shipped code, and none is the kind of thing anyone notices reading a diff.
 */
it('gives every nav area a unique order', function (): void {
    // DefaultNavRegistry::areas() sorts on `order` alone, so a tie resolves by service
    // provider boot order: the rail silently reshuffles when a module is enabled or
    // disabled. The console shipped Logs/Security both at 60 and Settings/Connectors
    // both at 70.
    $orders = collect(Console::nav()->areas())->map(fn ($area): int => $area->order);

    expect($orders->duplicates()->values()->all())->toBe([]);
});

it('never puts two rail areas on the same subject', function (): void {
    $keys = collect(Console::nav()->areas())->pluck('key')->all();

    // Analytics belongs beside Overview › Usage, compliance's audit pages beside the
    // activity log, and risk events in Logs — not each in an area of its own. A rail
    // with one area per module asks the reader to learn our module boundaries.
    expect($keys)->not->toContain('analytics')
        ->and($keys)->not->toContain('compliance')
        ->and($keys)->not->toContain('security');
});

/**
 * The promise a nav label makes: click "Token vault" and you land on a page headed
 * "Token vault". Six host pages and two plugin pages broke it. Asserted over the
 * rendered document rather than the source, so it holds for any page a module adds
 * later without that module knowing this test exists.
 */
it('lands every nav entry on a page titled the way the entry is labelled', function (): void {
    $subject = app(Subjects::class)->create('nav@acme.test', 'Nav Admin', 'super-secret-1234');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-areas'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);

    // Behind the sudo step-up gate: it redirects, so there is no title to read.
    $behindStepUp = ['vault'];

    $checked = 0;

    foreach (Console::nav()->areas() as $area) {
        foreach ($area->pages() as $page) {
            if (in_array($page->route, $behindStepUp, true) || ! Route::has($page->route)) {
                continue;
            }

            $response = $this->get(route($page->route));

            // A page gated off in this environment (inactive module, missing
            // entitlement) is not a naming failure — only a reachable page is checked.
            if ($response->status() !== 200) {
                continue;
            }

            expect($response->getContent())->toContain('<title>'.e($page->label).' · ');
            $checked++;
        }
    }

    // Guard against the loop silently checking nothing: a broken session would 302
    // every page and turn this into a test that always passes.
    expect($checked)->toBeGreaterThanOrEqual(12);
});
