<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateOperator;
use Cbox\Id\Organization\Models\Environment;
use Illuminate\Routing\Route;
use Tests\Support\ConsoleCrawl;
use Tests\Support\ProductionShape;

/**
 * Platform admin, as a platform operator on the root host, crawled the way production
 * serves it. See {@see ConsoleCrawl}.
 *
 * It is the Platform area of the platform environment's console now (`/admin/platform/…`),
 * so the crawl starts where an operator does — that console's home, the area's own front
 * page, and the workspace console they sign in to — and every former `/platform` page must
 * be reached at its new address, with every link it offers answering: no 404, no 403.
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
        starts: [
            'https://'.ConsoleCrawl::ROOT.'/',
            'https://'.ConsoleCrawl::ROOT.'/dashboard',
            'https://'.ConsoleCrawl::ROOT.'/admin',
            'https://'.ConsoleCrawl::ROOT.'/admin/platform',
        ],
    );

    expect($crawl->failures)->toBe([], implode("\n", $crawl->failures))
        ->and($crawl->requests)->toBeGreaterThan(30)
        // Every page that used to be under `/platform`, reached under `/admin/platform`.
        ->and($crawl->components)->toHaveKeys([
            'console/platform/customers',
            'console/platform/customers/show',
            'console/platform/environments',
            'console/platform/organizations',
            'console/platform/organizations/show',
            'console/platform/operators',
            'console/platform/usage',
            'console/platform/queues',
            'console/platform/search',
        ]);
});
