<?php

declare(strict_types=1);

use App\SignalsData\SourceEnvironments;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Models\Environment;
use Illuminate\Support\Str;

function sourceEnvironment(EnvironmentStatus $status): string
{
    return Environment::query()->create([
        'name' => 'Source '.$status->value,
        'slug' => 'source-'.Str::lower((string) Str::ulid()),
        'type' => EnvironmentType::Production,
        'status' => $status,
        'is_default' => false,
        'settings' => [],
    ])->id;
}

it('captures only listed environments by default', function (): void {
    $listed = sourceEnvironment(EnvironmentStatus::Active);
    $other = sourceEnvironment(EnvironmentStatus::Active);
    config(['signals-data.environments' => [$listed]]);

    expect(SourceEnvironments::includes($listed))->toBeTrue()
        ->and(SourceEnvironments::includes($other))->toBeFalse()
        ->and(SourceEnvironments::listed())->toBe([$listed])
        ->and(SourceEnvironments::enabled())->toBeTrue();
});

it('captures every active environment with the wildcard, including new ones', function (): void {
    $active = sourceEnvironment(EnvironmentStatus::Active);
    $inactive = sourceEnvironment(EnvironmentStatus::Suspended);
    config(['signals-data.environments' => ['*']]);

    expect(SourceEnvironments::includes($active))->toBeTrue()
        ->and(SourceEnvironments::includes($inactive))->toBeFalse()
        ->and(in_array($active, SourceEnvironments::listed(), true))->toBeTrue()
        ->and(in_array($inactive, SourceEnvironments::listed(), true))->toBeFalse()
        ->and(in_array('*', SourceEnvironments::listed(), true))->toBeFalse();

    $later = sourceEnvironment(EnvironmentStatus::Active);
    expect(SourceEnvironments::includes($later))->toBeTrue();
});

it('is disabled when nothing is listed', function (): void {
    config(['signals-data.environments' => []]);

    expect(SourceEnvironments::enabled())->toBeFalse()->and(SourceEnvironments::listed())->toBe([]);
});
