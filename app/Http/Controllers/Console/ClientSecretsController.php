<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\Apps\RevokeAppSecret;
use App\Actions\Apps\RotateAppSecret;
use App\Http\Props\Console\ClientSecretProps;
use App\Http\Requests\Console\RotateClientSecretRequest;
use App\Platform\Actions\ActionRefused;
use App\Platform\Console\AppHeader;
use App\Platform\Console\AppTabs;
use App\Platform\Console\ConsoleClients;
use App\Platform\Console\ConsoleScope;
use App\Platform\Console\ConsoleStepUp;
use App\Platform\Enums\SecretGrace;
use Carbon\CarbonImmutable;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\ClientSecretSummary;
use Cbox\Id\OAuthServer\ValueObjects\RotatedClientSecret;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Inertia\ResponseFactory;

/**
 * AN APP › SECRETS — the secrets an app authenticates with, rotated with an overlap and
 * revoked one at a time.
 *
 * ROTATION IS NO LONGER A CUT-OVER. It used to be: the new secret existed and the old one
 * was dead in the same instant, so every deployment still holding it failed until somebody
 * redeployed all of them. The registry keeps the replaced secret alive for a grace period
 * the administrator chooses ({@see SecretGrace}), so the order is the safe one — rotate,
 * roll the new secret out, and let the old one run out — and a secret that has leaked can
 * still be cut off at once, by choosing "immediately" or by revoking it here.
 *
 * THE PLAINTEXT IS SHOWN ONCE, on the flash channel: only a hash is stored, and page props
 * are written into the browser's history entry, where a live credential is retrievable by
 * pressing Back long after the page that showed it has gone.
 *
 * BOTH WRITES ARE ACTIONS (`apps.secrets.rotate`, `apps.secrets.revoke`) — the same ones the
 * management API runs, so the registry's refusals and the `app.secret_*` trail are one thing
 * whichever door asked. What stays here is the console's: the refusals said before a
 * password prompt rather than after it, the step-up, and the reveal on the flash channel.
 *
 * BOTH WRITES ARE BEHIND A STEP-UP, after authorization and after every refusal. Rotation
 * mints a live credential and puts it on screen; revoking is the other half of the same
 * power — a stolen session that cannot mint a secret must not be able to cut an app's
 * production deployment off either, which is how the management keys are gated too.
 */
final readonly class ClientSecretsController extends ConsoleController
{
    public function __construct(
        ResponseFactory $inertia,
        ConsoleScope $scope,
        private ConsoleClients $clients,
    ) {
        parent::__construct($inertia, $scope);
    }

    public function index(Request $request, string $client, AppHeader $header, ClientRegistry $registry): Response
    {
        $model = $this->holder($client);
        $now = CarbonImmutable::now();
        $secrets = $registry->secrets($model);
        $revocable = count($secrets) > 1;

        return $this->page('console/clients/secrets', $model->name, [
            'appHeader' => $header->for($model, AppTabs::SECRETS),
            'secrets' => array_map(
                fn (ClientSecretSummary $secret): ClientSecretProps => ClientSecretProps::from(
                    $secret,
                    $revocable ? $this->url('clients.secrets.revoke', ['client' => $model->id, 'secret' => $secret->id]) : null,
                    $now,
                ),
                $secrets,
            ),
            'graces' => array_map(
                static fn (SecretGrace $grace): array => ['value' => $grace->value, 'label' => $grace->label()],
                SecretGrace::offered(),
            ),
            'rotateHref' => $this->url('clients.rotate', $model->id),
            /*
             * A STEP-UP THAT HAS JUST BEEN CLEARED, said out loud.
             *
             * Rotation sends the administrator to re-enter their password and the console
             * brings them back HERE — to a page that looks exactly as it did, with nothing
             * rotated and nothing explaining why. People concluded it was broken and did
             * the whole thing again.
             *
             * The page does not resume the rotation on its own: this is a GET, and a GET
             * that mints a live credential is reachable by a refresh, a prefetch and the
             * Back button. So it says the window is open and the button is pressed once
             * more — one click, on a request that means it.
             */
            'stepUpCleared' => in_array($request->string('confirmed')->toString(), ['rotate', 'revoke'], true),
        ]);
    }

    /**
     * Mint a new secret and retire the current ones after the chosen grace period.
     */
    public function rotate(RotateClientSecretRequest $request, string $client): RedirectResponse
    {
        // Authorization first: a step-up in front of a 403 hands somebody who may not
        // touch this app a password prompt instead of a refusal.
        $model = $this->clients->manageable($client);

        if ($model->type !== ClientType::Confidential) {
            return back()->with('error', __('console.public_apps_use_pkce_and_have_no_secret_to_r_b299056f'));
        }

        /*
         * A private_key_jwt client is Confidential AND has no secret, by construction:
         * a client authenticates EITHER by a shared secret OR by signing assertions, never
         * both. Minting one here does not rotate anything; it ADDS a bearer credential to
         * a client that was asymmetric-only, and the authenticator then accepts client_id
         * plus secret whenever no assertion is presented. A one-click downgrade of an
         * authentication model, offered as routine hygiene.
         */
        if (! AppTabs::holdsSharedSecret($model)) {
            return back()->with('error', __('console.this_app_signs_assertions_with_its_own_keys__7e457dd4'));
        }

        $grace = $request->grace();

        // LAST, after authorization and after the refusals above. Asking for a password
        // and then answering "public apps have no secret to rotate" trains people to type
        // it without reading.
        $sudo = app(ConsoleStepUp::class)->challenge(
            'clients.secrets',
            'environment.clients.secrets',
            // `confirmed` rides back as a query parameter, so the page this returns to can
            // say the window is open. See the `stepUpCleared` prop.
            ['client' => $model->id, 'confirmed' => 'rotate'],
            $grace === SecretGrace::Immediately
                ? __('console.rotating_this_app_s_secret_creates_a_new_one_c5772a3d')
                : __('console.rotating_this_app_s_secret_creates_a_new_one_18dace33').$grace->overlap().'.',
        );

        if ($sudo !== null) {
            return to_route($sudo);
        }

        $result = $this->attempt(RotateAppSecret::class, ['id' => $model->id, 'grace_seconds' => $grace->value], ['grace_seconds' => 'grace'], 'grace');

        if ($result instanceof ActionRefused) {
            return back()->with('error', $result->getMessage());
        }

        /** @var RotatedClientSecret $rotated */
        $rotated = $result->value;

        // The plaintext exists only in this response — see the class comment.
        $this->inertia->flash('revealedSecret', $rotated->secret);

        return back()->with('status', $grace === SecretGrace::Immediately
            ? __('console.secret_rotated_and_the_previous_one_has_stop_14aacb17')
            : __('console.secret_rotated_the_previous_one_keeps_workin_a0dad459').$grace->overlap().__('console.copy_the_new_one_now_it_will_not_be_shown_ag_e926c451'));
    }

    /**
     * Stop one secret working now — a leaked one, or an old one somebody forgot to let run
     * out. Never the app's last live secret: that is rotated, or the app deleted.
     */
    public function revoke(string $client, string $secret, ClientRegistry $registry): RedirectResponse
    {
        $model = $this->clients->manageable($client);

        $live = $registry->secrets($model);
        $target = array_values(array_filter($live, static fn (ClientSecretSummary $summary): bool => $summary->id === $secret));

        // Refused before the step-up, for the same reason rotation's refusals are.
        if ($target === []) {
            return back()->with('error', RevokeAppSecret::NOT_LIVE);
        }

        if (count($live) <= 1) {
            return back()->with('error', RevokeAppSecret::LAST_LIVE);
        }

        $sudo = app(ConsoleStepUp::class)->challenge(
            'clients.secrets',
            'environment.clients.secrets',
            ['client' => $model->id, 'confirmed' => 'revoke'],
            __('console.revoking_a_secret_stops_it_working_immediate_0f24d7b4'),
        );

        if ($sudo !== null) {
            return to_route($sudo);
        }

        // The action asks both questions again, and the registry once more under its own
        // lock: the checks above are the explanation before a password prompt, and between
        // the two a concurrent rotation can still change the answer.
        $result = $this->attempt(RevokeAppSecret::class, ['id' => $model->id, 'secret_id' => $secret]);

        if ($result instanceof ActionRefused) {
            return back()->with('error', $result->getMessage());
        }

        $hint = $target[0]->hint;

        return back()->with('status', $hint !== null
            ? "Secret ending in {$hint} revoked. It no longer works."
            : __('console.secret_revoked_it_no_longer_works_9b2336b2'));
    }

    /**
     * The app, refused unless it holds shared secrets at all. The tab is not drawn for
     * any other app, so a URL for one is a URL for nothing.
     */
    private function holder(string $client): Client
    {
        $model = $this->clients->manageable($client);

        abort_unless(AppTabs::holdsSharedSecret($model), 404);

        return $model;
    }
}
