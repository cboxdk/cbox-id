<?php

declare(strict_types=1);

use App\Http\Controllers\MovedPageController;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Models\Environment;
use Illuminate\Routing\Route;

/*
|--------------------------------------------------------------------------
| EVERY OLD SPELLING OF A PAGE LANDS ON A PAGE, ON EVERY HOST
|--------------------------------------------------------------------------
|
| The console's moved pages (`/admin/applications` → `/admin/apps`, `/clients` → `/apps`,
| …) were registered with no gate of their own, so they answered on every host — while
| the pages they point at do not. On cboxid.com, the platform root, there is no `/admin`
| at all (it is the environment console, `plane:environment`), so 24 old addresses
| answered `301 → /admin/… → 404` in production: a redirect whose only effect was to
| move the 404 somewhere else.
|
| An old address now carries the same EXISTENCE gates as the family it moved into, so it
| answers on exactly the hosts its destination does, and 404s itself everywhere else.
| This follows every registered one, on the platform root, on an environment's host and
| on a single-tenant install, as a visitor with no session: the destination must answer
| with something other than a 404 — the page, or the sign-in it sends a visitor to.
*/

/**
 * Every registered moved page, as [old path, new path] with each `{parameter}` filled.
 *
 * @return list<array{0: string, 1: string}>
 */
function movedPages(): array
{
    $moved = [];

    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        /** @var Route $route */
        if ($route->getAction('controller') !== MovedPageController::class) {
            continue;
        }

        $fill = static fn (string $path): string => (string) preg_replace('/\{[^}]+\}/', '01JMOVEDPAGEPROBE0000000000', $path);

        $moved[] = ['/'.ltrim($fill($route->uri()), '/'), $fill((string) $route->defaults['to'])];
    }

    return $moved;
}

/**
 * Follow each moved page on $host and return the ones whose destination 404s (or errors).
 *
 * @return list<string>
 */
function deadMovedPages(string $host): array
{
    $dead = [];

    foreach (movedPages() as [$from, $to]) {
        $legacy = test()->get('https://'.$host.$from);

        if ($legacy->status() === 404) {
            // Not served on this host — which is right exactly when its destination isn't.
            continue;
        }

        if ($legacy->status() !== 301) {
            $dead[] = "{$host}{$from} → {$legacy->status()} (expected a 301 or a 404)";

            continue;
        }

        $location = (string) $legacy->headers->get('Location');
        $target = test()->get($location);

        if ($target->status() === 404 || $target->status() >= 500) {
            $dead[] = "{$host}{$from} → {$location} → {$target->status()}";
        }
    }

    return $dead;
}

beforeEach(function (): void {
    installedDeployment();
});

it('finds the moved pages it follows', function (): void {
    $from = array_map(static fn (array $pair): string => $pair[0], movedPages());

    expect($from)->toContain('/admin/applications')
        ->toContain('/clients')
        ->toContain('/platform/customers');
});

it('lands every moved page on a page that exists, on the platform root', function (): void {
    multiTenantDeployment();
    config()->set('cbox-id.environments.base_domains', ['cboxid.com']);
    platformRootEnvironment();

    expect(deadMovedPages('cboxid.com'))->toBe([]);

    // The console's own old addresses still redirect on the root, where its pages are.
    $this->get('https://cboxid.com/clients')->assertStatus(301)->assertRedirect('/apps');
});

it('lands every moved page on a page that exists, on an environment\'s host', function (): void {
    multiTenantDeployment();
    config()->set('cbox-id.environments.base_domains', ['cboxid.com']);
    platformRootEnvironment();

    Environment::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'type' => EnvironmentType::Production,
        'status' => EnvironmentStatus::Active,
        'is_default' => false,
        'settings' => [],
    ]);

    expect(deadMovedPages('acme.cboxid.com'))->toBe([]);

    // Not vacuous: here, where the environment console lives, the old address does redirect.
    $this->get('https://acme.cboxid.com/admin/applications')->assertStatus(301)->assertRedirect('/admin/apps');
});

it('lands every moved page on a page that exists, on a single-tenant install', function (): void {
    platformRootEnvironment();

    expect(deadMovedPages((string) parse_url((string) config('app.url'), PHP_URL_HOST)))->toBe([]);
});

it('does not serve the environment console\'s old addresses where there is no environment console', function (): void {
    multiTenantDeployment();
    config()->set('cbox-id.environments.base_domains', ['cboxid.com']);
    platformRootEnvironment();

    $this->get('https://cboxid.com/admin/applications')->assertNotFound();
    $this->get('https://cboxid.com/admin/applications/01JMOVEDPAGEPROBE0000000000')->assertNotFound();
});
