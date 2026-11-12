<?php

declare(strict_types=1);

use App\Platform\Console\EnvironmentConsolePath;
use Cbox\Id\Organization\Models\Environment;
use Illuminate\Support\Facades\Http;

/**
 * THE WAY INTO PLATFORM ADMIN, for an operator who is not already in it.
 *
 * Before the shared shell, the account menu carried "Platform admin" and the platform's own
 * environment for an operator on the root host. cbox-ui's account menu takes no links of
 * an app's own, so both went missing and an operator could only reach `/admin` by typing
 * it. They are a rail area now (under More on a phone) and palette actions, and nobody
 * else sees either.
 */
beforeEach(function (): void {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    multiTenantDeployment('cboxid.com');
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);
});

/** @return array<string, mixed>|null the rail's `platform` area */
function platformAreaOf(array $shell): ?array
{
    $areas = array_values(array_filter($shell['nav']['areas'], static fn (array $area): bool => $area['key'] === 'platform'));

    expect(count($areas))->toBeLessThanOrEqual(1);

    return $areas[0] ?? null;
}

/** @return array<string, string> the palette's operator actions, id => url */
function operatorActionsOf(array $shell): array
{
    return array_filter(
        array_column($shell['palette']['actions'], 'url', 'id'),
        static fn (string $id): bool => in_array($id, ['platform.admin', 'platform.environment'], true),
        ARRAY_FILTER_USE_KEY,
    );
}

it('gives an operator on the root host a Platform area and palette actions into platform admin and the root environment', function (): void {
    provisionAccount('owner@acme.example');
    actAsOperator('ops@platform.test');

    $shell = (array) $this->get('https://cboxid.com/settings')->assertOk()->inertiaProps('shell');
    $area = platformAreaOf($shell);
    $root = 'https://cboxid.com/'.EnvironmentConsolePath::ROOT;

    expect($area)->not->toBeNull()
        ->and($area['icon'])->toBe('rocket')
        ->and(array_column($area['pages'], 'url', 'key'))->toBe([
            'platform.orgs' => 'https://cboxid.com/admin/organizations',
            'platform.environment' => $root,
        ])
        // Not where the operator is standing: the page's own area stays lit.
        ->and($shell['nav']['activeArea'])->not->toBe('platform')
        // On a phone it is under More, not one of the four tabs.
        ->and($shell['nav']['tabs'])->not->toContain('platform')
        // …and ⌘K offers both before anything is typed.
        ->and(operatorActionsOf($shell))->toBe([
            'platform.admin' => 'https://cboxid.com/admin/organizations',
            'platform.environment' => $root,
        ]);

    // Both links lead somewhere the operator is let in.
    $this->get('https://cboxid.com/admin/organizations')->assertOk();
    $this->get($root)->assertOk();
})->group('shell');

it('adds no second Platform area on platform admin itself, where the rail already has it', function (): void {
    provisionAccount('owner@acme.example');
    actAsOperator('ops@platform.test');

    foreach (['https://cboxid.com/admin/organizations', 'https://cboxid.com/'.EnvironmentConsolePath::ROOT] as $url) {
        $shell = (array) $this->get($url)->assertOk()->inertiaProps('shell');
        $area = platformAreaOf($shell);

        expect($area)->not->toBeNull()
            // The registry's Platform area, with all its pages, not the shortcut.
            ->and(array_column($area['pages'], 'key'))->toContain('platform.orgs', 'platform.operators')
            ->and(array_column($area['pages'], 'key'))->not->toContain('platform.environment')
            ->and(operatorActionsOf($shell))->toBe([]);
    }

    $shell = (array) $this->get('https://cboxid.com/admin/organizations')->inertiaProps('shell');

    expect($shell['nav']['activeArea'])->toBe('platform');
})->group('shell');

it('shows a workspace owner who is no operator nothing new on the root host', function (): void {
    ['subjectId' => $subjectId] = provisionAccount('owner@acme.example');
    signInAsMember($subjectId);

    $shell = (array) $this->get('https://cboxid.com/settings')->assertOk()->inertiaProps('shell');

    expect(platformAreaOf($shell))->toBeNull()
        ->and(operatorActionsOf($shell))->toBe([]);
})->group('shell');

it('shows an environment administrator who is no operator nothing new in their console', function (): void {
    actAsEnvironmentAdminOfATenant();

    $shell = (array) $this->get(envConsole('/users'))->assertOk()->inertiaProps('shell');

    expect(platformAreaOf($shell))->toBeNull()
        ->and(operatorActionsOf($shell))->toBe([]);
})->group('shell');

it('follows whichever environment is the default, not a slug or an id', function (): void {
    provisionAccount('owner@acme.example');
    actAsOperator('ops@platform.test');

    $default = Environment::query()->where('is_default', true)->sole();

    // The realm cutover renames the root: the `platform` slug goes away and `cbox` is the
    // default. Nothing in the link may have named the old one.
    $default->forceFill(['slug' => 'cbox'])->save();

    $shell = (array) $this->get('https://cboxid.com/settings')->assertOk()->inertiaProps('shell');
    $url = array_column(platformAreaOf($shell)['pages'], 'url', 'key')['platform.environment'];

    expect($url)->toBe(app(EnvironmentConsolePath::class)->route((string) $default->getKey()))
        ->and($url)->not->toContain('platform')
        ->and(operatorActionsOf($shell)['platform.environment'])->toBe($url)
        ->and(app(EnvironmentConsolePath::class)->resolve(EnvironmentConsolePath::ROOT)?->getKey())->toBe($default->getKey());

    // No default environment: no link to one, and platform admin is still offered.
    Environment::query()->whereKey($default->getKey())->update(['is_default' => false]);

    $shell = (array) $this->get('https://cboxid.com/settings')->inertiaProps('shell');

    expect(array_column(platformAreaOf($shell)['pages'] ?? [], 'key'))->toBe(['platform.orgs'])
        ->and(operatorActionsOf($shell))->toHaveKey('platform.admin')
        ->and(operatorActionsOf($shell))->not->toHaveKey('platform.environment');
})->group('shell');
