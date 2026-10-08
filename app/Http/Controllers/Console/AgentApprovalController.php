<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\Approvals\DenyAgentRequest;
use App\Http\Props\Console\ActionApprovalRowProps;
use App\Http\Props\Shared\HelpProps;
use App\Http\Props\Shared\PaginationProps;
use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\ActionRegistry;
use App\Platform\Actions\Approvals\ActionApprovalGate;
use App\Platform\Actions\Danger;
use App\Platform\Agents\ActionApprovalEntry;
use App\Platform\Agents\ActionApprovalInbox;
use App\Platform\Console\ConsoleStepUp;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\EnvironmentSudo;
use App\Platform\Help\HelpTopic;
use App\Platform\Keys\ManagementKeys;
use App\Platform\OrganizationActivity;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\OAuthServer\Enums\ActionApprovalStatus;
use Cbox\Id\OAuthServer\Models\BackchannelAuthRequest;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Platform\Models\EnvironmentApiKey;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;
use InvalidArgumentException;

/**
 * AI AGENTS › APPROVALS — the inbox of everything software is waiting for a person to
 * allow in this environment. Two kinds, one page:
 *
 * AN AGENT'S HELD ACTION. A management key whose approval policy holds an action stops
 * and asks the person who created the key ({@see ActionApprovalGate}). That person can
 * answer on their phone, or HERE: approving answers the very same CIBA request, bound to
 * the same person, so the agent's poll and its repeat with `Cbox-Approval` are unchanged.
 * Only that person may approve — the framework refuses anybody else — and a Critical
 * action asks them to confirm their password first, because a session left open is not
 * the person. Any administrator may DENY one, which withholds rather than grants.
 *
 * A REQUEST TO ACT AS A USER (OIDC CIBA), where an app asks to act on somebody's behalf.
 * THERE IS DELIBERATELY NO APPROVE FOR THESE. A CIBA approval is the USER's consent for an agent
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

    public function index(ActionApprovalInbox $inbox, ActionRegistry $registry): Response
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

        $actions = $this->actionRows($inbox, $registry);

        return $this->page('environment/approvals', 'Approvals', [
            'help' => HelpProps::for(HelpTopic::ReviewAgentRequests),
            'waiting' => array_values(array_filter($actions, static fn (array $row): bool => $row['status'] === ActionApprovalStatus::Pending->value)),
            'decided' => array_slice(array_values(array_filter($actions, static fn (array $row): bool => $row['status'] !== ActionApprovalStatus::Pending->value)), 0, 20),
            'agentsHref' => route('environment.agents'),
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

    /**
     * Deny through {@see DenyAgentRequest} — the action an environment key runs too — which
     * finds only a request still pending in THIS environment (another plane's is a 404) and
     * denies it as the request's own subject: denial cannot grant anything, so it is a
     * fail-closed operator act rather than consent on somebody else's behalf.
     */
    public function deny(string $request): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $result = $this->act(DenyAgentRequest::class, ['request_id' => $request]);

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        /*
         * BACK TO PAGE ONE. Deny the last row on page two and the paginator still asks for
         * page two, which is now empty — and this page's empty state says "No pending
         * requests", so an operator working a backlog concludes they are done.
         */
        return to_route('environment.approvals')->with('status', 'Request denied.');
    }

    /**
     * Approve an agent's held action, as the person it was raised for.
     */
    public function approveAction(string $approval, ActionApprovalInbox $inbox, ActionRegistry $registry): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $auth = app(EnvironmentAdminAuth::class);
        $entry = $inbox->find($approval, (string) $auth->environmentId());

        abort_if($entry === null, 404);

        if (! $entry->pending()) {
            return to_route('environment.approvals')->with('error', 'That request is no longer waiting — it was answered, used or expired.');
        }

        // The framework refuses anybody else as well; asked here first so the answer is a
        // plain refusal rather than a button that silently did nothing.
        $me = $auth->subjectId();
        abort_unless($me !== null && hash_equals($entry->approverId, $me), 403);

        // A Critical action waits for a password typed in the last few minutes: approving
        // one hands an agent a credential or the way people sign in, and a console left
        // open is not the person it belongs to.
        if (self::definition($registry, $entry->request->action)?->danger === Danger::Critical) {
            $sudo = app(ConsoleStepUp::class)->challenge(
                'approvals',
                'environment.approvals',
                [],
                'Approving lets an agent run a critical action: '.$entry->request->action.'.',
            );

            if ($sudo !== null) {
                return to_route($sudo);
            }
        }

        if (! $inbox->approve($entry, $me)) {
            return to_route('environment.approvals')->with('error', 'That request could not be approved — it may have just expired.');
        }

        $this->record('organization.action_approval_approved', $entry->request->id, $entry->request->action, $me);

        return to_route('environment.approvals')->with('status', 'Approved. The agent can now repeat its request — once.');
    }

    /**
     * Deny an agent's held action. Any administrator of the environment may: it withholds.
     */
    public function denyAction(string $approval, ActionApprovalInbox $inbox): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $auth = app(EnvironmentAdminAuth::class);
        $entry = $inbox->find($approval, (string) $auth->environmentId());

        abort_if($entry === null, 404);

        if (! $entry->pending()) {
            return to_route('environment.approvals')->with('error', 'That request is no longer waiting — it was answered, used or expired.');
        }

        $inbox->deny($entry);
        $this->record('organization.action_approval_denied', $entry->request->id, $entry->request->action, (string) $auth->subjectId());

        return to_route('environment.approvals')->with('status', 'Denied. The agent is told so when it asks again.');
    }

    /**
     * The action approvals of this environment, newest first, as the inbox draws them.
     *
     * Four reads for the lot — the requests, their framework halves, the keys that raised
     * them and the people they wait for — never one per row.
     *
     * @return list<array<string, mixed>>
     */
    private function actionRows(ActionApprovalInbox $inbox, ActionRegistry $registry): array
    {
        $auth = app(EnvironmentAdminAuth::class);
        $entries = $inbox->latest((string) $auth->environmentId());

        if ($entries === []) {
            return [];
        }

        $me = $auth->subjectId();
        $sudoOpen = app(EnvironmentSudo::class)->confirmed();

        $keyIds = array_values(array_filter(array_map(
            static fn (ActionApprovalEntry $entry): ?string => $entry->request->environmentKeyId(),
            $entries,
        )));

        /** @var array<string, string> $keys */
        $keys = EnvironmentApiKey::query()->whereIn('id', $keyIds)->pluck('name', 'id')->all();

        $approverIds = array_values(array_unique(array_map(static fn (ActionApprovalEntry $entry): string => $entry->approverId, $entries)));

        /** @var array<string, string> $approvers */
        $approvers = app(PlatformRoot::class)->run(fn (): array => User::query()
            ->whereIn('id', $approverIds)
            ->get(['id', 'name', 'email'])
            ->mapWithKeys(static fn (User $user): array => [$user->id => (string) ($user->name ?? $user->email)])
            ->all()) ?? [];

        return array_map(function (ActionApprovalEntry $entry) use ($registry, $keys, $approvers, $me, $sudoOpen): array {
            $definition = self::definition($registry, $entry->request->action);
            $keyId = $entry->request->environmentKeyId();
            $input = $entry->request->input ?? [];
            $pathFields = $definition?->input()->pathFields() ?? [];
            $mine = $me !== null && hash_equals($entry->approverId, $me);
            $pending = $entry->pending();

            return (new ActionApprovalRowProps(
                entry: $entry,
                agent: $keyId === null ? 'A workspace key' : ($keys[$keyId] ?? 'A revoked key'),
                agentId: $keyId,
                action: $definition,
                target: self::target($entry->request->action, $input, $pathFields, $keys),
                arguments: ActionApprovalRowProps::arguments($input, $pathFields),
                approver: $mine ? 'You' : ($approvers[$entry->approverId] ?? 'A former member'),
                mine: $mine,
                needsSudo: $pending && $mine && $definition?->danger === Danger::Critical && ! $sudoOpen,
                approveHref: $pending && $mine ? route('environment.approvals.actions.approve', $entry->request->id) : null,
                denyHref: $pending ? route('environment.approvals.actions.deny', $entry->request->id) : null,
            ))->toArray();
        }, $entries);
    }

    /**
     * What the action is aimed at, from the fields its URL names — a key by its name when
     * it is one of this environment's, otherwise the id as given.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $pathFields
     * @param  array<string, string>  $keys
     */
    private static function target(string $action, array $input, array $pathFields, array $keys): ?string
    {
        $parts = [];

        foreach ($pathFields as $field) {
            $value = $input[$field] ?? null;

            if (! is_scalar($value)) {
                continue;
            }

            $value = (string) $value;
            $parts[] = str_starts_with($action, 'keys.') && $field === 'id' && isset($keys[$value])
                ? 'Key "'.$keys[$value].'"'
                : $field.' '.$value;
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private static function definition(ActionRegistry $registry, string $name): ?ActionDefinition
    {
        try {
            return $registry->named($name);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * On the workspace's own trail, where the console records every act on this
     * environment's keys ({@see ManagementKeys}).
     */
    private function record(string $action, string $approvalId, string $actionName, string $actorId): void
    {
        $auth = app(EnvironmentAdminAuth::class);
        $workspaceId = $auth->membership()?->organization_id;

        if (! is_string($workspaceId) || $workspaceId === '') {
            return;
        }

        app(OrganizationActivity::class)->record(
            $workspaceId,
            $action,
            $actorId,
            targetType: 'environment',
            targetId: $auth->environmentId(),
            context: ['approval_id' => $approvalId, 'action' => $actionName],
        );
    }

    private function assertEnvironmentAdmin(): void
    {
        abort_if(app(EnvironmentAdminAuth::class)->membership() === null, 403);
    }
}
