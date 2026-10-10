<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateOperator;
use Cbox\Id\Organization\Models\Environment;
use Illuminate\Routing\Route;
use Tests\Support\ConsoleCrawl;
use Tests\Support\ProductionShape;

/**
 * The platform console, as a platform operator on the root host, crawled the way production
 * serves it. See {@see ConsoleCrawl}.
 */
afterEach(fn () => ProductionShape::reset());

it('serves every platform page and every link they offer to an operator', function (): void {
    $world = ConsoleCrawl::world();
    $root = Environment::query()->where('is_default', true)->sole();

    actAsOperator('operator@'.ConsoleCrawl::ROOT);
    ConsoleCrawl::productionShape();

    $parameters = [
        ...ConsoleCrawl::parameters($world, $root),
        // The platform pages act on the root's own rows: workspaces are its organizations.
        'organization' => $world->workspace?->id,
    ];

    $crawl = (new ConsoleCrawl($this))->crawl(
        host: ConsoleCrawl::ROOT,
        select: static fn (Route $route): bool => ConsoleCrawl::guardedBy($route, AuthenticateOperator::class),
        parameters: $parameters,
        starts: ['https://'.ConsoleCrawl::ROOT.'/', 'https://'.ConsoleCrawl::ROOT.'/dashboard'],
    );

    expect($crawl->failures)->toBe([], implode("\n", $crawl->failures))
        ->and($crawl->requests)->toBeGreaterThan(30);
});
