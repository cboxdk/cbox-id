<?php

declare(strict_types=1);

namespace App\Platform\AuditLogs;

use App\Http\Resources\Environment\AuditLogEventResource;
use App\Models\AuditLogs\AuditLogChain;
use App\Models\AuditLogs\AuditLogEvent;
use App\Models\AuditLogs\AuditLogEventTarget;
use Carbon\CarbonImmutable;
use Cbox\AuditChain\Codec\CanonicalJson;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * EVERY ORGANIZATION'S AUDIT EVENTS ARE A HASH CHAIN OF THEIR OWN.
 *
 * Each event carries a `sequence` (1, 2, 3… per organization), the `prev_hash` of the
 * event before it and its own `hash`:
 *
 *     hash = SHA-256( prev_hash ‖ canonical JSON of the event )
 *
 * where the event is {@see AuditLogEventResource::hashed()} — its id, organization,
 * sequence, action, time, actor, targets, context and metadata — encoded with keys sorted
 * at every depth, lists in order, slashes and Unicode unescaped
 * ({@see CanonicalJson}). The first event's `prev_hash` is 64 zeros. So an event changed,
 * deleted from the middle or reordered after it was written no longer verifies, and anybody
 * holding a copy of the events can check that for themselves.
 *
 * WHY NOT THE PLATFORM'S OWN CHAIN (cboxdk/laravel-audit-chain, which the platform's audit
 * trail is). It was the first choice and does not fit, for two reasons that are its design
 * rather than gaps in it: its entries are append-only with no prune — deleting any breaks
 * verification by construction — and these events have a retention an environment chooses;
 * and it would put a customer's `invoice.voided` into the same chain as our `user.created`.
 * So this is the same idea, smaller: a chain per organization in a table of its own, which
 * the prune cuts from the front and records where it cut ({@see self::cut()}).
 *
 * WHAT IT DOES NOT PROVE, said as plainly as the docs say it. It is tamper-EVIDENT, not
 * tamper-proof: whoever can rewrite the table and the chain head can recompute every hash.
 * There are no signed checkpoints and no export to storage the database's writers cannot
 * reach — a customer who needs that keeps the hashes they were given (every event is
 * returned with its hash) or streams events to their own SIEM. And it proves integrity, not
 * completeness: an event the app never sent leaves no gap.
 *
 * Appends to one organization's chain take turns: the chain's head row is locked for the
 * length of the transaction, so concurrent writers never share a sequence. Different
 * organizations never wait for each other.
 */
final readonly class AuditLogChains
{
    /** The `prev_hash` of an organization's first event. */
    public const string GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(private EnvironmentContext $environments) {}

    /**
     * Append $events to $organizationId's chain, in order. Each is an {@see EventShape}
     * normalized event. Returns the stored events.
     *
     * @param  list<array<string, mixed>>  $events
     * @return list<AuditLogEvent>
     */
    public function append(string $organizationId, array $events): array
    {
        return DB::transaction(function () use ($organizationId, $events): array {
            $environmentId = $this->environments->requireEnvironment()->environmentKey();
            $chain = $this->lockedHead($environmentId, $organizationId);
            $sequence = $chain->head_sequence;
            $previous = $chain->head_hash;
            $receivedAt = Carbon::now()->format('Y-m-d H:i:s');
            $rows = [];
            $targets = [];

            foreach ($events as $event) {
                $sequence++;
                $id = strtolower((string) Str::ulid());
                /** @var CarbonImmutable $occurredAt */
                $occurredAt = $event['occurred_at'];
                /** @var array{id: string, type: string, name: string|null, metadata: array<string, mixed>|null} $actor */
                $actor = $event['actor'];
                /** @var list<array{id: string, type: string, name: string|null, metadata: array<string, mixed>|null}> $eventTargets */
                $eventTargets = $event['targets'];
                /** @var array{location: string|null, user_agent: string|null} $context */
                $context = $event['context'];
                /** @var array<string, mixed>|null $metadata */
                $metadata = $event['metadata'];

                $hash = self::hash($previous, AuditLogEventResource::document(
                    $id,
                    $organizationId,
                    $sequence,
                    is_string($event['action']) ? $event['action'] : '',
                    $occurredAt->format('Y-m-d\TH:i:s.v\Z'),
                    $actor,
                    $eventTargets,
                    $context,
                    $metadata,
                ));

                $rows[] = [
                    'id' => $id,
                    'environment_id' => $environmentId,
                    'organization_id' => $organizationId,
                    'sequence' => $sequence,
                    'action' => $event['action'],
                    'schema_version' => $event['schema_version'],
                    'occurred_at' => AuditLogEvent::storedTime($occurredAt),
                    'actor_id' => $actor['id'],
                    'actor_type' => $actor['type'],
                    'actor_name' => $actor['name'],
                    'actor_metadata' => self::json($actor['metadata']),
                    'targets' => json_encode($eventTargets, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'location' => $context['location'],
                    'user_agent' => $context['user_agent'],
                    'metadata' => self::json($metadata),
                    'prev_hash' => $previous,
                    'hash' => $hash,
                    'created_at' => $receivedAt,
                ];

                foreach ($eventTargets as $target) {
                    $targets[] = [
                        'event_id' => $id,
                        'environment_id' => $environmentId,
                        'organization_id' => $organizationId,
                        'type' => $target['type'],
                        'target_id' => $target['id'],
                    ];
                }

                $previous = $hash;
            }

            foreach (array_chunk($rows, 50) as $chunk) {
                AuditLogEvent::query()->insert($chunk);
            }

            foreach (array_chunk($targets, 200) as $chunk) {
                AuditLogEventTarget::query()->insert($chunk);
            }

            $chain->forceFill(['head_sequence' => $sequence, 'head_hash' => $previous])->save();

            return array_values(AuditLogEvent::hydrate($rows)->all());
        });
    }

    /**
     * The chain hash of $document following $previous.
     *
     * @param  array<string, mixed>  $document
     */
    public static function hash(string $previous, array $document): string
    {
        return hash('sha256', $previous.CanonicalJson::encode($document));
    }

    /**
     * Re-hash $organizationId's chain from $fromSequence (default: the oldest event retention
     * kept), at most $limit events, and say whether it holds.
     */
    public function verify(string $organizationId, ?int $fromSequence = null, int $limit = 10_000): ChainVerification
    {
        $chain = AuditLogChain::query()->where('organization_id', $organizationId)->first();

        if ($chain === null) {
            return new ChainVerification(true, 0, null, null, 0, null, null, true);
        }

        $oldest = $chain->pruned_through_sequence + 1;
        $start = max($fromSequence ?? $oldest, $oldest);

        // What the first event checked must point back to: where the prune cut the chain, the
        // genesis, or the event before a window that starts later.
        $previous = match (true) {
            $start === $oldest && $chain->pruned_through_sequence === 0 => self::GENESIS,
            $start === $oldest => (string) $chain->pruned_through_hash,
            default => AuditLogEvent::query()
                ->where('organization_id', $organizationId)
                ->where('sequence', $start - 1)
                ->value('hash'),
        };

        $previous = is_string($previous) ? $previous : '';

        $expected = $start;
        $verified = 0;
        $break = null;

        // By sequence, a page at a time off the chain's unique index — never the whole chain
        // in memory, and never past $limit.
        do {
            $events = AuditLogEvent::query()
                ->where('organization_id', $organizationId)
                ->where('sequence', '>=', $expected)
                ->orderBy('sequence')
                ->limit(min(500, $limit - $verified))
                ->get();

            foreach ($events as $event) {
                $break = match (true) {
                    $event->sequence !== $expected => 'missing',
                    $event->prev_hash !== $previous => 'link',
                    self::hash($previous, AuditLogEventResource::hashed($event)) !== $event->hash => 'hash',
                    default => null,
                };

                if ($break !== null) {
                    break 2;
                }

                $previous = $event->hash;
                $expected++;
                $verified++;
            }
        } while ($events->count() === 500 && $verified < $limit);

        $last = $expected - 1;

        // Everything there verified, but the head says the chain went further — events were
        // removed from the end — or ends somewhere else than the last event does.
        if ($break === null && $verified < $limit && $last < $chain->head_sequence) {
            $break = 'truncated';
        } elseif ($break === null && $last >= $chain->head_sequence && $previous !== $chain->head_hash) {
            $break = 'truncated';
        }

        $complete = $break === null && $last >= $chain->head_sequence;

        return new ChainVerification(
            $break === null,
            $verified,
            $verified === 0 ? null : $start,
            $verified === 0 ? null : $last,
            $chain->head_sequence,
            $break === null ? null : $expected,
            $break,
            $complete,
        );
    }

    /**
     * Record that the prune is about to remove $organizationId's events through $sequence,
     * whose hash is $hash — BEFORE removing them, so a prune that dies half-way leaves a
     * chain that still verifies from where it says it starts.
     */
    public function cut(AuditLogChain $chain, int $sequence, string $hash): void
    {
        $chain->forceFill(['pruned_through_sequence' => $sequence, 'pruned_through_hash' => $hash])->save();
    }

    /**
     * The organization's chain head, locked for this transaction — created first if this is
     * its first event. `insertOrIgnore` rather than create-and-catch: on PostgreSQL a failed
     * insert aborts the whole transaction, and two first events arriving together race to it.
     */
    private function lockedHead(string $environmentId, string $organizationId): AuditLogChain
    {
        $head = AuditLogChain::query()->where('organization_id', $organizationId)->lockForUpdate()->first();

        if ($head !== null) {
            return $head;
        }

        $now = Carbon::now();

        AuditLogChain::query()->insertOrIgnore([
            'id' => strtolower((string) Str::ulid()),
            'environment_id' => $environmentId,
            'organization_id' => $organizationId,
            'head_sequence' => 0,
            'head_hash' => self::GENESIS,
            'pruned_through_sequence' => 0,
            'pruned_through_hash' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return AuditLogChain::query()->where('organization_id', $organizationId)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>|null  $value
     */
    private static function json(?array $value): ?string
    {
        return $value === null || $value === [] ? null : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
