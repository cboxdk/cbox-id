<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\Keys\CreateKey;
use App\Actions\Keys\RevokeKey;
use App\Actions\Keys\RotateKey;
use App\Actions\Workspace\Keys\CreateEnvironmentKey;
use App\Actions\Workspace\Keys\RevokeEnvironmentKey;
use App\Http\Props\Console\EnvironmentKeyRowProps;
use App\Http\Props\Console\EnvironmentScopeProps;
use App\Http\Props\Shared\HelpProps;
use App\Http\Requests\Console\IssueEnvironmentKeyRequest;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleStepUp;
use App\Platform\Console\KeyTabs;
use App\Platform\Console\Vocabulary;
use App\Platform\Enums\KeyLifetime;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\EnvironmentKeyScopes;
use App\Platform\Help\HelpTopic;
use App\Platform\OrganizationActivity;
use Carbon\CarbonImmutable;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Enums\EnvironmentApiScope;
use Cbox\Id\Platform\Models\EnvironmentApiKey;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\ValueObjects\IssuedEnvironmentApiKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * KEYS › MANAGEMENT KEYS — the machine credentials (`cbid_env_…`) apps use to provision
 * organizations and users inside ONE environment.
 *
 * ON BOTH CONSOLES. The workspace console issues them for any environment the person may
 * reach, with the environment in the URL; the environment console issues them for the
 * environment it stands on, which is the one a developer working there needs and could
 * only get by going back to the workspace on another host. The authority differs by
 * console and so does the audit trail's owner — see {@see self::reachable()} and
 * {@see self::auditScope()} — and nothing else does. Distinct from an account key:
 * an environment key is bound to a single environment and carries fine-grained scopes
 * rather than a role.
 *
 * HIGH PRIVILEGE: a key can provision identities. So only a member who manages
 * environments may mint or revoke one, and only for an environment they can actually
 * reach — {@see self::reachable()} — and both acts are behind a step-up.
 *
 * REVOKING IS GATED TOO, which it was not. A stolen but non-sudo session could not MINT
 * persistence — creation always demanded a fresh password — but it could destroy the
 * machine credentials that run somebody's provisioning and automation, which is a denial
 * of service the same session was otherwise held back from.
 *
 * The plaintext is shown exactly once, on the flash channel: only a hash is stored, and
 * page props are written into the browser's history entry.
 */
final readonly class EnvironmentKeyController extends ConsoleController
{
    public function index(Request $request, Memberships $members, EnvironmentApiKeys $keys, KeyTabs $tabs): Response|RedirectResponse
    {
        /*
         * A READ IS REDIRECTED, A WRITE IS REFUSED, and the difference is deliberate.
         *
         * Somebody arriving here who may not manage environments is somebody who followed
         * a link or typed a URL — send them where they CAN be, which is the console's own
         * answer everywhere else and the one ConsoleNavHonestyTest holds us to. A write
         * that fails the same question is not a navigation mistake, and there is nothing
         * to send them to: it is refused.
         */
        if (! $this->mayManageEnvironments()) {
            return to_route('projects');
        }

        /*
         * ON THE ENVIRONMENT CONSOLE, THE AGENTS PAGE IS THIS PAGE. Its management keys are
         * listed — with their approval policy, the keys they minted and a Rotate — under
         * AI agents › Agents, and two lists of one credential would drift apart the first
         * time either learned something. The URL is kept, and lands there.
         */
        if ($this->onEnvironmentPlane()) {
            return to_route('environment.agents');
        }

        $reachable = $this->reachable($members);
        $environments = Environment::query()->whereIn('id', $reachable)->orderBy('created_at')->get(['id', 'name']);

        /*
         * The chosen environment travels in the URL rather than in component state: it
         * decides which keys are listed and which environment a new key is minted for, so
         * it belongs in a link somebody can send, bookmark and come back to.
         */
        $selected = trim($request->string('environment')->toString());

        if (! in_array($selected, $reachable, true)) {
            $selected = (string) ($environments->first()->id ?? '');
        }

        $now = CarbonImmutable::now();

        // Workspace › Keys — one line from My account › API keys, which is a person's OWN
        // keys and a different page; the same word twice in one rail would send people to
        // the wrong one. (The environment console's keys are its Agents page, above.)
        return $this->page('console/keys/management', Vocabulary::API_KEYS, [
            'help' => HelpProps::for(HelpTopic::Keys),
            'tabs' => $tabs->for(KeyTabs::MANAGEMENT),
            // Any environment of the workspace this person may reach.
            'pickEnvironment' => true,
            'environments' => $environments->map(fn (Environment $environment): array => [
                'id' => $environment->id,
                'name' => $environment->name,
            ])->all(),
            'selected' => $selected,
            /*
             * EVERY key, revoked and expired included — the framework returns them on
             * purpose, as the audit list — each carrying its own status. They used to be
             * drawn exactly like live keys, with a Revoke button on a key nothing could
             * use any more.
             */
            'keys' => $selected === '' ? [] : $keys->forEnvironment($selected)
                ->map(fn (EnvironmentApiKey $key): EnvironmentKeyRowProps => EnvironmentKeyRowProps::from(
                    $key,
                    $this->url('keys.destroy', $key->id),
                    $now,
                ))
                ->values()
                ->all(),
            // What the form offers: labelled, with the API's own key beside each, and
            // without the reserved scopes no route requires. Writes are marked, because
            // that is the difference that matters when somebody is ticking boxes for a
            // credential that can provision people.
            'scopes' => array_map(EnvironmentScopeProps::offered(...), EnvironmentKeyScopes::offered()),
            'lifetimes' => array_map(
                fn (KeyLifetime $lifetime): array => ['value' => $lifetime->value, 'label' => $lifetime->label()],
                KeyLifetime::cases(),
            ),
            // An admin opts INTO write explicitly; the form opens read-only.
            'defaultScopes' => [
                EnvironmentApiScope::OrganizationsRead->value,
                EnvironmentApiScope::UsersRead->value,
            ],
            'storeHref' => $this->url('keys.store'),
        ]);
    }

    public function store(
        IssueEnvironmentKeyRequest $request,
        Memberships $members,
        EnvironmentApiKeys $keys,
        OrganizationActivity $activity,
    ): RedirectResponse {
        $this->assertMayManageEnvironments();

        // Resolved BEFORE the write, so there is no path that mints a key and then finds
        // it has nowhere to record who did.
        $auditScope = $this->auditScope();

        $environmentId = $request->environmentId();

        // Reachability is part of the authorization, not a lookup after it: an id the
        // caller cannot reach must not become a key for an environment they cannot see.
        abort_unless(in_array($environmentId, $this->reachable($members), true), 403);

        /*
         * AUTHORIZATION FIRST, THEN THE STEP-UP. It ran the other way round, which handed
         * a member who may not mint anything a password prompt and then refused them in
         * silence once they had typed it — and taught everybody else that the prompt is
         * something you get past rather than something that means what it says.
         */
        $sudo = $this->stepUp(
            'A management key reads and writes this environment\'s organizations and people over the API, and its value is shown once.',
        );

        if ($sudo !== null) {
            return to_route($sudo);
        }

        // Both consoles mint through an ACTION — the one the API and MCP run, with the same
        // refusals, attribution and audit entry: the environment console through its own
        // plane's `keys.create`, the workspace console through the workspace plane's
        // `keys.environment.create`, for any environment of the workspace it can reach.
        $fields = [
            'name' => 'name',
            'scopes' => 'scopes',
            'expires_at' => 'expiresOn',
            'description' => 'description',
            'require_approval' => 'approval',
            'require_approval.min_danger' => 'approval',
            'require_approval.actions' => 'approvalActions',
        ];
        $input = [
            'name' => $request->name(),
            'scopes' => $request->scopes(),
            'expires_at' => $request->expiresAt()?->toIso8601String(),
        ];

        // What it is for and which of its actions wait for approval: the agent create flow
        // on this console. `keys.create` takes both; the workspace plane's action takes
        // neither, so its form never offers them.
        $result = $this->onEnvironmentPlane()
            ? $this->act(CreateKey::class, [
                ...$input,
                'description' => $request->description(),
                'require_approval' => $request->requireApproval(),
            ], $fields, 'name')
            : $this->act(CreateEnvironmentKey::class, ['environment_id' => $environmentId, ...$input], $fields, 'name');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        /** @var IssuedEnvironmentApiKey $minted */
        $minted = $result->value;
        $this->inertia->flash('freshKey', $minted->plaintext);
        $this->inertia->flash('freshKeyName', $minted->key->name);

        return back()->with('status', 'Management key created — copy it now, it will not be shown again.');
    }

    public function destroy(
        Request $request,
        string $key,
        Memberships $members,
        EnvironmentApiKeys $keys,
        EnvironmentContext $environments,
        OrganizationActivity $activity,
    ): RedirectResponse {
        $this->assertMayManageEnvironments();

        $auditScope = $this->auditScope();

        $environmentId = trim($request->string('environment')->toString());

        abort_unless(in_array($environmentId, $this->reachable($members), true), 403);

        $sudo = $this->stepUp('Revoking a management key stops whatever is using it, immediately.');

        if ($sudo !== null) {
            return to_route($sudo);
        }

        // Only revoke a key that belongs to the named — and reachable — environment.
        //
        // One row, read INSIDE that environment's scope — the same fence `forEnvironment()`
        // draws, without hydrating every key the environment ever minted to find one. A
        // key of another environment is simply not in this scope, so it is a 404 rather
        // than a quiet bounce.
        $found = $environments->runAs(
            GenericEnvironment::of($environmentId),
            fn (): ?EnvironmentApiKey => EnvironmentApiKey::query()->whereKey($key)->first(),
        );

        abort_if($found === null, 404);

        // An already-revoked key stays revoked and records nothing new: the log is the act
        // that stopped it, not every request naming a row that no longer offers one.
        if ($found->revoked_at !== null) {
            return back();
        }

        // The action revokes the key AND every key it minted, and records each — the
        // environment plane's on the environment console, the workspace plane's from the
        // workspace console.
        $result = $this->onEnvironmentPlane()
            ? $this->act(RevokeKey::class, ['id' => $key])
            : $this->act(RevokeEnvironmentKey::class, ['environment_id' => $environmentId, 'id' => $key]);

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Management key revoked.');
    }

    /**
     * Rotate one of this environment's keys: a successor with the same name, scopes and
     * approval policy, shown once, while the old key keeps working for a day so whatever
     * holds it can switch without an outage.
     *
     * The environment console's alone, through `keys.rotate` — the action the API and MCP
     * run. Minting a credential, so behind the same step-up as minting one.
     */
    public function rotate(string $key): RedirectResponse
    {
        $this->assertMayManageEnvironments();
        abort_unless($this->onEnvironmentPlane(), 404);

        $sudo = $this->stepUp('Rotating a management key mints its successor, whose value is shown once.');

        if ($sudo !== null) {
            return to_route($sudo);
        }

        $result = $this->act(RotateKey::class, ['id' => $key]);

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        /** @var IssuedEnvironmentApiKey $minted */
        $minted = $result->value;
        $this->inertia->flash('freshKey', $minted->plaintext);
        $this->inertia->flash('freshKeyName', $minted->key->name);

        return back()->with('status', 'Key rotated — copy the new one now. The old key keeps working for 24 hours.');
    }

    /**
     * The account whose activity log records a key being minted or revoked.
     *
     * UNCONDITIONAL, and that is the change. Both writes recorded only `if` an
     * organization was resolved, and skipped the entry in silence otherwise — a credential
     * that provisions people, minted with no line anywhere saying who did it. Today no
     * request reaches a write without one ({@see self::reachable()} answers nothing
     * without an organization, and the write is refused), but that is a property of a
     * different method: this makes the audit a requirement of the write itself, so a
     * later change to reachability cannot turn it back into an optional extra.
     */
    private function auditScope(): string
    {
        // On the environment console the acting organization is one of the ENVIRONMENT's
        // organizations — somebody else's customer — and a key minted here is still the
        // workspace's act. So it is recorded where the workspace console records it: under
        // the workspace whose membership opened this console.
        if ($this->onEnvironmentPlane()) {
            $organizationId = app(EnvironmentAdminAuth::class)->membership()?->organization_id;

            abort_unless(is_string($organizationId) && $organizationId !== '', 403);

            return $organizationId;
        }

        return $this->scope->requireOrganizationId();
    }

    /**
     * ONE QUESTION. It used to be two — the scope decided ADMISSION and a member row was
     * what the page READ — because authority lived in two places and the scope answering
     * yes did not hand the row over. The scope answers both.
     *
     * For WRITES only; {@see self::index()} answers the same question with a redirect.
     */
    private function assertMayManageEnvironments(): void
    {
        abort_unless($this->mayManageEnvironments(), 403);
    }

    /**
     * On the environment console, holding it IS the capability: its session resolves only
     * for a workspace member who may manage environments and reaches this one, re-checked
     * on every request ({@see EnvironmentAdminAuth::membership()}). The workspace console
     * asks the membership's capability directly.
     */
    private function mayManageEnvironments(): bool
    {
        return $this->onEnvironmentPlane()
            ? app(EnvironmentAdminAuth::class)->check()
            : $this->scope->capabilities()?->canManageEnvironments() === true;
    }

    private function onEnvironmentPlane(): bool
    {
        return $this->scope->plane() === ConsolePlane::Environment;
    }

    /**
     * The environments this administrator may actually reach.
     *
     * IN THE PLATFORM ROOT. `memberships` is environment-owned and the console is served on
     * whichever host the deployment puts it on — so asked directly this answers "no
     * environments" for somebody who reaches several, and the page silently shows them
     * nothing.
     *
     * @return list<string>
     */
    private function reachable(Memberships $members): array
    {
        // The environment console administers exactly the environment it stands on. Its
        // session is anchored to that environment and refused on any other host, so the
        // anchor is the whole answer — and nothing in the request can widen it.
        if ($this->onEnvironmentPlane()) {
            $auth = app(EnvironmentAdminAuth::class);
            $environmentId = $auth->environmentId();

            return $auth->check() && $environmentId !== null ? [$environmentId] : [];
        }

        $organizationId = $this->scope->organizationId();
        $actorId = $this->scope->actorId();

        if ($organizationId === null || $actorId === '') {
            return [];
        }

        return app(PlatformRoot::class)->run(
            fn (): array => $members->accessibleEnvironmentIds($organizationId, $actorId),
        ) ?? [];
    }

    /**
     * The step-up route to send this administrator to, or null when the window is open.
     *
     * The REASON is required rather than decorative: the step-up screen otherwise says
     * "this is a protected action", which is the sentence people learn to type a password
     * past.
     */
    private function stepUp(string $reason): ?string
    {
        return app(ConsoleStepUp::class)->challenge(
            'keys',
            // Back to where the environment console's keys are now listed.
            'environment.agents',
            [],
            $reason,
        );
    }
}
