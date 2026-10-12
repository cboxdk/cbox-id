<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Platform\EnvironmentSudo;
use Illuminate\Support\Facades\DB;
use Tests\Support\ConsoleCrawl;
use Tests\Support\ProductionShape;

/**
 * CONNECTORS LEADS SOMEWHERE — every link on every Connectors page, followed.
 *
 * Connectors was a dead end: four cards that linked nowhere, a Directory Sync card that said
 * "Managed in module", a footnote that named "the Directory pages" without linking to them,
 * and a Connections list that said "No connectors yet" in an environment with an SSO
 * connection, a SCIM directory, a webhook and a log stream. Its whole job is now to lead into
 * the pages where integrations live, so the claim this holds is the strong one: EVERY link
 * the catalog and the connections list offer — each card's setup and manage, every provider
 * shortcut, every row, every filter — answers as the page it says it is. The console crawl
 * beside this file visits each route at most twice, which is right for sixty rail links and
 * wrong for a page made of shortcuts into the same three forms.
 *
 * On the environment console, as a whole and inside one organization's page; with a failing
 * webhook in the world so the "failing" links are drawn too.
 */
afterEach(fn () => ProductionShape::reset());

it('offers no link on any Connectors page that answers anything but the page', function (): void {
    $world = ConsoleCrawl::world();
    $environment = $world->environment;
    expect($environment)->not->toBeNull();

    DB::table('webhook_endpoints')->where('environment_id', $environment->id)
        ->update(['consecutive_failures' => 2, 'last_error' => 'HTTP 500 from the endpoint']);

    actAsEnvironmentAdmin($world->ownerId, $environment->id);
    // Past the step-up the setup forms sit behind, so each shortcut lands on its FORM
    // rather than on the password prompt in front of it.
    app(EnvironmentSudo::class)->confirm();
    ConsoleCrawl::productionShape();

    $origin = 'https://'.$environment->slug.'.'.ConsoleCrawl::ROOT.'/admin';
    $acme = $world->organizations['acme']->id;

    $crawl = new ConsoleCrawl($this);

    foreach ([
        '/connectors',
        '/connectors/connections',
        '/connectors/connections?status=failing',
        '/connectors/connections?type=directory-sync',
        '/organizations/'.$acme.'/connectors',
        '/organizations/'.$acme.'/connectors/connections',
    ] as $path) {
        $crawl->everyLink($origin.$path);
    }

    expect($crawl->failures)->toBe([], implode("\n", $crawl->failures))
        ->and($crawl->components)->toHaveKeys([
            'connectors::catalog',
            'connectors::connections',
            'console/directories/create',
            'console/outbound-sync/create',
            'console/log-streams/create',
            'console/pipes/create',
            'console/webhooks/show',
        ]);
});

/**
 * THE CATALOG'S CARDS, as data: every type this console has, each with a way to start one
 * and a way to manage them, and a REAL count — Directory Sync's included, which used to be
 * the words "Managed in module".
 */
it('gives every catalog card a setup link, a manage link and a live count', function (): void {
    $world = ConsoleCrawl::world();
    $environment = $world->environment;
    expect($environment)->not->toBeNull();

    actAsEnvironmentAdmin($world->ownerId, $environment->id);

    $version = (string) app(HandleInertiaRequests::class)->version(request());
    $response = $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
        ->get('https://'.$environment->slug.'.'.ConsoleCrawl::ROOT.'/admin/connectors');

    $response->assertOk();

    /** @var list<array{key: string, count: int, setupHref: string|null, manageHref: string, options: list<array{href: string}>}> $types */
    $types = $response->json('props.types');
    $byKey = collect($types)->keyBy('key');

    expect($byKey->keys()->all())->toBe([
        'enterprise-sso', 'social-login', 'directory-sync', 'hr-systems', 'scim-provisioning',
        'saml-apps', 'webhook', 'hooks', 'log-streams', 'pipes',
    ]);

    foreach ($types as $type) {
        expect($type['setupHref'])->not->toBeNull("[{$type['key']}] has no way to start one")
            ->and($type['manageHref'])->toBeString();
    }

    $directories = DB::table('directories')->where('environment_id', $environment->id)->count();

    expect($byKey['directory-sync']['count'])->toBe($directories)
        ->and($directories)->toBeGreaterThan(0)
        ->and($byKey['enterprise-sso']['count'])->toBeGreaterThan(0)
        ->and($byKey['webhook']['count'])->toBeGreaterThan(0)
        ->and($byKey['log-streams']['count'])->toBeGreaterThan(0)
        ->and(collect($byKey['hr-systems']['options'])->pluck('href')->first())->toEndWith('/admin/sync-in/new?provider=workday')
        ->and((string) $response->getContent())->not->toContain('Managed in module');
});
