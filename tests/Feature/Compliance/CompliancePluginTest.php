<?php

declare(strict_types=1);

use App\Platform\Console\DashboardCards;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Compliance\Contracts\AuditExportSink;
use Cbox\Id\Compliance\Testing\FakeAuditExportSink;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

it('leaves the compliance feature inactive with the inert null sink and disabled config', function (): void {
    expect(Console::featureActive('compliance'))->toBeFalse();
});

it('activates the compliance feature once a real export sink is bound', function (): void {
    app()->instance(AuditExportSink::class, new FakeAuditExportSink);

    expect(Console::featureActive('compliance'))->toBeTrue();
});

it('activates the compliance feature when explicitly enabled without a sink', function (): void {
    config()->set('compliance.enabled', true);

    expect(Console::featureActive('compliance'))->toBeTrue();
});

it('adds its pages to the host Logs area, beside the activity log they extend', function (): void {
    // Two areas for the audit trail meant an admin looking for "the log" had to guess.
    expect(collect(Console::nav()->areas())->firstWhere('key', 'compliance'))->toBeNull();

    $area = collect(Console::nav()->areas())->firstWhere('key', 'audit');
    $routes = collect($area->pages())->pluck('route')->all();

    expect($routes)->toContain('audit', 'compliance.audit', 'compliance.data-exports');

    $pages = collect($area->pages())->keyBy('route');
    expect($pages['compliance.audit']->feature)->toBe('compliance')
        ->and($pages['compliance.data-exports']->feature)->toBe('compliance')
        ->and($pages['compliance.data-exports']->label)->toBe('Exports & retention');
});

it('registers the Volt console routes', function (): void {
    expect(Route::has('compliance.audit'))->toBeTrue()
        ->and(Route::has('compliance.data-exports'))->toBeTrue();
});

it('renders a dashboard export card', function (): void {
    // AS AN ADMINISTRATOR OF AN ORGANIZATION, which this test used not to be. Every card
    // now narrows to the acting organization and renders NOTHING when there is none —
    // they used to read the whole environment, so they rendered for a caller with no
    // console scope at all, and that is exactly what this assertion was measuring.
    actingAsRole(MembershipRole::Owner);

    // THE CARD AS DATA, not as a rendered string.
    $card = collect(app(DashboardCards::class)->resolve())->firstWhere('key', 'compliance.exports');

    expect($card)->not->toBeNull()
        ->and($card->label)->toBe('Audit export')
        ->and($card->value)->toContain('pending');
});

/**
 * THE AUDIT PAGE RE-HASHED THE ENTIRE CHAIN ON EVERY KEYSTROKE.
 *
 * `verifyChain()` reads every row in its range and re-hashes each one in PHP, and the call
 * sat in `with()` — which Livewire re-runs on every action and on every debounced keystroke
 * of the two filter inputs. On a tenant with a few hundred thousand entries, which is
 * months of ordinary sign-ins, the page took tens of seconds and then died on the memory
 * limit, and typing made it worse.
 *
 * It now verifies the tail by default and the whole chain only when asked. What a short
 * window cannot see — entries deleted off the end — is what `verifyChain()` cross-checks
 * against the last signed checkpoint on every call regardless of range, so the cheap answer
 * is a narrower true rather than a weaker one.
 */
it('verifies a window of the audit chain, not all of history', function (): void {
    // The module's routes only exist where its feature is on, and this drives them by
    // request. Said here rather than in a `beforeEach`, because the first test in this file
    // is precisely the one that asserts the feature is OFF by default.
    config(['compliance.enabled' => true]);

    [$actor, $org] = actingAsRole(MembershipRole::Owner);

    $log = app(AuditLog::class);

    foreach (range(1, 60) as $i) {
        $log->record(new AuditEvent(action: 'test.entry.'.$i, organizationId: $org->id));
    }

    $rows = 0;
    DB::listen(function ($query) use (&$rows): void {
        if (str_contains($query->sql, 'audit_logs')) {
            $rows++;
        }
    });

    // The badge is honest about being a window rather than claiming the whole chain — the
    // page draws its sentence from this flag and from nothing else.
    $windowed = (array) test()->get(route('compliance.audit'))->assertOk()->inertiaProps('verification');

    expect($windowed['whole'])->toBeFalse()
        ->and($windowed['valid'])->toBeTrue();

    // And asking for everything is a deliberate act — a request of its own, not something
    // the page does to itself while somebody types in a filter box.
    $whole = (array) test()->get(route('compliance.audit', ['verifyAll' => 1]))->assertOk()->inertiaProps('verification');

    expect($whole['whole'])->toBeTrue()
        ->and($whole['count'])->toBeGreaterThan($windowed['count'] - 1);

    expect($rows)->toBeGreaterThan(0, 'the page read no audit rows at all — the assertion above is about an empty page');
});
