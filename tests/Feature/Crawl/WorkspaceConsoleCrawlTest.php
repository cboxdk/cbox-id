<?php

declare(strict_types=1);

use App\Http\Middleware\Authenticate;
use Cbox\Id\Organization\Models\Environment;
use Illuminate\Routing\Route;
use Tests\Support\ConsoleCrawl;
use Tests\Support\ProductionShape;

/**
 * The workspace console on the root host — Lovelace Labs, as its owner and as an admin who
 * is not the owner — crawled the way production serves it. See {@see ConsoleCrawl}.
 */
afterEach(fn () => ProductionShape::reset());

/** Every console page on the root host: behind the sign-in, and not another plane's. */
function workspaceConsoleRoute(Route $route): bool
{
    return ConsoleCrawl::guardedBy($route, Authenticate::class)
        && ! str_starts_with((string) $route->getName(), 'environment.')
        && ! str_starts_with((string) $route->getName(), 'platform.');
}

it('serves every workspace-console page and every link they offer, to the owner and to an admin', function (string $email): void {
    $world = ConsoleCrawl::world();
    $root = Environment::query()->where('is_default', true)->sole();

    signInAsSubject(platformSubjectId($email));
    ConsoleCrawl::productionShape();

    $crawl = (new ConsoleCrawl($this))->crawl(
        host: ConsoleCrawl::ROOT,
        select: workspaceConsoleRoute(...),
        parameters: ConsoleCrawl::parameters($world, $root),
        starts: ['https://'.ConsoleCrawl::ROOT.'/'],
    );

    expect($crawl->failures)->toBe([], implode("\n", $crawl->failures))
        ->and($crawl->requests)->toBeGreaterThan(80);
})->with([
    'owner' => 'ada@lovelace-labs.example',
    'admin' => 'grace@lovelace-labs.example',
]);
