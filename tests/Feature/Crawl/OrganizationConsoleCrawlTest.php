<?php

declare(strict_types=1);

use App\Http\Middleware\Authenticate;
use Cbox\Id\Organization\Models\Environment;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\URL;
use Tests\Support\ConsoleCrawl;
use Tests\Support\ProductionShape;

/**
 * The organization console on the root host — Lovelace Labs at `/orgs/{org}`, as its owner
 * and as an admin who is not the owner — crawled the way production serves it, from `/`
 * (which lands in the organization) and from every page. See {@see ConsoleCrawl}.
 */
afterEach(fn () => ProductionShape::reset());

/** Every console page on the root host: behind the sign-in, and not another plane's. */
function organizationConsoleRoute(Route $route): bool
{
    return ConsoleCrawl::guardedBy($route, Authenticate::class)
        && ! str_starts_with((string) $route->getName(), 'environment.')
        && ! str_starts_with((string) $route->getName(), 'platform.');
}

it('serves every organization-console page and every link they offer, to the owner and to an admin', function (string $email): void {
    $world = ConsoleCrawl::world();
    $root = Environment::query()->where('is_default', true)->sole();

    signInAsSubject(platformSubjectId($email));
    ConsoleCrawl::productionShape();

    $org = (string) URL::getDefaultParameters()['org'];

    $crawl = (new ConsoleCrawl($this))->crawl(
        host: ConsoleCrawl::ROOT,
        select: organizationConsoleRoute(...),
        parameters: [...ConsoleCrawl::parameters($world, $root), 'org' => $org],
        starts: ['https://'.ConsoleCrawl::ROOT.'/', 'https://'.ConsoleCrawl::ROOT.'/orgs'],
    );

    expect($crawl->failures)->toBe([], implode("\n", $crawl->failures))
        ->and($crawl->requests)->toBeGreaterThan(80)
        // It reached the picker, and (for the owner, whom the organization's native roles
        // admit to all of it) the organization's own pages under `/orgs/{org}`, or it
        // proved nothing about them.
        ->and(array_keys($crawl->components))->toContain('console/orgs/index');

    if ($email === 'ada@lovelace-labs.example') {
        expect(array_keys($crawl->components))->toContain('console/projects/index', 'console/projects/show', 'console/members', 'console/account-settings', 'console/keys/organization', 'billing::billing');
    }
})->with([
    'owner' => 'ada@lovelace-labs.example',
    'admin' => 'grace@lovelace-labs.example',
]);
