<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Requests\Console\IssueEnvironmentKeyRequest;
use App\Platform\Console\ConsoleStepUp;
use App\Platform\OrganizationActivity;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Enums\EnvironmentApiScope;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * CONSOLE › ENVIRONMENT KEYS — the machine credentials (`cbid_env_…`) apps use to
 * provision organizations and users inside ONE environment. Distinct from an account key:
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
    public function index(Request $request, Memberships $members, EnvironmentApiKeys $keys): Response|RedirectResponse
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
        if ($this->scope->capabilities()?->canManageEnvironments() !== true) {
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

        return $this->page('console/environment-keys', 'Environment keys', [
            'environments' => $environments->map(fn (Environment $environment): array => [
                'id' => $environment->id,
                'name' => $environment->name,
            ])->all(),
            'selected' => $selected,
            'keys' => $selected === '' ? [] : $keys->forEnvironment($selected)
                ->map(fn (object $key): array => [
                    'id' => $key->id,
                    'name' => $key->name,
                    'scopes' => $key->scopes,
                    'lastUsedAt' => $key->last_used_at?->diffForHumans(),
                    'revokeHref' => $this->url('environment-keys.destroy', $key->id),
                ])
                ->values()
                ->all(),
            'scopes' => array_map(static fn (EnvironmentApiScope $scope): array => [
                'value' => $scope->value,
                // Read scopes first, and marked, because that is the difference that
                // matters when somebody is ticking boxes for a credential that can
                // provision people.
                'writes' => ! str_ends_with($scope->value, ':read'),
            ], EnvironmentApiScope::cases()),
            // An admin opts INTO write explicitly; the form opens read-only.
            'defaultScopes' => [
                EnvironmentApiScope::OrganizationsRead->value,
                EnvironmentApiScope::UsersRead->value,
            ],
            'storeHref' => $this->url('environment-keys.store'),
        ]);
    }

    public function store(
        IssueEnvironmentKeyRequest $request,
        Memberships $members,
        EnvironmentApiKeys $keys,
        OrganizationActivity $activity,
    ): RedirectResponse {
        $this->assertMayManageEnvironments();

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
            'An environment API key reads and writes this environment\'s organizations and people over the API, and its value is shown once.',
        );

        if ($sudo !== null) {
            return to_route($sudo);
        }

        $issued = $keys->issue($environmentId, $request->name(), $request->scopes());

        $organizationId = $this->scope->organizationId();

        if ($organizationId !== null) {
            $activity->record(
                $organizationId,
                'organization.environment_key_created',
                $this->scope->actorId(),
                targetType: 'environment',
                targetId: $environmentId,
                context: ['name' => $request->name(), 'scopes' => $request->scopes()],
                request: $request,
            );
        }

        $this->inertia->flash('freshKey', $issued->plaintext);

        return back()->with('status', 'Environment key issued — copy it now, it will not be shown again.');
    }

    public function destroy(
        Request $request,
        string $key,
        Memberships $members,
        EnvironmentApiKeys $keys,
        OrganizationActivity $activity,
    ): RedirectResponse {
        $this->assertMayManageEnvironments();

        $environmentId = trim($request->string('environment')->toString());

        abort_unless(in_array($environmentId, $this->reachable($members), true), 403);

        $sudo = $this->stepUp('Revoking an environment key stops whatever is using it, immediately.');

        if ($sudo !== null) {
            return to_route($sudo);
        }

        // Only revoke a key that belongs to the named — and reachable — environment.
        if ($keys->forEnvironment($environmentId)->firstWhere('id', $key) === null) {
            return back();
        }

        $keys->revoke($environmentId, $key);

        $organizationId = $this->scope->organizationId();

        if ($organizationId !== null) {
            $activity->record(
                $organizationId,
                'organization.environment_key_revoked',
                $this->scope->actorId(),
                targetType: 'environment',
                targetId: $environmentId,
                context: ['key_id' => $key],
                request: $request,
            );
        }

        return back()->with('status', 'Environment key revoked.');
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
        abort_unless($this->scope->capabilities()?->canManageEnvironments() === true, 403);
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
            'environment-keys',
            'environment.environment-keys',
            [],
            $reason,
        );
    }
}
