<?php

declare(strict_types=1);

use App\Http\Middleware\Authenticate;
use Cbox\Id\Organization\Models\Environment;
use Illuminate\Routing\Route;
use Tests\Support\ConsoleCrawl;
use Tests\Support\ProductionShape;

/**
 * THE PLATFORM ROOT'S OWN ENVIRONMENT CONSOLE — `cboxid.com/admin`, as a platform operator
 * would click through it — crawled the way production serves it: routes cached, hosts
 * enforced. The same pages, at the same paths, as a tenant environment's console
 * ({@see EnvironmentConsoleCrawlTest}); only the host and the person differ.
 */
afterEach(fn () => ProductionShape::reset());

it('serves every environment-console page of the root environment, and every link they offer, to an operator', function (): void {
    $world = ConsoleCrawl::world();
    $root = Environment::query()->where('is_default', true)->sole();

    actAsOperator('operator@'.ConsoleCrawl::ROOT);
    ConsoleCrawl::productionShape();

    $crawl = (new ConsoleCrawl($this))->crawl(
        host: ConsoleCrawl::ROOT,
        select: static fn (Route $route): bool => str_starts_with((string) $route->getName(), 'environment.')
            && str_starts_with($route->uri(), 'admin'),
        parameters: ConsoleCrawl::parameters($world, $root),
    );

    expect($crawl->failures)->toBe([], implode("\n", $crawl->failures))
        ->and($crawl->requests)->toBeGreaterThan(100)
        ->and($crawl->components)->toHaveKeys(['console/sign-in-methods', 'console/social-providers', 'console/auth-policy']);
});

it('serves an operator the root host\'s workspace console, sending every environment page to the platform environment\'s', function (): void {
    $world = ConsoleCrawl::world();
    $root = Environment::query()->where('is_default', true)->sole();

    actAsOperator('operator@'.ConsoleCrawl::ROOT);
    ConsoleCrawl::productionShape();

    // Every organization-console page on the root host, as the operator — the ones that set
    // something an environment owns land on the platform environment's console, and every
    // link those pages offer is followed there too.
    $crawl = (new ConsoleCrawl($this))->crawl(
        host: ConsoleCrawl::ROOT,
        select: static fn (Route $route): bool => ConsoleCrawl::guardedBy($route, Authenticate::class)
            && ! str_starts_with((string) $route->getName(), 'environment.')
            && ! str_starts_with((string) $route->getName(), 'platform.'),
        parameters: ConsoleCrawl::parameters($world, $root),
        starts: ['https://'.ConsoleCrawl::ROOT.'/'],
    );

    expect($crawl->failures)->toBe([], implode("\n", $crawl->failures))
        ->and($crawl->requests)->toBeGreaterThan(80)
        ->and($crawl->components)->toHaveKey('console/social-providers');
});
