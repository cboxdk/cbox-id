<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Shared\HelpProps;
use App\Platform\Actions\ActionRegistry;
use App\Platform\Actions\Approvals\ActionApprovalRequest;
use App\Platform\Actions\RenamedActions;
use App\Platform\Console\ConsoleStepUp;
use App\Platform\CurrentUser;
use App\Platform\Help\HelpTopic;
use Cbox\Id\OAuthServer\CibaActionApprovals;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\OAuthServer\Contracts\ProtectedResources;
use Cbox\Id\OAuthServer\Models\BackchannelAuthRequest;
use Cbox\Id\OAuthServer\Models\Client;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;
use InvalidArgumentException;

/**
 * AGENT APPROVALS — MINE. The other side of the environment console's queue: this is where
 * a person answers a request for an agent to act AS THEM.
 *
 * WHICH IS WHY THIS PAGE HAS AN APPROVE BUTTON AND THE OPERATOR'S DOES NOT. A CIBA approval
 * is consent, and the token that follows is minted for the person consenting — so it can
 * only be given by them. The service enforces that (approval requires the acting subject to
 * BE the request's subject); this page is simply the one place where those two are the same
 * person.
 *
 * Every read and write is bound to the acting subject rather than filtered by it, so a
 * request belonging to somebody else does not resolve at all.
 */
final readonly class MyApprovalController extends ConsoleController
{
    /**
     * What a scope MEANS, in the words of the person it concerns.
     *
     * This screen asks somebody to consent, and `offline_access` is not a thing anybody can
     * consent to. Unlike the operator's queue, the raw scope is NOT shown beside it: the
     * reader here is the account holder, and a technical string next to a plain sentence
     * invites them to assume the sentence is a simplification of something they should be
     * worried about.
     */
    private const SCOPE_LABELS = [
        'openid' => 'console.verify_your_identity_ed32a9b2',
        'profile' => 'console.your_name_2c6b2e25',
        'email' => 'console.your_email_address_3fa7c5ef',
        'offline_access' => 'console.stay_signed_in_b0a1ca80',
    ];

    public function index(): Response
    {
        $me = app(CurrentUser::class);

        $requests = BackchannelAuthRequest::query()
            ->where('user_id', $me->id())
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->get();

        // One lookup for the page rather than one per row: this used to ask the registry
        // inside the map, which is a query per request on a screen that exists to be
        // answered quickly.
        $names = [];
        $selfRegistered = [];

        $clients = Client::query()
            ->whereIn('client_id', $requests->pluck('client_id')->unique()->all())
            ->get(['client_id', 'name', 'registration_access_token_hash']);

        foreach ($clients as $client) {
            $names[(string) $client->client_id] = (string) $client->name;
            $selfRegistered[(string) $client->client_id] = $client->isDynamicallyRegistered();
        }

        $resources = app(ProtectedResources::class);

        return $this->page('console/approvals', __('console.approvals_2bfc3471'), [
            'requests' => $requests->map(function (BackchannelAuthRequest $request) use ($names, $selfRegistered, $resources): array {
                $clientId = (string) $request->client_id;
                $resource = is_string($request->resource) && $request->resource !== '' ? $request->resource : null;

                return [
                    'id' => $request->id,
                    'app' => $names[$clientId] ?? $clientId,
                    // A self-registered client named ITSELF: anybody who reached the
                    // registration endpoint could have picked that name. The card says so,
                    // so the name is read as a claim and the binding message does the work.
                    'selfRegistered' => $selfRegistered[$clientId] ?? false,
                    // What the token will be for (RFC 8707). The operator's name for it when
                    // there is one, and the identifier always, so a lookalike name cannot
                    // hide a different service.
                    'resource' => $resource === null ? null : [
                        'identifier' => $resource,
                        'name' => $resources->find($resource)?->name,
                    ],
                    'bindingMessage' => $request->binding_message,
                    'scopes' => array_map(static fn (string $scope): array => [
                        'value' => $scope,
                        'label' => isset(self::SCOPE_LABELS[$scope]) ? __(self::SCOPE_LABELS[$scope]) : $scope,
                    ], array_values(array_filter($request->scopes, 'is_string'))),
                    'urls' => [
                        'approve' => route('approvals.approve', $request->id),
                        'deny' => route('approvals.deny', $request->id),
                    ],
                ];
            })->all(),
            'help' => HelpProps::for(HelpTopic::AgentApprovals),
        ]);
    }

    public function approve(string $request, BackchannelAuthentication $backchannel, ActionRegistry $registry): RedirectResponse
    {
        $me = app(CurrentUser::class);

        // A HELD ACTION asks what the environment console's queue asks
        // ({@see AgentApprovalController::approveAction()}): approving a Critical one hands an
        // agent a credential, a factor's removal or the way people sign in, and the action's
        // own console button is behind the step-up. Without this, a borrowed session that
        // cannot pass the step-up could have a token run the action and approve it here.
        $row = BackchannelAuthRequest::query()->whereKey($request)->where('user_id', $me->id())->first();

        if ($row !== null && $row->getAttribute('purpose') === CibaActionApprovals::PURPOSE) {
            $action = ActionApprovalRequest::query()->whereKey($row->getKey())->value('action');

            try {
                $definition = is_string($action) ? $registry->named($action) : null;
            } catch (InvalidArgumentException) {
                $definition = null;
            }

            if (AgentApprovalController::needsStepUp($definition)) {
                $sudo = app(ConsoleStepUp::class)->challenge(
                    'approvals',
                    'approvals',
                    [],
                    __('console.approving_lets_an_agent_run_a_critical_action_action_81ec66e8', ['action' => is_string($action) ? RenamedActions::current($action) : '?']),
                );

                if ($sudo !== null) {
                    return to_route($sudo);
                }
            }
        }

        // Bound to the acting subject rather than checked against it: a request belonging to
        // somebody else is refused by the service, which is where that rule belongs.
        $backchannel->approve($request, $me->id(), $me->organizationId());

        return back()->with('status', __('console.request_approved_cab80f7b'));
    }

    public function deny(string $request, BackchannelAuthentication $backchannel): RedirectResponse
    {
        $backchannel->deny($request, app(CurrentUser::class)->id());

        return back()->with('status', __('console.request_denied_8b558b83'));
    }
}
