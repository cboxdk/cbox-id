<?php

declare(strict_types=1);

use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Whitelabel\Assets\LocalBrandAssetStore;
use Cbox\Id\Whitelabel\Contracts\BrandProfiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/**
 * An organization admin brands their own organization, and nobody else's.
 *
 * The page is reached behind an ORG-level role, and it read and wrote
 * `forEnvironment()` — the `organization_id IS NULL` row, which is the fallback every
 * organization in the environment inherits when it has none of its own. So an admin of
 * one tenant re-branded the console and the hosted sign-in page for every other tenant
 * in that environment, none of whom share any trust relationship with them. The
 * resolver already prefers an organization's own profile, so the environment default is
 * an environment administrator's to set.
 */
it('writes branding to the admin own organization, not the environment default', function (): void {
    config(['console.features.whitelabel' => true]);

    Storage::fake('public');

    [, $org] = actingAsRole(MembershipRole::Owner);

    $component = Volt::test('whitelabel.branding')
        ->set('appName', 'Acme Identity')
        ->call('save');

    $component->assertHasNoErrors();

    $profiles = app(BrandProfiles::class);

    // Positive control first: without it, a page that silently does nothing would
    // satisfy every "did not write the environment default" assertion below.
    expect($profiles->forOrganization($org->id)?->app_name)
        ->toBe('Acme Identity', 'the branding page wrote nothing at all');

    expect($profiles->forEnvironment()?->app_name)
        ->not->toBe('Acme Identity', 'an org admin rewrote the branding every other tenant inherits');
});

/**
 * And the environment's custom domain is not theirs to touch.
 *
 * Those controls used to live on this page, behind the same org-level role: an org admin
 * could take sign-in down at the environment's vanity host for every tenant on it, or
 * point it somewhere of their choosing. The account plane already owns domains, scoped
 * to environments the member can reach and verified by a DNS TXT record.
 */
it('does not expose environment domain controls to an organization admin', function (): void {
    $markup = (string) file_get_contents(
        __DIR__.'/../../../modules/whitelabel/resources/views/livewire/whitelabel/branding.blade.php'
    );

    expect($markup)->not->toContain('ManageCustomDomain')
        ->and($markup)->not->toContain('wire:click="clearDomain"')
        ->and($markup)->not->toContain('wire:submit="saveDomain"');
});

/**
 * One environment must not be able to delete another environment's brand asset.
 *
 * `AssetPath` refuses traversal and anchors on the base folder — but the base is
 * `brand/`, while writes land in `brand/{environment}/`. So a URL naming a different
 * environment's asset resolved cleanly and was deleted on the next upload. Reachable in
 * two requests: read a victim environment's logo URL off its own public sign-in page,
 * set the page's `logoUrl` to it, save, then upload a legitimate file.
 */
it('refuses to delete a brand asset belonging to another environment', function (): void {
    $disk = Storage::fake('public');
    $context = app(EnvironmentContext::class);

    $store = new LocalBrandAssetStore($disk, $context, 'brand');

    // A victim environment's asset, in its own folder.
    $disk->put('brand/env_victim/logo.png', 'victim bytes');

    $context->runAs(GenericEnvironment::of('env_attacker'), function () use ($store): void {
        $store->forget('/storage/brand/env_victim/logo.png');
    });

    expect($disk->exists('brand/env_victim/logo.png'))
        ->toBeTrue('one environment deleted another environment brand asset');

    // Positive control: it still forgets its own.
    $disk->put('brand/env_attacker/logo.png', 'own bytes');

    $context->runAs(GenericEnvironment::of('env_attacker'), function () use ($store): void {
        $store->forget('/storage/brand/env_attacker/logo.png');
    });

    expect($disk->exists('brand/env_attacker/logo.png'))->toBeFalse();
});
