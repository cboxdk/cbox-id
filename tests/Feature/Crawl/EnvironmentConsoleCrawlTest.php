<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Tests\Support\ConsoleCrawl;
use Tests\Support\ProductionShape;

/**
 * The environment console at `/admin`, crawled as its administrator would click through it —
 * on the environment's own host, routes cached and hosts enforced, as production serves
 * it. See {@see ConsoleCrawl}.
 */
afterEach(fn () => ProductionShape::reset());

it('serves every environment-console page and every link they offer', function (): void {
    $world = ConsoleCrawl::world();
    $environment = $world->environment;
    expect($environment)->not->toBeNull();

    actAsEnvironmentAdmin($world->ownerId, $environment->id);
    ConsoleCrawl::productionShape();

    $crawl = (new ConsoleCrawl($this))->crawl(
        host: $environment->slug.'.'.ConsoleCrawl::ROOT,
        select: static fn (Route $route): bool => str_starts_with((string) $route->getName(), 'environment.')
            && str_starts_with($route->uri(), 'admin'),
        parameters: ConsoleCrawl::parameters($world, $environment),
    );

    expect($crawl->failures)->toBe([], implode("\n", $crawl->failures))
        ->and($crawl->requests)->toBeGreaterThan(100)
        // The sign-in pages, with the demo's environment-wide providers, Globex's own and
        // Initech's opt-out on them — every row's change, edit and setup links followed.
        ->and($crawl->components)->toHaveKeys(['console/sign-in-methods', 'console/social-providers', 'console/auth-policy']);
});
