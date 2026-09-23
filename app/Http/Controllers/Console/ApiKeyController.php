<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Console\ApiKeyRowProps;
use App\Http\Requests\Console\IssueApiKeyRequest;
use App\Platform\Console\KeyTabs;
use App\Platform\Enums\KeyLifetime;
use App\Platform\OrganizationActivity;
use App\Platform\StepUpReason;
use App\Platform\Sudo;
use Carbon\CarbonImmutable;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Cbox\Id\Platform\Models\OrganizationApiKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        return $this->page('console/keys/workspace', 'Keys', [
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

    public function store(IssueApiKeyRequest $request, OrganizationApiKeys $keys, OrganizationActivity $activity): RedirectResponse
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
            'An account API key acts with this role across your whole account, and its value is shown once.',
        );

        if ($challenge !== null) {
            return $challenge;
        }

        $issued = $keys->issue($organizationId, $request->name(), $request->role(), $request->expiresAt());

        $activity->record(
            $organizationId,
            'organization.api_key_created',
            $this->scope->actorId(),
            targetType: 'api_key',
            targetId: $issued->key->id,
            context: [
                'name' => $issued->key->name,
                'role' => $issued->key->role->value,
                'expires_at' => $issued->key->expires_at?->toIso8601String(),
            ],
            request: $request,
        );

        /*
         * The plaintext, on the flash channel and nowhere else. Props are written into the
         * browser's history entry; a full-authority credential there is readable by
         * pressing Back, long after the page that showed it has gone.
         */
        $this->inertia->flash('freshKey', $issued->plaintext);

        return back()->with('status', 'API key created — copy it now, it will not be shown again.');
    }

    public function destroy(Request $request, string $key, OrganizationApiKeys $keys, OrganizationActivity $activity): RedirectResponse
    {
        $organizationId = $this->scope->organizationId();

        abort_if($organizationId === null, 403);
        abort_unless($this->scope->capabilities()?->canManageMembers() === true, 403);

        $challenge = $this->stepUp('Revoking an API key stops whatever is using it, immediately.');

        if ($challenge !== null) {
            return $challenge;
        }

        // Only a key that belongs to THIS organization. The id comes from the URL, and a
        // revoke by id alone would let one account's administrator stop another's
        // automation.
        $found = $keys->forOrganization($organizationId)->firstWhere('id', $key);

        if ($found === null) {
            return back();
        }

        // A key that is already revoked stays one, and says nothing new: the log records
        // the act that stopped it, not every click on a row that no longer offers one.
        if ($found->revoked_at !== null) {
            return back();
        }

        $keys->revoke($key);

        $activity->record(
            $organizationId,
            'organization.api_key_revoked',
            $this->scope->actorId(),
            targetType: 'api_key',
            targetId: $found->id,
            context: ['name' => $found->name],
            request: $request,
        );

        return back()->with('status', 'API key revoked.');
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
