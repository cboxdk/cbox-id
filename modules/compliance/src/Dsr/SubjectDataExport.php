<?php

declare(strict_types=1);

namespace Cbox\Id\Compliance\Dsr;

use Cbox\Id\AuditQuery\Contracts\AuditReader;
use Cbox\Id\AuditQuery\ValueObjects\AuditQueryFilter;
use Cbox\Id\Compliance\ValueObjects\AuditExportRecord;

/**
 * Assembles a data-subject's access-request bundle from the existing authorized
 * read seams. For v0.1 the subject is identified by their audit `actor_id`, and the
 * bundle is every audit entry that subject produced, gathered across every chain by
 * paging {@see AuditReader::query()} with the `actorId` filter.
 *
 * Honest scope of v0.1:
 *  - Access/portability (this class) is supported: the trail is read, not mutated.
 *  - Erasure is NOT offered. The trail is append-only and hash-chained, so redacting
 *    a subject's fields in place would recompute (i.e. break) the chain and defeat
 *    the tamper-evidence the audit kernel exists to provide. Compliant erasure needs
 *    a redaction-aware canonical-hash seam in laravel-id (a framework change), so it
 *    is deliberately out of scope here rather than faked. See docs / the report.
 *  - The bundle covers BOTH directions: what the subject did (`actor_id`) and what was
 *    done to them (`target_id`). The second half used to be missing, on the stated
 *    grounds that the reader exposed no target filter — it has since v0.19.0, and
 *    `AuditQueryFilter` has carried `targetType`/`targetId` all along. Article 15 is
 *    about personal data concerning the subject, and "an administrator reset your second
 *    factor" concerns them a great deal more than "you signed in".
 *  - Scoped to ONE organization. It used to sweep `AuditScopes::all()` — every chain in
 *    the environment — so an org admin running an access request received that person's
 *    records from organizations they have no relationship with.
 */
class SubjectDataExport
{
    private const PAGE_SIZE = 200;

    public function __construct(private readonly AuditReader $reader) {}

    /**
     * How large a subject's bundle would be, without building it.
     *
     * The console shows this number while somebody types a subject id into a live-updating
     * field, and it used to get it by calling {@see forSubject()} — two cursor sweeps of
     * that person's ENTIRE audit history, held in memory, to call `count()` on the result.
     * A half-typed id swept the lot.
     *
     * SLIGHTLY OVER-COUNTS, on purpose: it is the sum of the two directions, so an entry
     * where the subject is both actor and target (changing their own password) is counted
     * twice, where the bundle deduplicates by sequence. Deduplicating would mean reading
     * the sequences, which is the sweep this exists to avoid — and the honest name for
     * what the screen needs here is "roughly how much is there", answered before anyone
     * commits to producing it. The bundle itself remains exact.
     */
    public function countFor(string $subjectId, string $organizationId): int
    {
        return $this->reader->count(new AuditQueryFilter(organizationId: $organizationId, actorId: $subjectId))
            + $this->reader->count(new AuditQueryFilter(
                organizationId: $organizationId,
                targetType: 'user',
                targetId: $subjectId,
            ));
    }

    /**
     * @param  string  $organizationId  the chain to read — the caller's OWN organization
     */
    public function forSubject(string $subjectId, string $organizationId): SubjectDataBundle
    {
        // Two passes over the same chain: once for what they did, once for what was done
        // to them. Deduplicated by sequence, because an entry where the subject is both
        // actor and target (changing their own password) is one event, not two.
        $records = [];

        foreach ([['actorId' => $subjectId], ['targetType' => 'user', 'targetId' => $subjectId]] as $direction) {
            $afterSequence = null;

            while (true) {
                $page = $this->reader->query(new AuditQueryFilter(
                    organizationId: $organizationId,
                    actorId: $direction['actorId'] ?? null,
                    targetType: $direction['targetType'] ?? null,
                    targetId: $direction['targetId'] ?? null,
                    afterSequence: $afterSequence,
                    limit: self::PAGE_SIZE,
                ));

                foreach ($page->items as $entry) {
                    $records[$entry->sequence] = AuditExportRecord::fromEntry($entry);
                }

                if ($page->nextCursor === null) {
                    break;
                }

                $afterSequence = (int) $page->nextCursor;
            }
        }

        ksort($records);

        return new SubjectDataBundle($subjectId, array_values($records));
    }
}
