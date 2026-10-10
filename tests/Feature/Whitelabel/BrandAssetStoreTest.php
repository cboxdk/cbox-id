<?php

declare(strict_types=1);

use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Whitelabel\Assets\BrandAssetStore;
use Cbox\Id\Whitelabel\Assets\DatabaseBrandAssetStore;
use Cbox\Id\Whitelabel\Contracts\BrandProfiles;
use Cbox\Id\Whitelabel\Models\BrandAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

/**
 * AN UPLOADED LOGO IS AN IMAGE EVERY REPLICA CAN SERVE.
 *
 * The default store wrote to the `public` disk and handed out `APP_URL/storage/…`. On the
 * production image that URL answered 404 every time — nothing runs `storage:link` — and
 * with two web replicas, each with its own disk and both replaced on every deploy, a
 * linked disk would only have made it 404 half the time and forget everything on the next
 * rollout. Found by uploading a logo on a production-shaped stack (two replicas behind a
 * TLS proxy) and asking for the URL the page then rendered.
 *
 * The database is what every replica shares, so that is where the image goes now.
 */
it('stores an uploaded logo in the database and serves it from the URL it hands out', function (): void {
    config(['console.features.whitelabel' => true]);

    [, $org] = actingAsRole(MembershipRole::Owner);

    expect(app(BrandAssetStore::class))->toBeInstanceOf(DatabaseBrandAssetStore::class);

    // Uploaded the way the Appearance editor sends it — the bytes as a data URI, through
    // the `branding.appearance.set` action the API and MCP run too.
    $logo = pngDataUri(40, 40);
    $bytes = (string) base64_decode(substr($logo, strlen('data:image/png;base64,')), true);

    saveThemeImages(['logo' => $logo])->assertSessionHasNoErrors();

    $url = app(BrandProfiles::class)->forOrganization($org->id)?->logo_url;

    expect($url)->toBeString()->toContain('/brand-assets/brand/');

    // No session for an image: asked for the way an anonymous visitor's browser asks.
    forgetSubjectSession();

    $this->get((string) $url)
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public')
        ->assertCookieMissing((string) config('session.cookie'))
        ->assertContent($bytes);
});

it('replaces the previous logo rather than keeping every upload', function (): void {
    config(['console.features.whitelabel' => true]);

    [, $org] = actingAsRole(MembershipRole::Owner);

    saveThemeImages(['logo' => pngDataUri(40, 20)])->assertSessionHasNoErrors();
    $first = app(BrandProfiles::class)->forOrganization($org->id)?->logo_url;

    saveThemeImages(['logo' => pngDataUri(60, 20)])->assertSessionHasNoErrors();
    $second = app(BrandProfiles::class)->forOrganization($org->id)?->logo_url;

    expect($second)->not->toBe($first)
        ->and(BrandAsset::query()->count())->toBe(1);

    $this->get((string) $first)->assertNotFound();
    $this->get((string) $second)->assertOk();
});

/** The rule the disk store learned: one environment never removes another's asset. */
it('refuses to forget a brand asset belonging to another environment', function (): void {
    $context = app(EnvironmentContext::class);
    $store = app(DatabaseBrandAssetStore::class);

    $victimUrl = $context->runAs(
        GenericEnvironment::of('env_victim'),
        fn (): string => $store->put('logo', UploadedFile::fake()->image('victim.png')),
    );

    $context->runAs(GenericEnvironment::of('env_attacker'), fn () => $store->forget($victimUrl));

    expect(BrandAsset::query()->count())->toBe(1, 'one environment deleted another environment brand asset');

    $context->runAs(GenericEnvironment::of('env_victim'), fn () => $store->forget($victimUrl));

    expect(BrandAsset::query()->count())->toBe(0);
});

it('serves nothing for a path it did not store', function (): void {
    $this->get('/brand-assets/brand/env_x/logo-0123456789abcdef.png')->assertNotFound();
    $this->get('/brand-assets/brand/../../.env')->assertNotFound();
});
