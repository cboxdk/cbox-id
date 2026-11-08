<?php

declare(strict_types=1);

namespace App\SignalsData;

use App\SignalsData\Models\SourceErasurePosition;
use App\SignalsData\Models\SourceHead;
use App\SignalsData\Models\SourcePending;
use App\SignalsData\Models\SourceRecord;
use Carbon\CarbonImmutable;
use Cbox\Id\Kernel\Tenancy\Concerns\ResolvesEnvironment;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Durable owner outbox; only committed facts are assigned a public position. */
final readonly class SourceLedger
{
    use ResolvesEnvironment;

    public const array FAMILIES = ['identity.authentication', 'identity.session', 'identity.token', 'identity.account', 'identity.activity', 'relation'];

    public function __construct(private SourceCursor $cursors) {}

    public function environment(): ?string
    {
        $environment = $this->environments()->current()?->environmentKey();

        return $environment !== null && SourceEnvironments::includes($environment) ? $environment : null;
    }

    public function append(SourceFact $fact): void
    {
        $environment = $this->environment();
        if ($environment === null) {
            return;
        }
        $this->stage($environment, $fact);
    }

    private function stage(string $environment, SourceFact $fact): void
    {
        if (DB::transactionLevel() === 0) {
            DB::transaction(fn () => $this->stage($environment, $fact));

            return;
        }
        $encoded = json_encode($fact->data, JSON_THROW_ON_ERROR);
        if (! in_array($fact->name, self::FAMILIES, true) || strlen($encoded) > 60 * 1024) {
            throw new RuntimeException('Invalid source fact.');
        }
        // The owner transaction writes the durable fact, without acquiring the
        // global publication lock between business/audit locks. Publication happens
        // later and scans unassigned committed facts, never a timestamp/ULID cursor.
        $hashes = array_map(self::subjectHash(...), array_unique($fact->subjects));
        SourcePrivacyLock::acquire($environment, array_values($hashes), false);
        if (DB::table('signals_source_erasures')->where('environment_id', $environment)->whereIn('subject_hash', $hashes)->exists()) {
            return;
        }
        DB::table('signals_source_pending')->insertOrIgnore([
            'environment_id' => $environment, 'id' => $fact->id, 'name' => $fact->name,
            'organization_id' => $fact->organizationId, 'occurred_at' => $fact->occurredAt->utc()->format('Y-m-d H:i:s.u'),
            'recorded_at' => CarbonImmutable::now()->utc()->format('Y-m-d H:i:s.u'), 'data' => $encoded,
            'subject_hashes' => json_encode(array_values($hashes), JSON_THROW_ON_ERROR),
        ]);
    }

    /** Assign positions after commit, in bounded publication transactions. */
    private function publish(string $environment, bool $erasuresOnly = false): SourceHead
    {
        return DB::transaction(function () use ($environment, $erasuresOnly): SourceHead {
            $head = $this->head($environment, true);
            $pending = SourcePending::query()->where('environment_id', $environment)->when($erasuresOnly, fn ($query) => $query->where('name', 'identity.account')->where('data->action', 'erased'))->orderBy('id')->limit(500)->get();
            foreach ($pending as $record) {
                $erased = DB::table('signals_source_erasures')->where('environment_id', $environment)->whereIn('subject_hash', $record->subject_hashes)->exists();
                $erasure = $record->name === 'identity.account' && ($record->data['action'] ?? null) === 'erased';
                $exists = DB::table('signals_source_records')->where('environment_id', $environment)->where('id', $record->id)->exists();
                if ($erasure) {
                    $exists = $exists || DB::table('signals_source_erasure_positions')->where('environment_id', $environment)->where('id', $record->id)->exists();
                }
                if (! $erased && ! $exists && ($erasure || $record->recorded_at->gte(CarbonImmutable::now()->subDays($this->retentionDays())))) {
                    abort_if(! $erasure && $head->record_count >= config()->integer('signals-data.max_records'), 503, 'Source publication is backpressured.');
                    $sequence = ++$head->sequence;
                    $head->record_count++;
                    DB::table('signals_source_records')->insert([
                        'environment_id' => $environment, 'sequence' => $sequence, 'id' => $record->id,
                        'name' => $record->name, 'organization_id' => $record->organization_id,
                        'occurred_at' => $record->occurred_at->utc()->format('Y-m-d H:i:s.u'), 'recorded_at' => $record->recorded_at->utc()->format('Y-m-d H:i:s.u'),
                        'data' => json_encode($record->data, JSON_THROW_ON_ERROR),
                    ]);
                    if ($erasure) {
                        DB::table('signals_source_erasure_positions')->insert([
                            'environment_id' => $environment, 'sequence' => $sequence, 'id' => $record->id,
                            'record' => json_encode([
                                'contract_version' => '2.0.0', 'id' => $record->id, 'producer_environment' => $environment, 'organization_id' => null,
                                'position' => ['source_id' => 'cbox-id:'.$environment, 'partition' => '0', 'generation' => $head->generation, 'sequence' => (string) $sequence],
                                'occurred_at' => $record->occurred_at->utc()->toISOString(), 'recorded_at' => $record->recorded_at->utc()->toISOString(),
                                'kind' => 'event', 'name' => $record->name, 'data' => $record->data,
                            ], JSON_THROW_ON_ERROR),
                        ]);
                    }
                    foreach ($record->subject_hashes as $hash) {
                        DB::table('signals_source_subjects')->insert(['environment_id' => $environment, 'sequence' => $sequence, 'subject_hash' => $hash]);
                    }
                }
                DB::table('signals_source_pending')->where('environment_id', $environment)->where('id', $record->id)->delete();
            }
            $head->save();

            return $head;
        });
    }

    /** @return array<string, mixed> */
    public function page(?string $after, int $limit): array
    {
        $environment = $this->environment();
        abort_if($environment === null, 503, 'Source capture is not enabled for this environment.');
        $head = $this->publish($environment);
        $generation = $head->generation;
        $sequence = $this->cursors->decode($after, $environment, $generation);
        $high = $head->sequence;
        if ($sequence > $high || ($after !== null && $sequence < $head->retained_after)) {
            throw ValidationException::withMessages(['after' => 'Source history is no longer available. Restart the import explicitly.']);
        }
        $records = [];
        $bytes = 0;
        $next = max($sequence, $head->retained_after);
        $eligible = SourceRecord::query()->where('environment_id', $environment)
            ->where('sequence', '>', $next)->where('sequence', '<=', $high)
            ->where('recorded_at', '>=', CarbonImmutable::now()->subDays($this->retentionDays()))
            ->whereNotIn('sequence', DB::table('signals_source_subjects as s')->select('s.sequence')->join('signals_source_erasures as e', function (JoinClause $join): void {
                $join->on('s.environment_id', '=', 'e.environment_id')->on('s.subject_hash', '=', 'e.subject_hash');
            })->where('s.environment_id', $environment));
        $rows = (clone $eligible)->orderBy('sequence')->limit($limit + 1)->get();
        foreach ($rows as $row) {
            $record = [
                'contract_version' => '2.0.0', 'id' => $row->id,
                'producer_environment' => $environment, 'organization_id' => $row->organization_id,
                'position' => ['source_id' => 'cbox-id:'.$environment, 'partition' => '0', 'generation' => $generation, 'sequence' => (string) $row->sequence],
                'kind' => 'event', 'name' => $row->name,
                'occurred_at' => $row->occurred_at->utc()->toISOString(),
                'recorded_at' => $row->recorded_at->utc()->toISOString(),
                'data' => $row->data,
            ];
            if ($row->name === 'relation') {
                unset($record['name'], $record['data']);
                $record = [...$record, ...$row->data, 'kind' => 'relation'];
            }
            $size = strlen(json_encode($record, JSON_THROW_ON_ERROR));
            if (count($records) >= $limit || $bytes + $size > 960 * 1024) {
                break;
            }
            $records[] = $record;
            $bytes += $size;
            $next = $row->sequence;
        }
        // Deleted subjects leave intentional holes in the commit sequence.
        $hasMore = (clone $eligible)->where('sequence', '>', $next)->exists();
        if (! $hasMore) {
            $next = $high;
        }

        $hasMore = $hasMore || SourcePending::query()->where('environment_id', $environment)->exists();

        return ['data' => $records, 'meta' => [
            'next_cursor' => $this->cursors->encode($environment, $generation, $next), 'has_more' => $hasMore,
            'source_id' => 'cbox-id:'.$environment, 'generation' => $generation,
            'commit_through' => (string) $high, 'accepted_through' => (string) $next,
            'capture_started_at' => $head->started_at->utc()->toISOString(),
            'heartbeat_at' => $head->heartbeat_at?->utc()->toISOString(),
            'observed_at' => CarbonImmutable::now()->utc()->toISOString(),
            'families' => self::FAMILIES, 'retention_days' => $this->retentionDays(),
            // The capture manifest will qualify each public door; never infer complete
            // history merely because this page reached the current head.
            'coverage' => 'partial',
            'capture' => [
                'version' => 'source-capture/1', 'strategy' => 'owner_outbox',
                'event_time_watermark' => null, 'lateness_bound_seconds' => null,
                'max_accepted_event_age_seconds' => null, 'clock_skew_bound_seconds' => null,
                'reasons' => ['capture_doors_unqualified', 'owner_transaction_lateness_unbounded'],
            ],
            'history' => ['retained_after' => (string) $head->retained_after, 'retention_days' => $this->retentionDays()],
        ]];
    }

    /** @return array<string, mixed> */
    public function erasuresPage(?string $after, int $limit): array
    {
        $environment = $this->environments()->current()?->environmentKey();
        abort_if($environment === null || ($this->environment() === null && ! SourceHead::query()->where('environment_id', $environment)->exists()), 503, 'Source history is unavailable.');
        $head = $this->publish($environment, erasuresOnly: true);
        $position = $this->cursors->decode($after, $environment, $head->generation);
        if ($position > $head->sequence) {
            throw ValidationException::withMessages(['after' => 'Source history has reset. Restart the import explicitly.']);
        }
        $rows = SourceErasurePosition::query()->where('environment_id', $environment)
            ->where('sequence', '>', $position)->where('sequence', '<=', $head->sequence)->orderBy('sequence')->limit($limit + 1)->get();
        $records = [];
        $bytes = 0;
        $next = $position;
        foreach ($rows as $row) {
            $size = strlen(json_encode($row->record, JSON_THROW_ON_ERROR));
            if (count($records) >= $limit || $bytes + $size > 960 * 1024) {
                break;
            }
            $records[] = $row->record;
            $next = $row->sequence;
            $bytes += $size;
        }
        $more = $rows->count() > count($records);
        if (! $more) {
            $next = $head->sequence;
        }
        $more = $more || SourcePending::query()->where('environment_id', $environment)->where('name', 'identity.account')->where('data->action', 'erased')->exists();

        return ['data' => $records,
            'meta' => ['next_cursor' => $this->cursors->encode($environment, $head->generation, $next), 'has_more' => $more,
                'commit_through' => (string) $head->sequence, 'generation' => $head->generation]];
    }

    /** @param list<string> $subjects */
    public function erase(array $subjects, ?string $subjectReference = null): int
    {
        $environment = $this->environments()->current()?->environmentKey();
        if ($environment === null) {
            throw new RuntimeException('Erasure requires an environment.');
        }

        return DB::transaction(function () use ($environment, $subjects, $subjectReference): int {
            $hashes = array_map(self::subjectHash(...), $subjects);
            SourcePrivacyLock::acquire($environment, $hashes, true);
            $head = $this->head($environment, true);
            foreach ($hashes as $hash) {
                DB::table('signals_source_erasures')->insertOrIgnore(['environment_id' => $environment, 'subject_hash' => $hash, 'erased_at' => CarbonImmutable::now()]);
            }
            DB::table('signals_source_memberships')->where('environment_id', $environment)->whereIn('subject_hash', $hashes)->delete();
            $pending = DB::table('signals_source_pending')->where('environment_id', $environment);
            $pending->where(function (Builder $query) use ($hashes): void {
                foreach ($hashes as $hash) {
                    $query->orWhereJsonContains('subject_hashes', $hash);
                }
            });
            $pendingDeleted = $pending->delete();
            $deleted = DB::table('signals_source_records')->where('environment_id', $environment)
                ->whereIn('sequence', DB::table('signals_source_subjects')->select('sequence')->where('environment_id', $environment)->whereIn('subject_hash', $hashes))->delete();
            DB::table('signals_source_heads')->where('environment_id', $environment)->update(['record_count' => max(0, $head->record_count - $deleted)]);

            if ($subjectReference !== null) {
                $this->stage($environment, new SourceFact('erasure:'.hash('sha256', $subjectReference), 'identity.account', [
                    'subject' => ['namespace' => 'cbox-id:'.$environment, 'type' => 'user', 'id' => $subjectReference],
                    'action' => 'erased', 'attempt_id' => null, 'context' => IdentityContext::unknown(), 'actor' => null,
                ], CarbonImmutable::now()));
            }

            return $deleted + $pendingDeleted;
        });
    }

    /** One bounded retention pass; a failed pass never publishes a healthy heartbeat. */
    public function maintain(): void
    {
        $environment = $this->environments()->current()?->environmentKey();
        if ($environment === null) {
            return;
        }
        DB::transaction(function () use ($environment): void {
            $head = $this->head($environment, true);
            $cutoff = CarbonImmutable::now()->subDays($this->retentionDays());
            // A delayed transaction can publish an older fact after newer rows.
            $expired = SourceRecord::query()->where('environment_id', $environment)
                ->where('recorded_at', '<', $cutoff)->orderBy('sequence')->limit(1000)->get();
            $ids = $expired->pluck('sequence')->all();
            if ($ids !== []) {
                DB::table('signals_source_records')->where('environment_id', $environment)->whereIn('sequence', $ids)->delete();
                DB::table('signals_source_subjects')->where('environment_id', $environment)->whereIn('sequence', $ids)->delete();
                $first = SourceRecord::query()->where('environment_id', $environment)->orderBy('sequence')->first();
                DB::table('signals_source_heads')->where('environment_id', $environment)->update([
                    // Removing a later position cannot hide an earlier retained row.
                    'retained_after' => max($head->retained_after, $first === null ? $head->sequence : $first->sequence - 1),
                    'record_count' => max(0, $head->record_count - count($ids)),
                ]);
            }
        });
        $enabled = $this->environment() !== null;
        $this->publish($environment, erasuresOnly: ! $enabled);
        if ($enabled) {
            DB::table('signals_source_heads')->where('environment_id', $environment)->update(['heartbeat_at' => CarbonImmutable::now()->utc()->format('Y-m-d H:i:s.u')]);
        }
        // Pending raw facts also expire when capture is paused.
        $expired = DB::table('signals_source_pending')->where('environment_id', $environment)
            ->where('recorded_at', '<', CarbonImmutable::now()->subDays($this->retentionDays()))
            ->whereNot(fn (Builder $query) => $query->where('name', 'identity.account')->where('data->action', 'erased'))
            ->orderBy('id')->limit(1000)->pluck('id');
        DB::table('signals_source_pending')->where('environment_id', $environment)->whereIn('id', $expired)->delete();
    }

    private function retentionDays(): int
    {
        // An immutable producer record is never silently changed under its retry ID.
        // The producer replay window ends with raw-IP retention; consumers may keep
        // the accepted non-IP fields for their independently authorized retention.
        return max(1, min(7, config()->integer('signals-data.raw_ip_days'), config()->integer('signals-data.retention_days')));
    }

    private function head(string $environment, bool $lock): SourceHead
    {
        DB::table('signals_source_heads')->insertOrIgnore([
            'environment_id' => $environment, 'generation' => (string) Str::uuid(), 'sequence' => 0,
            'retained_after' => 0, 'record_count' => 0, 'started_at' => CarbonImmutable::now()->utc()->format('Y-m-d H:i:s.u'),
        ]);
        $query = SourceHead::query()->where('environment_id', $environment);
        $head = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($head === null) {
            throw new RuntimeException('Source head unavailable.');
        }

        return $head;
    }

    private static function subjectHash(string $id): string
    {
        return hash('sha256', $id);
    }
}
