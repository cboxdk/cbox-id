<?php

declare(strict_types=1);

namespace Cbox\Id\Compliance\Dsr;

use Cbox\Id\AuditQuery\Contracts\AuditReader;
use Cbox\Id\AuditQuery\ValueObjects\AuditQueryFilter;
use Cbox\Id\Compliance\Support\AuditScopes;
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
 *  - Lookup is by `actor_id` (actions the subject performed). Records where the
 *    subject is only the `target_id` need a target filter the reader does not yet
 *    expose — also a framework-seam follow-up.
 */
class SubjectDataExport
{
    private const PAGE_SIZE = 200;

    public function __construct(private readonly AuditReader $reader) {}

    public function forSubject(string $subjectId): SubjectDataBundle
    {
        $records = [];

        foreach (AuditScopes::all() as $organizationId) {
            $afterSequence = null;

            while (true) {
                $page = $this->reader->query(new AuditQueryFilter(
                    organizationId: $organizationId,
                    actorId: $subjectId,
                    afterSequence: $afterSequence,
                    limit: self::PAGE_SIZE,
                ));

                foreach ($page->items as $entry) {
                    $records[] = AuditExportRecord::fromEntry($entry);
                }

                if ($page->nextCursor === null) {
                    break;
                }

                $afterSequence = (int) $page->nextCursor;
            }
        }

        return new SubjectDataBundle($subjectId, $records);
    }
}
