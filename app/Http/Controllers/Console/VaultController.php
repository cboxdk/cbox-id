<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Shared\HelpProps;
use App\Http\Props\Shared\PaginationProps;
use App\Http\Requests\Console\GrantVaultAccessRequest;
use App\Http\Requests\Console\RotateVaultSecretRequest;
use App\Http\Requests\Console\StoreVaultSecretRequest;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\VaultScope;
use App\Platform\Help\HelpTopic;
use App\Platform\VerifiedEmailGate;
use Cbox\Id\TokenVault\Contracts\SecretVault;
use Cbox\Id\TokenVault\Models\VaultGrant;
use Cbox\Id\TokenVault\Models\VaultSecret;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * CONSOLE › TOKEN VAULT — the downstream credential store: the API keys this
 * organization's apps and agents present to third parties. Sealed at rest, brokered only
 * to explicitly granted clients, and never shown again after it is stored.
 *
 * THE LAST UNMERGED PAIR, and the one where the drift between the planes was a security
 * hole rather than a missing feature. The organization plane's page was gated by `sudo`;
 * the environment plane's three pages by nothing at all — so the identical rotate, grant
 * and revoke actions demanded a fresh password from a tenant administrator and none
 * whatsoever from the administrator who can act on EVERY tenant. Both planes carry a
 * step-up now, on the route, and there is one controller, so a gate cannot be added to one
 * plane and forgotten on the other again.
 *
 * EVERY READ AND EVERY WRITE GOES THROUGH {@see VaultScope}, which derives the owner from
 * the CONSOLE's scope. The environment plane's version derived it from the row being
 * acted on and handed the framework's deny-by-default owner check its own answer — a
 * tautology that authorized every row against itself. An id outside this scope resolves to
 * nothing and is a 404, which tells the caller nothing about what exists elsewhere.
 *
 * THE SEALED VALUE IS NEVER SENT TO THE BROWSER. Not the stored one, not a rotated one.
 * The only time a value is in the clear is the moment somebody types it into the rotate
 * field, and it is sealed and gone on submit.
 */
final readonly class VaultController extends ConsoleController
{
    private const PER_PAGE = 25;

    public function index(Request $request, VaultScope $vault): Response
    {
        $this->scope->assertMayAdminister();

        /*
         * Through the scope, not a hand-written where: the list and the detail page's
         * mutations must be bounded by the SAME owner, or a row can be listed that no
         * button on it can act on — or, as it was, the reverse.
         */
        $query = $vault->secrets()->orderByDesc('id');

        $term = trim($request->string('q')->toString());

        if ($term !== '') {
            $query->where('name', 'like', '%'.$term.'%');
        }

        $page = $query->paginate(self::PER_PAGE)->withQueryString();

        return $this->page('console/vault/index', 'Token vault', [
            'help' => HelpProps::for(HelpTopic::TokenVault),
            'secrets' => array_map(fn (VaultSecret $secret): array => $this->row($secret), $page->getCollection()->all()),
            'pagination' => PaginationProps::from($page),
            'search' => $term,
            /*
             * The environment's OWN secrets are a separate collection, not a wider view of
             * the tenants'. Said out loud, because "the vault" meaning two different sets
             * depending on a picker elsewhere in the chrome is not something an
             * administrator should have to infer.
             */
            'environmentWide' => $this->scope->organizationId() === null,
            'createHref' => $this->url('vault.create'),
        ]);
    }

    public function create(): Response
    {
        $this->scope->assertMayAdminister();

        return $this->page('console/vault/create', 'New stored token', [
            /*
             * ONE name, asked for by name. Indexing the whole organization map to label a
             * single form hydrated every organization in the environment — a tenant with a
             * few thousand paid for all of them to render one sentence.
             */
            'scopeLabel' => $this->scope->organizationId() === null
                ? 'this environment'
                : ($this->scope->organizationName() ?? 'this organization'),
            'indexHref' => $this->url('vault'),
            'storeHref' => $this->url('vault.store'),
        ]);
    }

    public function store(StoreVaultSecretRequest $request, SecretVault $secrets, VaultScope $vault): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        /*
         * REACH OUTSIDE THE TENANT, handed to an account whose address nobody has
         * confirmed. A stored secret is a credential this platform will present to a third
         * party on the tenant's behalf — the same shape as a webhook or a directory, both
         * of which have been held this way since the rule was written.
         *
         * The `sudo` gate on the route is not this gate. Sudo asks "are you still the
         * person who signed in"; an unverified account answers that perfectly well,
         * because it has a password. This asks whether anybody has confirmed the address
         * belongs to a real person, which is the question a social sign-in leaves open.
         *
         * It was missing here for a reason worth recording: the sweep that enforces this
         * rule matched write methods by NAME, and its list did not include `store` — so
         * the one create page in the console that used that name was invisible to it. The
         * name is in the list now.
         */
        if ($this->scope->plane() === ConsolePlane::Organization) {
            app(VerifiedEmailGate::class)->require('store a secret');
        }

        $secret = $secrets->store(
            $request->name(),
            $request->provider(),
            $request->secret(),
            $vault->owner(),
        );

        return to_route($this->scope->routeName('vault.show'), $secret->id)
            ->with('status', 'Secret sealed and stored — its value is never shown again.');
    }

    public function show(string $secret, VaultScope $vault): Response
    {
        $this->scope->assertMayAdminister();

        $model = $this->resolve($secret, $vault);

        return $this->page('console/vault/show', $model->name, [
            'secret' => $this->row($model),
            /*
             * DENY BY DEFAULT: only the clients listed here may lease this secret. Revoked
             * grants are not shown — a revoked grant authorizes nothing, and listing it
             * beside the live ones invites somebody to read the list as "who has access".
             */
            'grants' => VaultGrant::query()
                ->where('secret_id', $model->id)
                ->whereNull('revoked_at')
                ->orderBy('client_id')
                ->get(['client_id'])
                ->map(fn (VaultGrant $grant): array => [
                    'clientId' => $grant->client_id,
                    'revokeHref' => $this->url('vault.grants.destroy', [
                        'secret' => $model->id,
                        'client' => $grant->client_id,
                    ]),
                ])
                ->all(),
            'indexHref' => $this->url('vault'),
            'urls' => [
                'rotate' => $this->url('vault.rotate', $model->id),
                'grant' => $this->url('vault.grants.store', $model->id),
                'revoke' => $this->url('vault.revoke', $model->id),
            ],
        ]);
    }

    public function rotate(RotateVaultSecretRequest $request, string $secret, SecretVault $secrets, VaultScope $vault): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $model = $this->resolve($secret, $vault);

        // A revoked secret has no future lease to serve, so rotating one would replace a
        // sealed value nothing can ever open — busywork that reads as a fix.
        if ($model->isRevoked()) {
            return back()->withErrors(['secret' => 'This secret is revoked — it can no longer be rotated.']);
        }

        $secrets->rotate($model->id, $request->secret(), $vault->owner());

        return back()->with('status', 'Secret rotated — the sealed value was replaced.');
    }

    public function grant(GrantVaultAccessRequest $request, string $secret, SecretVault $secrets, VaultScope $vault): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $model = $this->resolve($secret, $vault);

        if ($model->isRevoked()) {
            return back()->withErrors(['client' => 'This secret is revoked — no client can lease it.']);
        }

        $secrets->grant($model->id, $request->clientId(), $vault->owner());

        return back()->with('status', 'Access granted.');
    }

    public function revokeGrant(string $secret, string $client, SecretVault $secrets, VaultScope $vault): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $secrets->revokeGrant($this->resolve($secret, $vault)->id, $client, $vault->owner());

        return back()->with('status', 'Access revoked.');
    }

    public function revoke(string $secret, SecretVault $secrets, VaultScope $vault): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $secrets->revoke($this->resolve($secret, $vault)->id, $vault->owner());

        return to_route($this->scope->routeName('vault'))
            ->with('status', 'Secret revoked — no future lease can open it.');
    }

    /**
     * A secret this console's scope owns, or a 404.
     *
     * Re-resolved on every request rather than trusted from the one that rendered the
     * page: the id arrives in the URL of each mutation, so this is the only place that can
     * decide whether the caller is entitled to it.
     */
    private function resolve(string $secret, VaultScope $vault): VaultSecret
    {
        $model = $vault->find($secret);

        abort_if($model === null, 404);

        return $model;
    }

    /**
     * One secret, as the pages draw it — its SHAPE and never its value.
     *
     * @return array{id: string, name: string, provider: string, scope: string, status: string, revoked: bool, rotatedAt: string|null, expiresAt: string|null, href: string}
     */
    private function row(VaultSecret $secret): array
    {
        return [
            'id' => $secret->id,
            'name' => $secret->name,
            'provider' => $secret->provider,
            'scope' => $secret->owner_type === 'organization' ? 'Organization' : 'Environment-wide',
            // Three states rather than a boolean: revoked is permanent and expired is a
            // date that has passed, and an administrator's next move differs for each.
            'status' => $secret->isRevoked() ? 'revoked' : ($secret->isExpired() ? 'expired' : 'active'),
            'revoked' => $secret->isRevoked(),
            'rotatedAt' => $secret->rotated_at?->diffForHumans(),
            'expiresAt' => $secret->expires_at?->diffForHumans(),
            'href' => $this->url('vault.show', $secret->id),
        ];
    }
}
