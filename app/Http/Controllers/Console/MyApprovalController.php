<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Shared\HelpProps;
use App\Platform\CurrentUser;
use App\Platform\Help\HelpTopic;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\OAuthServer\Models\BackchannelAuthRequest;
use Cbox\Id\OAuthServer\Models\Client;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

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
        'openid' => 'Verify your identity',
        'profile' => 'Your name',
        'email' => 'Your email address',
        'offline_access' => 'Stay signed in',
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

        $clients = Client::query()
            ->whereIn('client_id', $requests->pluck('client_id')->unique()->all())
            ->get(['client_id', 'name']);

        foreach ($clients as $client) {
            $names[(string) $client->client_id] = (string) $client->name;
        }

        return $this->page('console/approvals', 'Agent approvals', [
            'requests' => $requests->map(function (BackchannelAuthRequest $request) use ($names): array {
                $clientId = (string) $request->client_id;

                return [
                    'id' => $request->id,
                    'app' => $names[$clientId] ?? $clientId,
                    'bindingMessage' => $request->binding_message,
                    'scopes' => array_map(static fn (string $scope): array => [
                        'value' => $scope,
                        'label' => self::SCOPE_LABELS[$scope] ?? $scope,
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

    public function approve(string $request, BackchannelAuthentication $backchannel): RedirectResponse
    {
        $me = app(CurrentUser::class);

        // Bound to the acting subject rather than checked against it: a request belonging to
        // somebody else is refused by the service, which is where that rule belongs.
        $backchannel->approve($request, $me->id(), $me->organizationId());

        return back()->with('status', 'Request approved.');
    }

    public function deny(string $request, BackchannelAuthentication $backchannel): RedirectResponse
    {
        $backchannel->deny($request, app(CurrentUser::class)->id());

        return back()->with('status', 'Request denied.');
    }
}
