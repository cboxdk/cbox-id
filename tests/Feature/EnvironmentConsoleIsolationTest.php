<?php

declare(strict_types=1);

use App\Platform\Navigation\ConsoleNavigation;
use Cbox\Id\Directory\Contracts\Directories;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Cbox\Id\Webhooks\Contracts\WebhookRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/**
 * A sweep, not a spot-check.
 *
 * The environment console is where a self-serve signup lands, so every page on it is
 * reachable by anyone with an email address. Two of its fifty pages had an isolation
 * test; the audit log got one only because it had already leaked — it queried
 * `AuditEntry::query()` with no filter at all, against a table with no environment
 * column, so a free signup could page through every other customer's security trail.
 *
 * That bug was not special. It was the first one anybody looked for. This walks EVERY
 * page in the environment navigation with a second tenant's data sitting in the
 * database under recognizable names, and fails if any of those names reaches the
 * response — so the next page written inherits the check instead of waiting for its own
 * incident.
 *
 * @return array{0: object, 1: object} [victim, attacker] provisioning results
 */
function twoTenants(): array
{
    platformRootEnvironment();

    $victim = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Victim Co',
        ownerEmail: 'owner@victim.example',
        ownerName: 'Victim Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    platformRootEnvironment();

    $attacker = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Attacker Co',
        ownerEmail: 'owner@attacker.example',
        ownerName: 'Attacker Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    return [$victim, $attacker];
}

/**
 * Seed one recognizable record of each console-visible kind into an environment.
 *
 * Every value contains the marker, so a single string search over a response answers
 * "did anything from the other tenant reach this page" for all of them at once.
 *
 * @return list<string> the ids of the seeded records, for the IDOR pass
 */
function seedTenantData(string $environmentId, string $marker): array
{
    return app(EnvironmentContext::class)->runAs(
        GenericEnvironment::of($environmentId),
        function () use ($marker): array {
            $org = app(Organizations::class)->create(new NewOrganization("{$marker} Org", strtolower($marker).'-org'));

            $user = app(Subjects::class)->create(
                strtolower($marker)."@{$marker}.example",
                "{$marker} Person",
                'a-strong-unbreached-passphrase',
            );
            app(Memberships::class)->add($org->id, $user->id, MembershipRole::Member);

            $client = app(ClientRegistry::class)->register(new NewClient(
                name: "{$marker} Application",
                type: ClientType::Confidential,
                redirectUris: ['https://'.strtolower($marker).'.example/callback'],
                grantTypes: ['authorization_code'],
                scopes: ['openid'],
            ))->client;

            // A resolvable host, deliberately: the registry SSRF-guards the URL by
            // resolving it, and a .example domain is refused on any runner with a real
            // resolver. The marker rides in the path instead, which is what the console
            // renders anyway.
            $webhook = app(WebhookRegistry::class)
                ->register($org->id, 'https://example.com/hook-'.strtolower($marker), ['user.created'])
                ->endpoint;

            $connection = app(Connections::class)->create(
                $org->id,
                ConnectionType::Oidc,
                "{$marker} SSO",
                [
                    'issuer' => 'https://'.strtolower($marker).'.example',
                    'client_id' => strtolower($marker).'-idp',
                    'client_secret' => 'shh',
                ],
            );

            $directory = app(Directories::class)->register($org->id, "{$marker} Directory")->directory;

            return [$org->id, $user->id, $client->id, $webhook->id, $connection->id, $directory->id];
        },
    );
}

it('never shows one environment\'s data on another\'s console', function (): void {
    [$victim, $attacker] = twoTenants();

    seedTenantData($victim->environment->id, 'Zarquon');

    // The attacker's own environment gets data too. A page that returns NOTHING is
    // trivially isolated and proves nothing — this makes each page actually render a
    // list, so an empty result means a real filter rather than an empty table.
    seedTenantData($attacker->environment->id, 'Ordinary');

    serveOnTestHost($attacker->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($attacker->environment->id));
    actAsEnvironmentAdmin($attacker->member, $attacker->environment->id);

    $leaked = [];
    $rendered = [];
    $refused = [];

    foreach ((new ConsoleNavigation)->environment()->routes() as $route) {
        $response = $this->get(route($route));

        // A page that refuses outright is isolated by definition; only what renders
        // can leak. Counted, not silently skipped — see the assertion below.
        if ($response->getStatusCode() !== 200) {
            $refused[] = $route;

            continue;
        }

        $body = $response->getContent() ?: '';

        if (str_contains($body, 'Zarquon')) {
            $leaked[] = $route;
        }

        if (str_contains($body, 'Ordinary')) {
            $rendered[] = $route;
        }
    }

    expect($leaked)->toBe([], 'other tenant\'s data rendered on: '.implode(', ', $leaked));

    // The assertion that keeps the one above honest. A console where every page 500s,
    // or renders an empty table because the fixture never landed, passes a leak check
    // trivially and proves nothing at all. The attacker's OWN records must be visible
    // on their own console — if they are not, this test is measuring silence.
    fwrite(STDERR, sprintf(
        "\n  isolation sweep: %d pages, %d showing own data, %d refused\n",
        count((new ConsoleNavigation)->environment()->routes()),
        count($rendered),
        count($refused),
    ));

    expect(count($rendered))->toBeGreaterThanOrEqual(
        4,
        'the sweep rendered no tenant data anywhere, so it could not have detected a leak either'
    );
});

/**
 * The index pages above are the easy half. The detail pages take an id straight out of
 * the URL, which is where an IDOR actually lives: knowing a ULID is not authorization,
 * and a deep link is exactly what leaks out of a support ticket or a browser history.
 *
 * Each of these is requested with the VICTIM's real id from the ATTACKER's host. Only a
 * refusal is acceptable — a 200 means the page loaded someone else's record.
 */
it('refuses a deep link to another environment\'s record', function (): void {
    [$victim, $attacker] = twoTenants();

    [$orgId, $userId, $clientId, $webhookId, $connectionId, $directoryId] = seedTenantData($victim->environment->id, 'Zarquon');

    serveOnTestHost($attacker->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($attacker->environment->id));
    actAsEnvironmentAdmin($attacker->member, $attacker->environment->id);

    $deepLinks = [
        'environment.organizations.show' => $orgId,
        'environment.users.show' => $userId,
        'environment.clients.show' => $clientId,
        'environment.webhooks.show' => $webhookId,
        'environment.connections.show' => $connectionId,
        'environment.directories.show' => $directoryId,
    ];

    $reachable = [];

    foreach ($deepLinks as $routeName => $id) {
        $response = $this->get(route($routeName, $id));

        if ($response->getStatusCode() === 200) {
            $reachable[] = $routeName;
        }
    }

    expect($reachable)->toBe([], 'another environment\'s record opened on: '.implode(', ', $reachable));
});
