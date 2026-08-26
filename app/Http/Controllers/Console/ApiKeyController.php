<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Requests\Console\IssueApiKeyRequest;
use App\Platform\StepUpReason;
use App\Platform\Sudo;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Cbox\Id\Platform\Models\OrganizationApiKey;
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
 */
final readonly class ApiKeyController extends ConsoleController
{
    public function index(OrganizationApiKeys $keys): Response|RedirectResponse
    {
        if ($this->scope->capabilities()?->canManageMembers() !== true) {
            // Somebody arriving where they may not go is sent somewhere they can be, which
            // is the console's own answer and the one the navigation-honesty test holds.
            return to_route('projects');
        }

        $organizationId = $this->scope->organizationId();

        return $this->page('console/api-keys', 'API keys', [
            'keys' => $organizationId === null ? [] : $keys->forOrganization($organizationId)
                ->map(fn (OrganizationApiKey $key): array => [
                    'id' => $key->id,
                    'name' => $key->name,
                    'role' => $key->role->label(),
                    'prefix' => $key->prefix,
                    'active' => $key->isActive(),
                    // ISO, rendered relative in the browser: "last used 3 minutes ago"
                    // computed on the server is wrong the moment the page sits open, and
                    // this is a page people leave open while a deploy runs.
                    'lastUsedAt' => $key->last_used_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'roles' => array_map(
                fn (MembershipRole $role): array => ['value' => $role->value, 'label' => $role->label()],
                MembershipRole::assignable(),
            ),
        ]);
    }

    public function store(IssueApiKeyRequest $request, OrganizationApiKeys $keys): RedirectResponse
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

        $issued = $keys->issue($organizationId, $request->name(), $request->role());

        /*
         * The plaintext, on the flash channel and nowhere else. Props are written into the
         * browser's history entry; a full-authority credential there is readable by
         * pressing Back, long after the page that showed it has gone.
         */
        $this->inertia->flash('freshKey', $issued->plaintext);

        return back();
    }

    public function destroy(string $key, OrganizationApiKeys $keys): RedirectResponse
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

        $keys->revoke($key);

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

        $intended = route('api-keys');

        session()->put('sudo.intended', $intended);
        StepUpReason::record('sudo', $reason, $intended);

        return to_route('sudo');
    }
}
