<?php

declare(strict_types=1);

use Cbox\Id\Organization\Models\Environment;
use Illuminate\Routing\Route;
use Tests\Support\ConsoleCrawl;
use Tests\Support\ProductionShape;

/*
|--------------------------------------------------------------------------
| THE PLATFORM ROOT'S ENVIRONMENT HAS EVERY PAGE A TENANT'S HAS
|--------------------------------------------------------------------------
|
| The owner's requirement, as a test that cannot go stale: every page of the environment
| console a tenant's administrator reaches at `{tenant}.cboxid.com/admin/…` is reachable
| for the platform root's environment at `cboxid.com/admin/…`, by a platform operator — the
| same route, the same answer. Enumerated from the route table, so a page added to the
| environment console tomorrow is held to it without anybody adding a line here.
|
| Production shape: routes cached, the trusted-host list enforced.
*/

afterEach(fn () => ProductionShape::reset());

/** Every environment-console page: the `environment.*` GET routes under `/admin`. */
function environmentConsolePage(Route $route): bool
{
    return str_starts_with((string) $route->getName(), 'environment.') && str_starts_with($route->uri(), 'admin');
}

it('answers every environment-console page for the root environment exactly as for a tenant\'s', function (): void {
    $world = ConsoleCrawl::world();
    $tenant = $world->environment;
    $root = Environment::query()->where('is_default', true)->sole();
    expect($tenant)->not->toBeNull();

    // The tenant's console, as its workspace's owner.
    actAsEnvironmentAdmin($world->ownerId, (string) $tenant?->id);
    ConsoleCrawl::productionShape();

    $onTenant = (new ConsoleCrawl($this))->answers(
        host: $tenant?->slug.'.'.ConsoleCrawl::ROOT,
        select: environmentConsolePage(...),
        parameters: ConsoleCrawl::parameters($world, $tenant),
    );

    // The root's, as a platform operator — on the root's own host.
    ProductionShape::reset();
    actAsOperator('operator@'.ConsoleCrawl::ROOT);
    ConsoleCrawl::productionShape();

    $onRoot = (new ConsoleCrawl($this))->answers(
        host: ConsoleCrawl::ROOT,
        select: environmentConsolePage(...),
        parameters: ConsoleCrawl::parameters($world, $root),
    );

    // Every page with nothing in the URL is compared, and every page whose parameters both
    // worlds can name: the root holds organizations, users, roles and apps of its own.
    $compared = array_intersect_key($onRoot, $onTenant);
    $differ = [];

    foreach ($compared as $route => $status) {
        if ($status !== $onTenant[$route]) {
            $differ[] = "{$route}: tenant {$onTenant[$route]}, root {$status}";
        }
    }

    $served = array_filter($onRoot, static fn (int $status): bool => $status === 200);

    expect($differ)->toBe([], implode("\n", $differ))
        // Not vacuous: most of the console, parameterless pages included.
        ->and(count($compared))->toBeGreaterThan(80)
        ->and($served)->toHaveKeys([
            'environment.home', 'environment.social-providers', 'environment.sign-in-methods',
            'environment.auth-policy', 'environment.branding', 'environment.clients', 'environment.apis',
            'environment.webhooks', 'environment.audit', 'environment.audit-streams', 'environment.roles',
            'environment.users', 'environment.organizations', 'environment.keys.frontend', 'environment.settings',
        ]);
});

it('serves the root environment\'s console to nobody but its operators', function (): void {
    $world = ConsoleCrawl::world();
    $root = Environment::query()->where('is_default', true)->sole();

    // A workspace OWNER — the most a customer can be — signed in at the root.
    signInAsSubject(platformSubjectId('ada@lovelace-labs.example'));
    ConsoleCrawl::productionShape();

    $answers = (new ConsoleCrawl($this))->answers(
        host: ConsoleCrawl::ROOT,
        select: environmentConsolePage(...),
        parameters: ConsoleCrawl::parameters($world, $root),
    );

    $served = array_filter($answers, static fn (int $status): bool => $status !== 404);

    expect($answers)->not->toBeEmpty()
        ->and($served)->toBe([], 'a workspace owner reached the platform environment\'s console: '.implode(', ', array_keys($served)));
})->group('security');

it('sends somebody with no session to the root\'s sign-in, and back to the page afterwards', function (): void {
    ConsoleCrawl::world();
    ConsoleCrawl::productionShape();

    $this->get('https://'.ConsoleCrawl::ROOT.'/admin/social-sign-in')
        ->assertRedirect('https://'.ConsoleCrawl::ROOT.'/login');

    // The intent survives the sign-in: on the root's own host `/admin` is a subject's console.
    expect(session('url.intended'))->toBe('https://'.ConsoleCrawl::ROOT.'/admin/social-sign-in');
});

it('serves no environment console under a name that only resolves to the root', function (): void {
    ConsoleCrawl::world();
    actAsOperator('operator@'.ConsoleCrawl::ROOT);

    // Not the root's host, though the context it resolves to is the root's: the same rule
    // that keeps a sign-in form off every wildcard name keeps this console off them too.
    $this->get('https://nope.'.ConsoleCrawl::ROOT.'/admin')->assertNotFound();
    $this->get('https://nope.'.ConsoleCrawl::ROOT.'/admin/social-sign-in')->assertNotFound();
})->group('security');
