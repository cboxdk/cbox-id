<?php

declare(strict_types=1);

use Cbox\Console\Kit\ConsoleManager;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Compliance\Contracts\AuditExportSink;
use Cbox\Id\Compliance\Testing\FakeAuditExportSink;
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

it('adds a gated Compliance nav area with Audit trail and Exports pages', function (): void {
    $area = collect(Console::nav()->areas())->firstWhere('key', 'compliance');

    expect($area)->not->toBeNull();

    $pages = $area->pages();
    expect($pages[0]->route)->toBe('compliance.audit')
        ->and($pages[0]->feature)->toBe('compliance')
        ->and($pages[1]->route)->toBe('compliance.exports')
        ->and($pages[1]->feature)->toBe('compliance');
});

it('registers the Volt console routes', function (): void {
    expect(Route::has('compliance.audit'))->toBeTrue()
        ->and(Route::has('compliance.exports'))->toBeTrue();
});

it('renders a dashboard export card', function (): void {
    $html = Console::slots()->render(ConsoleManager::DASHBOARD_CARDS);

    expect($html)->toContain('Audit export')->toContain('pending');
});
