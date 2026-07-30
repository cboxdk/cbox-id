<?php

declare(strict_types=1);

use Cbox\Id\Devices\Enums\DevicePlatform;
use Cbox\Id\Devices\Enums\DeviceStatus;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Exceptions\CrossEnvironmentAccess;
use Cbox\Id\Kernel\Tenancy\Testing\InteractsWithTenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class, InteractsWithTenancy::class);

function enrolDevice(string $subjectId, string $name): Device
{
    return Device::query()->create([
        'subject_id' => $subjectId,
        'install_id' => (string) Str::ulid(),
        'platform' => DevicePlatform::Ios,
        'name' => $name,
        'status' => DeviceStatus::Active,
        'token_encrypted' => 'sealed-token',
    ]);
}

it('never shows one environment the devices of another', function (): void {
    $this->actingAsEnvironment('env_a');
    enrolDevice('user_1', "A's iPhone");

    $this->actingAsEnvironment('env_b');
    enrolDevice('user_2', "B's Pixel");

    expect(Device::query()->pluck('name')->all())->toBe(["B's Pixel"]);

    $this->actingAsEnvironment('env_a');

    expect(Device::query()->pluck('name')->all())->toBe(["A's iPhone"]);
});

it('returns nothing rather than everything when no environment is in context', function (): void {
    $this->actingAsEnvironment('env_a');
    enrolDevice('user_1', "A's iPhone");

    app(EnvironmentContext::class)->set(null);

    // Deny-by-default: the global scope resolves to `1 = 0`, not to an unfiltered read.
    expect(Device::query()->count())->toBe(0);
});

it('stamps the acting environment onto a device on write', function (): void {
    $this->actingAsEnvironment('env_a');

    $device = enrolDevice('user_1', "A's iPhone");

    expect($device->environment_id)->toBe('env_a');
});

it('refuses to write a device into a different environment than the acting one', function (): void {
    $this->actingAsEnvironment('env_a');

    expect(fn () => Device::query()->create([
        'environment_id' => 'env_b',
        'subject_id' => 'user_1',
        'install_id' => (string) Str::ulid(),
        'platform' => DevicePlatform::Ios,
        'name' => 'Smuggled',
        'status' => DeviceStatus::Active,
    ]))->toThrow(CrossEnvironmentAccess::class);
});
