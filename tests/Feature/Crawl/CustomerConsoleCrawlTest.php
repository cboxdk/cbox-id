<?php

declare(strict_types=1);

use App\Http\Middleware\Authenticate;
use App\Platform\AdminPortal;
use App\Platform\Enums\PortalIntent;
use App\Platform\Enums\PortalScope;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Illuminate\Routing\Route;
use Tests\Support\ConsoleCrawl;
use Tests\Support\ProductionShape;

/**
 * What a vendor's CUSTOMER reaches on the vendor's environment host: their organization's
 * console as its admin and as a plain member, and the hosted Admin Portal a setup link
 * opens. Crawled the way production serves them. See {@see ConsoleCrawl}.
 */
afterEach(fn () => ProductionShape::reset());

it('serves every page of a customer\'s console and every link it offers', function (string $email): void {
    $world = ConsoleCrawl::world();
    $environment = $world->environment;
    expect($environment)->not->toBeNull();

    // An end user of the environment, signed in on its host through the one door.
    app(EnvironmentContext::class)->runAs($environment, function () use ($email): void {
        $subject = app(Subjects::class)->findByEmail($email);
        expect($subject)->not->toBeNull();
        app(PlatformAuth::class)->establish(request(), (string) $subject?->id, ['pwd']);
    });

    ConsoleCrawl::productionShape();

    $host = $environment->slug.'.'.ConsoleCrawl::ROOT;

    $crawl = (new ConsoleCrawl($this))->crawl(
        host: $host,
        select: static fn (Route $route): bool => ConsoleCrawl::guardedBy($route, Authenticate::class)
            && ! str_starts_with((string) $route->getName(), 'environment.')
            && ! str_starts_with((string) $route->getName(), 'platform.'),
        parameters: ConsoleCrawl::parameters($world, $environment),
        starts: ["https://{$host}/"],
    );

    expect($crawl->failures)->toBe([], implode("\n", $crawl->failures))
        ->and($crawl->requests)->toBeGreaterThan(20);
})->with([
    'organization admin' => 'frank@globex.example',
    'organization member' => 'peter@initech.example',
]);

it('serves every Admin Portal page a setup link opens, and every link they offer', function (): void {
    $world = ConsoleCrawl::world();
    $environment = $world->environment;
    expect($environment)->not->toBeNull();

    $token = app(EnvironmentContext::class)->runAs($environment, fn (): string => app(AdminPortal::class)->generate(
        $world->organizations['globex']->id,
        PortalScope::of(PortalIntent::cases()),
        $world->ownerId,
    ));

    ConsoleCrawl::productionShape();

    $host = $environment->slug.'.'.ConsoleCrawl::ROOT;

    // The customer opens the link and presses the button: the one POST of the visit.
    $this->get("https://{$host}/setup/{$token}")->assertOk();
    $this->post("https://{$host}/setup/{$token}")->assertRedirect();

    $crawl = (new ConsoleCrawl($this))->crawl(
        host: $host,
        select: static fn (Route $route): bool => str_starts_with((string) $route->getName(), 'portal.')
            && $route->parameterNames() === [],
        parameters: [],
        starts: ["https://{$host}/setup"],
    );

    expect($crawl->failures)->toBe([], implode("\n", $crawl->failures))
        ->and($crawl->requests)->toBeGreaterThan(8);
});
