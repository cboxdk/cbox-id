<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Console\EnvironmentKeyRowProps;
use App\Http\Props\Console\EnvironmentScopeProps;
use App\Http\Requests\Console\IssueEnvironmentKeyRequest;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleStepUp;
use App\Platform\Console\KeyTabs;
use App\Platform\Enums\KeyLifetime;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\EnvironmentKeyScopes;
use App\Platform\OrganizationActivity;
use Carbon\CarbonImmutable;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Enums\EnvironmentApiScope;
use Cbox\Id\Platform\Models\EnvironmentApiKey;
use Cbox\Id\Platform\PlatformRoot;
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

        return $this->page('console/keys/management', 'Keys', [
            'tabs' => $tabs->for(KeyTabs::MANAGEMENT),
            // The environment console mints for the environment it stands on; a picker with
            // one entry would only suggest there was a choice.
            'pickEnvironment' => ! $this->onEnvironmentPlane(),
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
            'scopes' => array_map(EnvironmentScopeProps::from(...), EnvironmentKeyScopes::offered()),
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

        $issued = $keys->issue($environmentId, $request->name(), $request->scopes(), $request->expiresAt());

        $activity->record(
            $auditScope,
            'organization.environment_key_created',
            $this->scope->actorId(),
            targetType: 'environment',
            targetId: $environmentId,
            context: [
                'key_id' => $issued->key->id,
                'name' => $request->name(),
                'scopes' => $request->scopes(),
                'expires_at' => $issued->key->expires_at?->toIso8601String(),
            ],
            request: $request,
        );

        $this->inertia->flash('freshKey', $issued->plaintext);

        return back()->with('status', 'Management key created — copy it now, it will not be shown again.');
    }

    public function destroy(
        Request $request,
        string $key,
        Memberships $members,
        EnvironmentApiKeys $keys,
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
        $found = $keys->forEnvironment($environmentId)->firstWhere('id', $key);

        // An already-revoked key stays revoked and records nothing new: the log is the act
        // that stopped it, not every request naming a row that no longer offers one.
        if ($found === null || $found->revoked_at !== null) {
            return back();
        }

        $keys->revoke($environmentId, $key);

        $activity->record(
            $auditScope,
            'organization.environment_key_revoked',
            $this->scope->actorId(),
            targetType: 'environment',
            targetId: $environmentId,
            context: ['key_id' => $key, 'name' => $found->name],
            request: $request,
        );

        return back()->with('status', 'Management key revoked.');
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
            'environment.keys',
            [],
            $reason,
        );
    }
}
