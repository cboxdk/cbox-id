<?php

declare(strict_types=1);

use App\Http\Middleware\Authenticate;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Contracts\SignInProviders;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Routing\Route;
use Tests\Support\ConsoleCrawl;

/**
 * A SINGLE-TENANT INSTALL'S CONSOLE — the organization console, which there is the whole
 * administration, the environment's own sign-in settings included — crawled as its owner
 * clicks through it. See {@see ConsoleCrawl}.
 *
 * With social login on every kind of row: the environment's providers (managed from here on
 * this shape), the organization's own in the environment's place, and one turned off.
 */
it('serves every page of a single-tenant install\'s console and every link they offer', function (): void {
    installedDeployment();
    [, $org] = actingAsRole(MembershipRole::Owner);

    $providers = app(SignInProviders::class);
    $connections = app(Connections::class);

    foreach ([[null, 'github'], [null, 'discord'], [$org->id, 'github']] as [$owner, $key]) {
        $connection = $providers->create($owner, $key, ConnectionType::OAuth2, ucfirst($key), ['provider' => $key, 'client_id' => 'c', 'client_secret' => 's']);
        $connections->activate($owner, $connection->id);
    }

    $providers->stopInheriting($org->id, 'discord');

    $crawl = (new ConsoleCrawl($this))->crawl(
        host: (string) parse_url(url('/'), PHP_URL_HOST),
        select: static fn (Route $route): bool => ConsoleCrawl::guardedBy($route, Authenticate::class)
            && ! str_starts_with((string) $route->getName(), 'environment.')
            && ! str_starts_with((string) $route->getName(), 'platform.'),
        parameters: ['organization' => $org->id],
        starts: [url('/dashboard'), url('/sign-in-methods'), url('/social-sign-in?provider=google')],
    );

    expect($crawl->failures)->toBe([], implode("\n", $crawl->failures))
        ->and($crawl->requests)->toBeGreaterThan(60)
        ->and($crawl->components)->toHaveKeys(['console/sign-in-methods', 'console/social-providers', 'console/auth-policy']);
});
