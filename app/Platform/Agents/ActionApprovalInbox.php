<?php

declare(strict_types=1);

namespace App\Platform\Agents;

use App\Platform\Actions\Approvals\ActionApprovalGate;
use App\Platform\Actions\Approvals\ActionApprovalRequest;
use Carbon\CarbonImmutable;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\OAuthServer\Enums\ActionApprovalStatus;
use Cbox\Id\OAuthServer\Enums\GrantPollStatus;
use Cbox\Id\OAuthServer\Models\BackchannelAuthRequest;
use Cbox\Id\OAuthServer\ValueObjects\ActionApprovalRequest as FrameworkApprovalRequest;
use Cbox\Id\Platform\PlatformRoot;

/**
 * One environment's action approvals, read for its console — the requests its agents'
 * keys raised when their approval policy held an action ({@see ActionApprovalGate}).
 *
 * TWO HALVES, IN TWO PLACES. The row naming the credential and what it asked for is the
 * app's ({@see ActionApprovalRequest}); the approval itself is the framework's CIBA request
 * in the platform ROOT, where the approving person is a subject and their phone is
 * enrolled. Approving here answers THAT request — the same one Cbox Authenticator answers —
 * bound to the same person, so the agent's poll and its repeat with `Cbox-Approval` are
 * exactly what they would have been had the phone answered.
 *
 * A pending approval is at most fifteen minutes old (the framework's ceiling on its time
 * to live), which is what keeps the waiting count on every console page one indexed read:
 * nothing older than that can still be waiting.
 */
final readonly class ActionApprovalInbox
{
    /** The framework's ceiling on an action approval's life ({@see FrameworkApprovalRequest}). */
    private const int MAX_PENDING_MINUTES = 15;

    public function __construct(
        private PlatformRoot $platformRoot,
        private BackchannelAuthentication $backchannel,
    ) {}

    /**
     * How many of this environment's approvals are waiting for `$subjectId` right now —
     * the count beside Approvals in the rail.
     */
    public function waitingFor(string $subjectId, string $environmentId): int
    {
        $ids = $this->recentIds($environmentId);

        if ($ids === []) {
            return 0;
        }

        $count = $this->platformRoot->run(fn (): int => BackchannelAuthRequest::query()
            ->whereIn('id', $ids)
            ->where('user_id', $subjectId)
            ->where('purpose', 'action')
            ->where('status', GrantPollStatus::Pending)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->count());

        return is_int($count) ? $count : 0;
    }

    /**
     * The latest of this environment's approvals, newest first, each with where it stands.
     *
     * @return list<ActionApprovalEntry>
     */
    public function latest(string $environmentId, int $limit = 60): array
    {
        $rows = array_values(ActionApprovalRequest::query()
            ->where('environment_id', $environmentId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->all());

        return $this->entries($rows);
    }

    /**
     * One of THIS environment's approvals, or null — an id raised in another environment, or
     * by a workspace key, is simply not found.
     */
    public function find(string $id, string $environmentId): ?ActionApprovalEntry
    {
        $row = ActionApprovalRequest::query()
            ->whereKey($id)
            ->where('environment_id', $environmentId)
            ->first();

        return $row === null ? null : ($this->entries([$row])[0] ?? null);
    }

    /**
     * Approve as `$subjectId`. The framework refuses unless they ARE the person the request
     * was raised for, so this cannot approve somebody else's: it answers false instead.
     */
    public function approve(ActionApprovalEntry $entry, string $subjectId): bool
    {
        return $this->platformRoot->run(fn (): bool => $this->backchannel->approve($entry->request->id, $subjectId)) === true;
    }

    /**
     * Deny, as the request's own approver. Denying withholds rather than grants, so an
     * administrator of the environment may shut down a request that looks wrong even when
     * it was raised for somebody else — the same fail-closed half the sign-in requests
     * below it on the page have always had.
     */
    public function deny(ActionApprovalEntry $entry): bool
    {
        return $this->platformRoot->run(fn (): bool => $this->backchannel->deny($entry->request->id, $entry->approverId)) === true;
    }

    /**
     * @param  list<ActionApprovalRequest>  $rows
     * @return list<ActionApprovalEntry>
     */
    private function entries(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        /** @var array<string, BackchannelAuthRequest> $ciba */
        $ciba = $this->platformRoot->run(fn (): array => BackchannelAuthRequest::query()
            ->whereIn('id', array_map(static fn (ActionApprovalRequest $row): string => $row->id, $rows))
            ->where('purpose', 'action')
            ->get()
            ->keyBy('id')
            ->all()) ?? [];

        $entries = [];

        foreach ($rows as $row) {
            $request = $ciba[$row->id] ?? null;

            // The framework's half is gone (pruned, or never written because the root
            // refused it): there is nothing to approve and nothing true to say about it.
            if ($request === null) {
                continue;
            }

            $entries[] = new ActionApprovalEntry(
                request: $row,
                approverId: $request->user_id,
                status: self::status($request),
                expiresAt: CarbonImmutable::instance($request->expires_at),
                decidedAt: $request->approved_at === null ? null : CarbonImmutable::instance($request->approved_at),
            );
        }

        return $entries;
    }

    /** The framework's own reading of a request's state (the framework's ActionApprovals::status()). */
    private static function status(BackchannelAuthRequest $request): ActionApprovalStatus
    {
        return match (true) {
            $request->consumed_at !== null => ActionApprovalStatus::Consumed,
            $request->status === GrantPollStatus::Denied => ActionApprovalStatus::Denied,
            $request->expires_at->isPast() => ActionApprovalStatus::Expired,
            $request->status === GrantPollStatus::Approved => ActionApprovalStatus::Approved,
            default => ActionApprovalStatus::Pending,
        };
    }

    /**
     * Ids of this environment's approvals young enough to still be waiting.
     *
     * @return list<string>
     */
    private function recentIds(string $environmentId): array
    {
        return array_values(ActionApprovalRequest::query()
            ->where('environment_id', $environmentId)
            ->where('created_at', '>=', now()->subMinutes(self::MAX_PENDING_MINUTES))
            ->pluck('id')
            ->filter(static fn (mixed $id): bool => is_string($id))
            ->all());
    }
}
