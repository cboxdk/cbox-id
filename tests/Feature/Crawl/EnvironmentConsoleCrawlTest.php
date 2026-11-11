<?php

declare(strict_types=1);

use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\PlatformRoot;
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

    actAsEnvironmentAdmin($world->ownerId, $environment->id, consoleHost: ConsoleCrawl::ROOT);
    ConsoleCrawl::productionShape();

    $crawl = (new ConsoleCrawl($this))->crawl(
        host: ConsoleCrawl::ROOT,
        select: static fn (Route $route): bool => str_starts_with((string) $route->getName(), 'environment.')
            && str_starts_with($route->uri(), '{environmentPath}'),
        parameters: ConsoleCrawl::parameters($world, $environment),
    );

    expect($crawl->failures)->toBe([], implode("\n", $crawl->failures))
        ->and($crawl->requests)->toBeGreaterThan(100)
        // The sign-in pages, with the demo's environment-wide providers, Globex's own and
        // Initech's opt-out on them — every row's change, edit and setup links followed.
        ->and($crawl->components)->toHaveKeys(['console/sign-in-methods', 'console/social-providers', 'console/auth-policy']);
});

/**
 * The same console, as a member limited to this one environment: no error, and no page
 * offering a link that then refuses them.
 *
 * (A Reader is not crawled here yet: most environment pages still ask for the manage role
 * on a read, so the rail offers a Reader pages that answer 403. EnvironmentAdminConsoleTest
 * holds what a Reader may do today: the console opens, and every write is refused.)
 */
it('serves the environment console to a member limited to one environment', function (string $roles): void {
    $world = ConsoleCrawl::world();
    $environment = $world->environment;
    expect($environment)->not->toBeNull();

    [$member, $subjectId] = addMember((string) $world->workspace?->id, MembershipRole::Admin, $roles.'@lovelace-labs.example');
    grantNativeProductRoles($member, [$roles]);
    app(PlatformRoot::class)->run(fn () => app(Memberships::class)->setEnvironmentAccess(
        (string) $world->workspace?->id,
        $subjectId,
        false,
        [$environment->id],
    ));

    actAsEnvironmentAdmin($subjectId, $environment->id, consoleHost: ConsoleCrawl::ROOT);
    ConsoleCrawl::productionShape();

    $crawl = (new ConsoleCrawl($this))->crawl(
        host: ConsoleCrawl::ROOT,
        select: static fn (Route $route): bool => str_starts_with((string) $route->getName(), 'environment.')
            && str_starts_with($route->uri(), '{environmentPath}'),
        parameters: ConsoleCrawl::parameters($world, $environment),
    );

    expect($crawl->failures)->toBe([], implode("\n", $crawl->failures))
        ->and($crawl->requests)->toBeGreaterThan(50);
})->with([
    'a developer limited to this environment' => ['developer'],
]);
