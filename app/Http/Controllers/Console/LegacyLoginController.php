<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\LegacyLogin\ApproveLegacyLogin;
use App\Actions\LegacyLogin\ProbeLegacyLogin;
use App\Actions\LegacyLogin\RevokeLegacyLogin;
use App\Http\Props\Shared\HelpProps;
use App\Http\Requests\Console\ProbeLegacyLoginRequest;
use App\Platform\EnvironmentSudo;
use App\Platform\Help\HelpTopic;
use App\Platform\Migration\LegacyLoginApprovals;
use Cbox\Id\OAuthServer\Models\Client;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * CONSOLE › LEGACY LOGIN — approving the old sign-in endpoint an app has declared, so
 * people who have not moved to Cbox ID yet can still get in while a migration runs.
 *
 * THIS PAGE IS THE HUMAN IN THE LOOP, and it exists because of one asymmetry: everything
 * else an app declares in its manifest affects only that app, while this names a URL that
 * every unknown email — and the password typed with it — will be offered to, on this
 * environment's whole sign-in path. A client holding `apps.manifest` that could switch that
 * on by itself would be a credential harvester with a scope for the purpose.
 *
 * So the page's job is not to make approval convenient. It is to make sure the person
 * clicking knows exactly what they are agreeing to: which app asked, what URL, and what
 * happens to people who are already migrated. All three are on screen before the button.
 *
 * EVERY WRITE ASKS FOR A FRESH STEP-UP, in both directions. Approving is the click that
 * redirects where passwords go; withdrawing is the one that locks every un-migrated person
 * out of the product, which is an outage an attacker would choose the timing of. The step-up
 * is the console's, asked here before the write; the write itself is the ACTION
 * (`App\Actions\LegacyLogin\*`) the management API runs, which records who did it.
 */
final readonly class LegacyLoginController extends ConsoleController
{
    public function show(LegacyLoginApprovals $approvals): Response
    {
        $this->scope->assertMayAdministerEnvironment();

        $declaration = $approvals->current();

        return $this->page('console/legacy-login', 'Legacy login', [
            'help' => HelpProps::for(HelpTopic::LegacyLogin),
            'declaration' => $declaration === null ? null : [
                'url' => $declaration->url,
                'approved' => $declaration->isApproved(),
                'approvedAt' => $declaration->approved_at?->diffForHumans(),
                /*
                 * NAMED rather than shown as an id: "this came from Acme Web" is what an
                 * operator can judge; an opaque id is something they have to take on trust.
                 *
                 * `where('client_id', …)` and NOT `whereKey()`. A Client has both — `id`
                 * is the row's primary key and `client_id` is the OAuth identifier — and
                 * the declaration stores the latter. `whereKey()` looked up the former, so
                 * it matched nothing, and this page told every operator who ever opened it
                 * that the app which asked "no longer exists". The one field whose whole
                 * job is to say who is asking.
                 */
                'declaredBy' => Client::query()
                    ->where('client_id', $declaration->client_id)
                    ->value('name'),
            ],
            'urls' => [
                'probe' => $this->url('legacy-login.probe'),
                'approve' => $this->url('legacy-login.approve'),
                'revoke' => $this->url('legacy-login.revoke'),
            ],
        ]);
    }

    /**
     * Ask the declared endpoint whether it is alive, before anybody approves it.
     *
     * Without this an operator approves blind, and the first thing to discover that the
     * endpoint does not answer is a real person's login — failing closed, so they simply
     * cannot sign in and nobody knows why.
     */
    public function probe(ProbeLegacyLoginRequest $request): RedirectResponse
    {
        $this->scope->assertMayAdministerEnvironment();

        $result = $this->act(ProbeLegacyLogin::class, ['email' => $request->email()], ['email' => 'email'], 'email');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        // The RESULT is a sentence about somebody's account at another system, so it rides
        // the flash channel rather than being written into the history entry.
        $this->inertia->flash('probeResult', $result->value);

        return back();
    }

    public function approve(): RedirectResponse
    {
        $this->scope->assertMayAdministerEnvironment();
        $this->assertFreshStepUp();

        $result = $this->act(ApproveLegacyLogin::class, []);

        return $result instanceof RedirectResponse
            ? $result
            : back()->with('status', 'Approved. Sign-ins for people who are not in Cbox ID yet now go to that endpoint.');
    }

    public function revoke(): RedirectResponse
    {
        $this->scope->assertMayAdministerEnvironment();
        $this->assertFreshStepUp();

        $result = $this->act(RevokeLegacyLogin::class, []);

        return $result instanceof RedirectResponse
            ? $result
            : back()->with('status', 'Withdrawn. People already migrated are unaffected; anyone still on the old system cannot sign in.');
    }

    /**
     * Refuse a write from a session that has not re-confirmed recently.
     *
     * ASKED HERE AND NOT ONLY ON THE ROUTE. Route middleware gates the page load; these are
     * separate requests that a window opened before the confirmation expired can still
     * make. This is the one click in the console that redirects where passwords go.
     *
     * @throws AuthorizationException
     */
    private function assertFreshStepUp(): void
    {
        if (! app(EnvironmentSudo::class)->confirmed()) {
            throw new AuthorizationException('Confirm it is you before changing where sign-ins are sent.');
        }
    }
}
