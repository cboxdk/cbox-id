<?php

declare(strict_types=1);

use App\Http\Middleware\RedirectIfAuthenticated;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Illuminate\Routing\Route;
use Tests\Support\ConsoleCrawl;
use Tests\Support\ProductionShape;

/**
 * The pages nobody signs in to reach — sign-in, sign-up, password reset, the error page —
 * on the root host and on an environment's, and the consent screen an app's sign-in leads
 * to. Crawled the way production serves them. See {@see ConsoleCrawl}.
 */
afterEach(fn () => ProductionShape::reset());

it('serves the hosted sign-in pages and every link they offer, on the root and an environment host', function (): void {
    $world = ConsoleCrawl::world();
    $environment = $world->environment;
    expect($environment)->not->toBeNull();

    ConsoleCrawl::productionShape();

    $guest = static fn (Route $route): bool => ConsoleCrawl::guardedBy($route, RedirectIfAuthenticated::class)
        && $route->parameterNames() === [];

    $crawl = new ConsoleCrawl($this);

    foreach ([ConsoleCrawl::ROOT, $environment->slug.'.'.ConsoleCrawl::ROOT] as $host) {
        $crawl->crawl(host: $host, select: $guest, parameters: [], starts: ["https://{$host}/", "https://{$host}/login"]);

        // The error page renders, rather than failing while it explains a failure.
        $this->get("https://{$host}/no-such-page")->assertNotFound()->assertSee('<html', false);
    }

    expect($crawl->failures)->toBe([], implode("\n", $crawl->failures))
        ->and($crawl->requests)->toBeGreaterThan(8);
});

it('serves the consent screen an app\'s sign-in leads to, and every link it offers', function (): void {
    $world = ConsoleCrawl::world();
    $environment = $world->environment;
    expect($environment)->not->toBeNull();

    // A THIRD-PARTY app: the demo's own apps are first-party and skip consent entirely.
    $clientId = app(EnvironmentContext::class)->runAs($environment, function () use ($world): string {
        $registered = app(ClientRegistry::class)->register(new NewClient(
            name: 'Partner Reports',
            type: ClientType::Confidential,
            redirectUris: ['https://partner.example/callback'],
            grantTypes: ['authorization_code', 'refresh_token'],
            scopes: ['openid', 'email', 'profile'],
            firstParty: false,
        ), AuditActor::organizationMember($world->ownerId));

        $subject = app(Subjects::class)->findByEmail('peter@initech.example');
        app(PlatformAuth::class)->establish(request(), (string) $subject?->id, ['pwd']);

        return (string) $registered->client->client_id;
    });

    ConsoleCrawl::productionShape();

    $host = $environment->slug.'.'.ConsoleCrawl::ROOT;
    $authorize = "https://{$host}/oauth/authorize?".http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => 'https://partner.example/callback',
        'response_type' => 'code',
        'scope' => 'openid email',
        'state' => 'crawl',
        'code_challenge' => pkceChallenge(),
        'code_challenge_method' => 'S256',
    ]);

    $crawl = (new ConsoleCrawl($this))->crawl(
        host: $host,
        select: static fn (Route $route): bool => false,
        parameters: [],
        starts: [$authorize],
    );

    expect($crawl->failures)->toBe([], implode("\n", $crawl->failures))
        ->and(array_keys($crawl->components))->toContain('oauth/consent');
});
