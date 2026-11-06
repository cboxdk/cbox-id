<?php

declare(strict_types=1);

namespace App\SignalsData;

use App\SignalsData\Models\SourceMembership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Event-time membership facts; current rosters never authorize old tenant reads. */
final readonly class SourceRelationships
{
    public function __construct(private SourceLedger $ledger) {}

    public function membership(string $id, string $subject, string $organization, bool $added, ?CarbonImmutable $knownStart = null): void
    {
        $environment = $this->ledger->environment();
        if ($environment === null) {
            return;
        }
        $state = SourceMembership::query()->where('environment_id', $environment)->where('membership_id', $id)->lockForUpdate()->first();
        if (($added && $state !== null) || (! $added && $state?->valid_until !== null)) {
            return;
        }
        if (! $added && $state === null) {
            // A pre-capture deletion has no supported assertion to retract. This is
            // one reason coverage remains partial until the initial snapshot is qualified.
            return;
        }
        $at = CarbonImmutable::now();
        $start = $state->valid_from ?? $knownStart ?? $at;
        $assertion = 'membership:'.$id.':assert';
        $event = $added ? $assertion : 'membership:'.$id.':retract';
        $this->ledger->append(new SourceFact($event, 'relation', [
            'relation' => 'member_of',
            'from' => ['namespace' => 'cbox-id:'.$environment, 'type' => 'user', 'id' => $subject],
            'to' => ['namespace' => 'cbox-id:'.$environment, 'type' => 'team', 'id' => $organization],
            'valid_from' => $start->utc()->toISOString(), 'valid_until' => $added ? null : $at->utc()->toISOString(),
            'operation' => $added ? 'assert' : 'retract', 'supersedes_id' => $added ? null : $state->assertion_id,
        ], $at, [$subject], $organization));
        if ($added) {
            DB::table('signals_source_memberships')->insertOrIgnore([
                'environment_id' => $environment, 'membership_id' => $id, 'subject_hash' => hash('sha256', $subject),
                'assertion_id' => $assertion, 'valid_from' => $start->utc()->format('Y-m-d H:i:s.u'),
            ]);
        } else {
            DB::table('signals_source_memberships')->where('environment_id', $environment)->where('membership_id', $id)->update(['valid_until' => $at->utc()->format('Y-m-d H:i:s.u')]);
        }
    }
}
