<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleSynonyms;
use App\Platform\Navigation\ConsoleNavigation;
use Cbox\Console\Kit\Facades\Console;
use Database\Seeders\DemoEnvironmentSeeder;

/**
 * ⌘K FINDS A PAGE BY THE WORD PEOPLE TYPE — see {@see ConsoleSynonyms}.
 *
 * Pinned as searches, because that is how the list fails: a page renamed or moved, its
 * key left behind, and "SCIM" quietly finding nothing again.
 */

/**
 * Every page route either rail declares, without the environment plane's prefix — module
 * pages included: a module's pages are declared whether or not it is switched on, and the
 * shell drops a switched-off one at render.
 *
 * @return list<string>
 */
function synonymPageKeys(): array
{
    $keys = [];

    foreach (app(ConsoleNavigation::class)->environment()->areas as $area) {
        foreach ($area->pages as $page) {
            $keys[] = substr($page->route, strlen('environment.'));
        }
    }

    foreach (Console::nav()->areas() as $area) {
        foreach ($area->pages() as $page) {
            $keys[] = $page->route;
        }
    }

    return array_values(array_unique($keys));
}

it('names only pages one of the rails has', function (): void {
    $pages = synonymPageKeys();

    $stale = array_values(array_filter(
        ConsoleSynonyms::keys(),
        static fn (string $key): bool => ! in_array($key, $pages, true),
    ));

    expect($stale)->toBe([], 'synonyms for pages no rail has: '.implode(', ', $stale));
});

it('finds the page a new administrator means by the word they type', function (string $typed, string $route): void {
    $matching = array_values(array_filter(
        ConsoleSynonyms::keys(),
        static fn (string $key): bool => in_array(mb_strtolower($typed), array_map(mb_strtolower(...), ConsoleSynonyms::for($key)), true),
    ));

    expect($matching)->toContain($route);
})->with([
    'SAML is inbound SSO' => ['SAML', 'connections'],
    'OIDC is inbound SSO' => ['OIDC', 'connections'],
    'Okta' => ['Okta', 'connections'],
    'Google login' => ['Google login', 'social-providers'],
    'GitHub login' => ['GitHub login', 'social-providers'],
    'SCIM' => ['SCIM', 'directories'],
    'an HR system' => ['HR system', 'directories'],
    'Workday' => ['Workday', 'directories'],
    'tenant' => ['tenant', 'organizations'],
    'RBAC' => ['RBAC', 'roles'],
    'ReBAC' => ['ReBAC', 'fga'],
    'passkeys' => ['passkeys', 'sign-in-methods'],
    'magic link' => ['magic link', 'sign-in-methods'],
    'MFA' => ['MFA', 'auth-policy'],
    'domain verification' => ['domain verification', 'domains'],
    'redirect URI' => ['redirect URI', 'clients'],
    'MCP' => ['MCP', 'agent-connect'],
    'logo' => ['logo', 'appearance'],
    'SIEM' => ['SIEM', 'audit-streams'],
]);

it('keeps the outbound SAML page off the word "SAML", which nine readers in ten mean inbound', function (): void {
    expect(array_map(mb_strtolower(...), ConsoleSynonyms::for('environment.sso-providers')))->not->toContain('saml');
});

it('hands every page its words in the shell, on the environment console', function (): void {
    $world = (new DemoEnvironmentSeeder);
    $world->run();
    $environment = $world->environment;
    expect($environment)->not->toBeNull();

    multiTenantDeployment();
    serveOnTestHost($environment);
    actAsEnvironmentAdmin($world->ownerId, $environment->id);

    $shell = (array) $this->get(route('environment.home'))->assertOk()->inertiaProps('shell');

    $pages = collect($shell['areas'])->flatMap(fn (array $area): array => $area['pages'])->keyBy('route');

    expect($pages['environment.connections']['keywords'])->toContain('SAML')
        ->and($pages['environment.organizations']['keywords'])->toContain('tenant')
        ->and($pages['environment.sign-in-methods']['keywords'])->toContain('passkeys');
});
