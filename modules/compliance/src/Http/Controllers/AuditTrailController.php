<?php

declare(strict_types=1);

namespace Cbox\Id\Compliance\Http\Controllers;

use App\Http\Controllers\Console\ConsoleController;
use App\Http\Props\Shared\HelpProps;
use App\Platform\Help\HelpTopic;
use Cbox\Id\AuditQuery\Contracts\AuditReader;
use Cbox\Id\AuditQuery\ValueObjects\AuditQueryFilter;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Inertia\Response;

/**
 * CONSOLE › AUDIT TRAIL — one page, both planes. The same append-only, hash-chained record
 * the activity log shows, with the chain verified.
 *
 * THERE IS NO ORGANIZATION FILTER, and its absence is the point. It used to be a text input
 * bound to a public property and passed straight to the reader — and the only guard on this
 * page is "is an admin" OF THEIR OWN organization, so any org admin could type a peer's id
 * and read that tenant's entire trail: sign-ins, actor ids, IPs, member changes, role
 * grants, SSO configuration. The scope answers instead, and no request can influence it.
 */
final readonly class AuditTrailController extends ConsoleController
{
    /** A screenful of the trail. */
    private const LIMIT = 50;

    /** How many entries back from the head the badge verifies by default. */
    private const WINDOW = 500;

    public function index(): Response
    {
        $this->scope->assertMayAdminister();

        $organizationId = $this->chain();
        $action = trim(request()->string('action')->toString());
        $actor = trim(request()->string('actor')->toString());

        $entries = app(AuditReader::class)->query(new AuditQueryFilter(
            organizationId: $organizationId,
            action: $action === '' ? null : $action,
            actorId: $actor === '' ? null : $actor,
            limit: self::LIMIT,
        ))->items;

        $log = app(AuditLog::class);

        /*
         * VERIFIES THE TAIL, NOT ALL OF HISTORY. `verifyChain()` reads every row in its range
         * and re-hashes each one in PHP, and this runs on every render — including every
         * debounced keystroke in the two filter boxes. On a tenant with a few hundred
         * thousand entries, which is months of ordinary sign-ins, the page took tens of
         * seconds and then died on the memory limit, and typing made it worse.
         *
         * The window is ANCHORED: a range starting past the beginning picks its previous hash
         * up from the entry before it, so linkage is checked rather than assumed. And what a
         * short window cannot see — entries deleted off the tail, or a wiped scope — is
         * exactly what `verifyChain()` cross-checks against the last signed checkpoint on
         * every call regardless of range. The cheap answer is honest about a real property;
         * it is simply a narrower one, and the page says which.
         */
        $verifyAll = request()->boolean('verifyAll');

        $verification = $verifyAll
            ? $log->verifyChain($organizationId)
            : $log->verifyChain($organizationId, fromSequence: max(1, $log->headSequence($organizationId) - self::WINDOW + 1));

        return $this->page('compliance::audit', 'Audit trail', [
            'entries' => array_map(static fn (AuditEntry $entry): array => [
                'id' => $entry->id,
                'sequence' => $entry->sequence,
                'when' => $entry->recorded_at?->diffForHumans(),
                'action' => $entry->action,
                'actor' => $entry->actor_type->value.($entry->actor_id === null ? '' : ' · '.$entry->actor_id),
                'target' => $entry->target_type === null ? null : $entry->target_type.' · '.$entry->target_id,
            ], $entries),
            'filters' => ['action' => $action, 'actor' => $actor],
            'verification' => [
                'valid' => $verification->valid,
                'count' => $verification->verifiedCount,
                'brokenAt' => $verification->brokenAtSequence,
                'reason' => $verification->reason,
                // Which entries were checked, so "chain verified" over a window is a badge
                // that names its window rather than one that overstates.
                'whole' => $verifyAll,
            ],
            'help' => HelpProps::for(HelpTopic::ActivityLog),
        ]);
    }

    /**
     * The chain this page reads: the acting organization's, and nothing else.
     *
     * Null means the SYSTEM trail here, not "every organization": the reader answers a null
     * organization with `whereNull('organization_id')`, and the audit log keeps one chain per
     * scope. So an environment administrator who has chosen no organization sees the
     * environment's own entries — the actions with no tenant — rather than a merged view of
     * every tenant's chain, which is not a chain and could not be verified as one. Narrowing
     * to a tenant is the organization picker in the console header.
     */
    private function chain(): ?string
    {
        return $this->scope->organizationId();
    }
}
