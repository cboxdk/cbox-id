<?php

declare(strict_types=1);

use Cbox\Console\Kit\Branding\Branding;
use Cbox\Console\Kit\Contracts\BrandingResolver;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Whitelabel\Branding\TenantBrandingResolver;
use Cbox\Id\Whitelabel\Contracts\BrandProfiles;
use Cbox\Id\Whitelabel\Models\BrandProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Cbox\Id\Kernel\Tenancy\Testing\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class);

it('overrides console-kit\'s null resolver with the tenant one', function (): void {
    expect(app(BrandingResolver::class))->toBeInstanceOf(TenantBrandingResolver::class);
});

it('maps a BrandProfile to the correct token map and style tag', function (): void {
    $this->actingAsEnvironment('env_a');

    BrandProfile::create([
        'palette' => ['primary' => '#0a2540', 'ring' => 'oklch(0.45 0.16 258)'],
        'app_name' => 'Acme ID',
        'logo_url' => '/brand/acme/logo.svg',
    ]);

    $branding = app(BrandingResolver::class)->resolve();

    expect($branding)->toBeInstanceOf(Branding::class)
        ->and($branding->tokens())->toBe([
            '--primary' => '#0a2540',
            '--ring' => 'oklch(0.45 0.16 258)',
        ])
        ->and($branding->appName)->toBe('Acme ID')
        ->and($branding->logoUrl)->toBe('/brand/acme/logo.svg')
        ->and($branding->styleTag())->toBe('<style>:root{--primary:#0a2540;--ring:oklch(0.45 0.16 258)}</style>');
});

it('is inert (empty branding) when no environment is in context', function (): void {
    $this->app->make(EnvironmentContext::class)->set(null);

    expect(app(BrandingResolver::class)->resolve()->isEmpty())->toBeTrue();
});

it('is inert when the environment has no profile', function (): void {
    $this->actingAsEnvironment('env_a');

    expect(app(BrandingResolver::class)->resolve()->isEmpty())->toBeTrue();
});

it('keeps a profile invisible across environments (BelongsToEnvironment isolation)', function (): void {
    $this->actingAsEnvironment('env_a');
    BrandProfile::create(['palette' => ['primary' => '#0a2540'], 'app_name' => 'Acme ID']);

    // Same request, different environment: the profile must not resolve.
    $this->actingAsEnvironment('env_b');

    expect(app(BrandProfiles::class)->forEnvironment())->toBeNull()
        ->and(app(BrandingResolver::class)->resolve()->isEmpty())->toBeTrue();

    // Back in env A it is visible again.
    $this->actingAsEnvironment('env_a');
    expect(app(BrandProfiles::class)->forEnvironment())->not->toBeNull();
});
