<?php

declare(strict_types=1);

use Cbox\Console\Kit\ConsoleManager;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Compliance\Contracts\AuditExportSink;
use Cbox\Id\Compliance\Testing\FakeAuditExportSink;
use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    expect($routes)->toContain('audit', 'compliance.audit', 'compliance.exports');

    $pages = collect($area->pages())->keyBy('route');
    expect($pages['compliance.audit']->feature)->toBe('compliance')
        ->and($pages['compliance.exports']->feature)->toBe('compliance')
        ->and($pages['compliance.exports']->label)->toBe('Exports & retention');
});

it('registers the Volt console routes', function (): void {
    expect(Route::has('compliance.audit'))->toBeTrue()
        ->and(Route::has('compliance.exports'))->toBeTrue();
});

it('renders a dashboard export card', function (): void {
    // AS AN ADMINISTRATOR OF AN ORGANIZATION, which this test used not to be. Every card
    // now narrows to the acting organization and renders NOTHING when there is none —
    // they used to read the whole environment, so they rendered for a caller with no
    // console scope at all, and that is exactly what this assertion was measuring.
    actingAsRole(MembershipRole::Owner);

    $html = Console::slots()->render(ConsoleManager::DASHBOARD_CARDS);

    expect($html)->toContain('Audit export')->toContain('pending');
});
