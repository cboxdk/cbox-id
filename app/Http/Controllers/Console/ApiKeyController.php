<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\Workspace\Keys\CreateWorkspaceKey;
use App\Actions\Workspace\Keys\RevokeWorkspaceKey;
use App\Http\Props\Console\ApiKeyRowProps;
use App\Http\Props\Shared\HelpProps;
use App\Http\Requests\Console\IssueApiKeyRequest;
use App\Platform\Actions\ActionRefused;
use App\Platform\Console\KeyTabs;
use App\Platform\Console\Vocabulary;
use App\Platform\Enums\KeyLifetime;
use App\Platform\Help\HelpTopic;
use App\Platform\StepUpReason;
use App\Platform\Sudo;
use Carbon\CarbonImmutable;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Cbox\Id\Platform\Models\OrganizationApiKey;
use Cbox\Id\Platform\ValueObjects\IssuedOrganizationApiKey;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * IDENTITY PLATFORM › API KEYS — the machine equivalent of a member's session.
 *
 * High privilege by construction: a key carries an assignable ROLE and can do everything
 * that role can, without a session, a second factor, or a person. So only member managers
 * may mint or revoke one, and both are behind a step-up.
 *
 * REVOKING IS GATED TOO, and that is not symmetry for its own sake. A stolen but non-sudo
 * session could not MINT persistence — creation asks for a password — but it could destroy
 * the machine credentials running provisioning and automation, which is a denial of service
 * the same session was otherwise held back from.
 *
 * BOTH ARE ON THE ACCOUNT'S ACTIVITY LOG, which neither was: a credential that acts with a
 * role across the whole account could be minted and destroyed without a line anywhere
 * saying who did it. The environment key page beside this one always recorded both.
 *
 * Both writes are actions (`keys.workspace.create` / `.revoke`), the ones a workspace key
 * runs over `/api/v1/workspace/keys`; the step-up stays here, because a password prompt is
 * the console's ceremony and not part of the act.
 */
final readonly class ApiKeyController extends ConsoleController
{
    public function index(OrganizationApiKeys $keys, KeyTabs $tabs): Response|RedirectResponse
    {
        if ($this->scope->capabilities()?->canManageMembers() !== true) {
            // Somebody arriving where they may not go is sent somewhere they can be, which
            // is the console's own answer and the one the navigation-honesty test holds.
            return to_route('projects');
        }

        $organizationId = $this->scope->organizationId();
        $now = CarbonImmutable::now();

        return $this->page('console/keys/workspace', Vocabulary::API_KEYS, [
            'help' => HelpProps::for(HelpTopic::Keys),
            'tabs' => $tabs->for(KeyTabs::WORKSPACE),
            'keys' => $organizationId === null ? [] : $keys->forOrganization($organizationId)
                ->map(fn (OrganizationApiKey $key): ApiKeyRowProps => ApiKeyRowProps::from(
                    $key,
                    route('keys.workspace.destroy', $key->id),
                    $now,
                ))
                ->values()
                ->all(),
            'roles' => array_map(
                fn (MembershipRole $role): array => ['value' => $role->value, 'label' => $role->label()],
                MembershipRole::assignable(),
            ),
            'lifetimes' => array_map(
                fn (KeyLifetime $lifetime): array => ['value' => $lifetime->value, 'label' => $lifetime->label()],
                KeyLifetime::cases(),
            ),
        ]);
    }

    public function store(IssueApiKeyRequest $request): RedirectResponse
    {
        $organizationId = $this->scope->organizationId();

        /*
         * AUTHORIZATION FIRST, THEN THE STEP-UP.
         *
         * It ran the other way round once, which handed a member who may not mint anything
         * a password prompt and then refused them in silence after they had typed it — and
         * taught everybody else that the prompt is something you get past rather than
         * something that means what it says.
         */
        abort_if($organizationId === null, 403);
        abort_unless($this->scope->capabilities()?->canManageMembers() === true, 403);

        $challenge = $this->stepUp(
            'A workspace key acts with its built-in role across your whole workspace, and its value is shown once.',
        );

        if ($challenge !== null) {
            return $challenge;
        }

        // The action `POST /api/v1/workspace/keys` runs: a key minted here and one minted by
        // another key are recorded alike — and only a key is bounded by its parent.
        $result = $this->act(CreateWorkspaceKey::class, [
            'name' => $request->name(),
            'role' => $request->role()->value,
            'expires_at' => $request->expiresAt()?->toIso8601String(),
        ], ['name' => 'name', 'role' => 'role', 'expires_at' => 'expires'], 'name');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        /** @var IssuedOrganizationApiKey $issued */
        $issued = $result->value;

        /*
         * The plaintext, on the flash channel and nowhere else. Props are written into the
         * browser's history entry; a full-authority credential there is readable by
         * pressing Back, long after the page that showed it has gone.
         */
        $this->inertia->flash('freshKey', $issued->plaintext);

        return back()->with('status', 'Workspace key created — copy it now, it will not be shown again.');
    }

    public function destroy(string $key): RedirectResponse
    {
        $organizationId = $this->scope->organizationId();

        abort_if($organizationId === null, 403);
        abort_unless($this->scope->capabilities()?->canManageMembers() === true, 403);

        $challenge = $this->stepUp('Revoking a workspace key stops whatever is using it, immediately.');

        if ($challenge !== null) {
            return $challenge;
        }

        // Only a key that belongs to THIS organization. The id comes from the URL, and a
        // revoke by id alone would let one account's administrator stop another's
        // automation. The organization predicate is in the query, so a foreign id is not a
        // row this request can see: 404, like every other console lookup. The action fences
        // the id by the workspace once more.
        $found = OrganizationApiKey::query()
            ->where('organization_id', $organizationId)
            ->whereKey($key)
            ->first();

        abort_if($found === null, 404);

        // A key that is already revoked stays one, and says nothing new: the log records
        // the act that stopped it, not every click on a row that no longer offers one.
        if ($found->revoked_at !== null) {
            return back();
        }

        try {
            $this->runAction(RevokeWorkspaceKey::class, ['id' => $found->id]);
        } catch (ActionRefused) {
            return back();
        }

        return back()->with('status', 'Workspace key revoked.');
    }

    /**
     * The step-up in front of both writes, or null when it has already been given.
     *
     * The REASON is required rather than decorative: without it the prompt says "this is a
     * protected action", which is the sentence people learn to type a password past.
     */
    private function stepUp(string $reason): ?RedirectResponse
    {
        if (app(Sudo::class)->confirmed()) {
            return null;
        }

        $intended = route('keys.workspace');

        session()->put('sudo.intended', $intended);
        StepUpReason::record('sudo', $reason, $intended);

        return to_route('sudo');
    }
}
