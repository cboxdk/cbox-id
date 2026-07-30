<?php

declare(strict_types=1);

use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Devices\Contracts\PushTransport;
use Cbox\Id\Devices\Transports\NullPushTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('leaves the devices feature inactive by default', function (): void {
    expect(Console::featureActive('devices'))->toBeFalse();
});

it('activates the devices feature when enabled in config', function (): void {
    config()->set('id-devices.enabled', true);

    expect(Console::featureActive('devices'))->toBeTrue();
});

it('binds an inert transport by default so an unconfigured install sends nothing', function (): void {
    expect(app(PushTransport::class))->toBeInstanceOf(NullPushTransport::class);
});

it('appends a gated Trusted devices page to the host Sign-in area', function (): void {
    $area = collect(Console::nav()->areas())->firstWhere('key', 'authentication');

    expect($area)->not->toBeNull();

    $page = collect($area->pages())->firstWhere('route', 'devices.index');

    expect($page)->not->toBeNull()
        ->and($page->feature)->toBe('devices');
});

it('registers the Volt console route', function (): void {
    expect(Route::has('devices.index'))->toBeTrue();
});

it('creates its tables even while the feature is disabled', function (): void {
    // Migrations load unconditionally so enabling the module later is a config change
    // rather than a migration window.
    expect(Console::featureActive('devices'))->toBeFalse()
        ->and(Schema::hasTable('id_devices'))->toBeTrue()
        ->and(Schema::hasTable('id_device_notifications'))->toBeTrue();
});
