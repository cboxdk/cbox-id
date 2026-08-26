<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Shared\PaginationProps;
use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\OAuthServer\Models\BackchannelAuthRequest;
use Cbox\Id\OAuthServer\Models\Client;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * ENVIRONMENT PLANE › AGENT APPROVALS — the human-in-the-loop surface for CIBA requests,
 * where an agent asks to act on somebody's behalf.
 *
 * THERE IS DELIBERATELY NO APPROVE HERE. A CIBA approval is the USER's consent for an agent
 * to act as them, and the token that follows is minted for that user — so an operator
 * approving on their behalf would be granting consent nobody asked them for. That is the
 * same bypass the service layer refuses (approval requires the acting subject to BE the
 * request's subject), and this console used to pass the operator's own member id, which
 * could never match: the button silently did nothing.
 *
 * Denying is the safe half of the pair — it withholds access rather than granting it — so
 * an operator keeps the ability to shut a pending request down.
 *
 * Requests are environment-owned, so every query and lookup here is transparently scoped to
 * this environment: an id minted in another plane never resolves and is a 404.
 */
final readonly class AgentApprovalController extends ConsoleController
{
    /** A screenful. See the read below for why this page is bounded at all. */
    private const PER_PAGE = 25;

    /**
     * What a scope MEANS, in the words the person it concerns would use.
     *
     * An operator is being asked whether a request looks like abuse, and `offline_access`
     * does not answer that question — "stay signed in" does. The raw scope stays on screen
     * beside it, because the operator may also be the developer.
     */
    private const SCOPE_LABELS = [
        'openid' => 'Verify your identity',
        'profile' => 'Your name',
        'email' => 'Your email address',
        'offline_access' => 'Stay signed in',
    ];

    public function index(): Response
    {
        $this->assertEnvironmentAdmin();

        /*
         * A PAGE OF THEM. This is every pending request in the environment, not one
         * person's — an agent platform generates these continuously, and the unbounded read
         * that preceded it hydrated the lot into one response. Twenty-five is a screenful;
         * an operator working a backlog pages, and an environment with a runaway client no
         * longer takes the console down with it.
         */
        $page = BackchannelAuthRequest::query()
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        /** @var list<BackchannelAuthRequest> $rows */
        $rows = $page->getCollection()->all();

        // Two lookups for the page, not two per row: resolving each name inside the map was
        // a query each, so a full page cost fifty round trips to render twenty-five rows.
        /** @var array<string, string> $names */
        $names = [];

        $clients = Client::query()
            ->whereIn('client_id', array_unique(array_map(
                static fn (BackchannelAuthRequest $request): string => (string) $request->client_id,
                $rows,
            )))
            ->get(['client_id', 'name']);

        foreach ($clients as $client) {
            $names[(string) $client->client_id] = (string) $client->name;
        }

        /*
         * WHOSE request it is. The page asks an operator to recognise a request and used to
         * give them the application name alone — which is the same on every row when one
         * agent platform is behind them all. The subject is the fact that distinguishes "an
         * agent is asking to act as Dana" from "an agent is asking".
         */
        /** @var array<string, string> $subjects */
        $subjects = [];

        $users = User::query()
            ->whereIn('id', array_unique(array_map(
                static fn (BackchannelAuthRequest $request): string => (string) $request->user_id,
                $rows,
            )))
            ->get(['id', 'email']);

        foreach ($users as $user) {
            $subjects[(string) $user->id] = (string) $user->email;
        }

        return $this->page('environment/approvals', 'Agent approvals', [
            'requests' => array_map(function (BackchannelAuthRequest $request) use ($names, $subjects): array {
                $clientId = (string) $request->client_id;

                return [
                    'id' => $request->id,
                    'app' => $names[$clientId] ?? $clientId,
                    // Named rather than left blank: a request whose subject has gone is a
                    // thing an operator should be able to see and deny, not a blank row.
                    'subject' => $subjects[(string) $request->user_id] ?? 'a user who no longer exists',
                    'bindingMessage' => $request->binding_message,
                    'scopes' => array_map(static fn (string $scope): array => [
                        'value' => $scope,
                        'label' => self::SCOPE_LABELS[$scope] ?? $scope,
                    ], array_values(array_filter($request->scopes, 'is_string'))),
                    'denyHref' => route('environment.approvals.deny', $request->id),
                ];
            }, $rows),
            'pagination' => PaginationProps::from($page),
        ]);
    }

    public function deny(string $request, BackchannelAuthentication $backchannel): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $model = $this->pending($request);

        // Act as the request's own subject: denial cannot grant anything, so this is a
        // fail-closed operator action rather than consent on somebody else's behalf.
        $backchannel->deny($model->id, $model->user_id);

        /*
         * BACK TO PAGE ONE. Deny the last row on page two and the paginator still asks for
         * page two, which is now empty — and this page's empty state says "No pending
         * requests", so an operator working a backlog concludes they are done.
         */
        return to_route('environment.approvals')->with('status', 'Request denied.');
    }

    private function assertEnvironmentAdmin(): void
    {
        abort_if(app(EnvironmentAdminAuth::class)->membership() === null, 403);
    }

    /**
     * A pending, unexpired request THIS environment owns, or refuse.
     *
     * The query is environment-scoped, so an id from another plane resolves to null and is
     * a 404 — never a cross-tenant mutation.
     */
    private function pending(string $id): BackchannelAuthRequest
    {
        $request = BackchannelAuthRequest::query()
            ->whereKey($id)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->first();

        abort_if($request === null, 404);

        return $request;
    }
}
