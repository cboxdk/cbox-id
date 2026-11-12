<?php

declare(strict_types=1);

use App\Platform\AdminSignInLink;
use App\Platform\Console\EnvironmentConsolePath;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The discreet "I'm an administrator" link on an environment's sign-in page: on under the
 * tenant base domain, off on a custom domain, and the environment's to switch either way.
 *
 * @return array{environment: Environment, ownerId: string, console: string}
 */
function aslTenant(): array
{
    multiTenantDeployment('app.cboxid.com');
    config(['cbox-id.environments.base_domains' => ['cboxauth.com', 'cboxid.com']]);

    $tenant = provisionAccount();
    $environment = $tenant['environment']->refresh();

    return [
        'environment' => $environment,
        'ownerId' => $tenant['subjectId'],
        'console' => 'https://app.cboxid.com/'.app(EnvironmentConsolePath::class)->for($environment->id),
    ];
}

function aslAdminUrl(string $host): mixed
{
    return test()->get('https://'.$host.'/login')->assertOk()->inertiaProps('adminUrl');
}

it('shows the link on the tenant base domain by default, leading to the console on the app host', function (): void {
    ['environment' => $environment, 'console' => $console] = aslTenant();

    expect(aslAdminUrl($environment->slug.'.cboxauth.com'))->toBe($console);
});

it('hides the link on a custom domain by default', function (): void {
    ['environment' => $environment] = aslTenant();
    $environment->forceFill(['domain' => 'id.acme.example', 'domain_verified_at' => now()])->save();

    expect(aslAdminUrl('id.acme.example'))->toBeNull();
});

it('follows the environment\'s choice on every host once it is made', function (): void {
    ['environment' => $environment, 'console' => $console] = aslTenant();
    $environment->forceFill(['domain' => 'id.acme.example', 'domain_verified_at' => now()])->save();

    app(AdminSignInLink::class)->set($environment, true);
    expect(aslAdminUrl('id.acme.example'))->toBe($console);

    app(AdminSignInLink::class)->set($environment->refresh(), false);
    expect(aslAdminUrl('id.acme.example'))->toBeNull()
        ->and(AdminSignInLink::shownFor($environment->refresh(), $environment->slug.'.cboxauth.com'))->toBeFalse();
});

it('has no link where there is no console on another host', function (): void {
    // The suite's pinned single-tenant shape.
    platformRootEnvironment();

    expect(test()->get(route('login'))->assertOk()->inertiaProps('adminUrl'))->toBeNull();
});

it('defaults to off for the platform\'s own environment', function (): void {
    aslTenant();
    $root = Environment::query()->where('is_default', true)->sole();

    expect(AdminSignInLink::defaultFor($root, $root->slug.'.cboxid.com'))->toBeFalse();
});

it('is switched in the environment console and by the API, and recorded', function (): void {
    ['environment' => $environment, 'ownerId' => $ownerId] = aslTenant();

    actAsEnvironmentAdmin($ownerId, $environment->id);

    $setting = test()->get(route('environment.auth-policy'))->assertOk()->inertiaProps('adminSignInLink');

    expect($setting['enabled'])->toBeTrue()
        ->and($setting['chosen'])->toBeFalse()
        ->and($setting['baseDomain'])->toBe('cboxauth.com');

    test()->from(route('environment.auth-policy'))
        ->put(route('environment.auth-policy.admin-link'), ['enabled' => false])
        ->assertSessionHasNoErrors();

    expect(AdminSignInLink::chosenFor($environment->refresh()))->toBeFalse();

    $key = app(EnvironmentApiKeys::class)->issue($environment->id, 'Sign-in worker', ['signin:write'])->plaintext;

    test()->withToken($key)->putJson('https://'.$environment->slug.'.cboxauth.com/api/v1/sign-in/admin-link', ['enabled' => true])
        ->assertOk()
        ->assertJsonPath('data.enabled', true);

    expect(AdminSignInLink::chosenFor($environment->refresh()))->toBeTrue();

    $recorded = app(PlatformRoot::class)->run(fn (): array => AuditEntry::query()
        ->whereIn('action', ['environment.admin_sign_in_link_enabled', 'environment.admin_sign_in_link_disabled'])
        ->orderBy('id')
        ->pluck('action')
        ->all());

    expect($recorded)->toBe(['environment.admin_sign_in_link_disabled', 'environment.admin_sign_in_link_enabled']);
});

it('says "I\'m an administrator" in every language the sign-in page speaks', function (): void {
    foreach (glob(lang_path('*/auth.php')) ?: [] as $file) {
        $messages = require $file;

        expect($messages['login']['admin_link'] ?? null)->toBeString()->not->toBe('');
    }
});
