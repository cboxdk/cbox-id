<?php

declare(strict_types=1);

namespace App\Platform\Radar;

use App\Models\Radar\RadarRule;
use App\Models\RiskDecision;
use App\Platform\Radar\Enums\RadarBuiltin;
use App\Platform\Radar\Enums\RadarFlow;
use App\Platform\RiskTrail;
use Cbox\Id\Kernel\Tenancy\Concerns\ResolvesEnvironment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * THE DECISIONS EXPLORER — one environment's Radar decisions, newest first, filtered, and
 * explained.
 *
 * `risk_decisions` is deliberately NOT environment-owned (deployment-wide tuning queries are
 * run from a console with no environment in context — see {@see RiskDecision}), so this is
 * where the environment is applied, explicitly, on every query: an environment with none in
 * context sees nothing, never everything.
 *
 * Filtering by IP or address works the way the trail allows: the value is turned into the
 * same keyed pseudonym the row holds and matched on that. Neither is ever shown back.
 */
final readonly class RadarDecisions
{
    use ResolvesEnvironment;

    public function __construct(private RiskTrail $trail) {}

    /**
     * @param  array{verdict?: string|null, flow?: string|null, rule?: string|null, country?: string|null, email?: string|null, ip?: string|null, device?: string|null, enforced?: bool|null, from?: Carbon|null, to?: Carbon|null}  $filters
     * @return array{rows: list<RiskDecision>, has_more: bool, next_cursor: string|null}
     */
    public function page(array $filters, int $limit, ?string $after = null): array
    {
        $query = $this->query();

        if (($filters['verdict'] ?? null) !== null) {
            $query->where('verdict', $filters['verdict']);
        }

        if (($filters['flow'] ?? null) !== null) {
            $flow = RadarFlow::tryFrom((string) $filters['flow']);
            $query->where('action', $flow?->riskAction() ?? '');
        }

        if (($filters['rule'] ?? null) !== null) {
            $rule = (string) $filters['rule'];
            // The deciding rule, or any rule that fired: "every attempt credential stuffing
            // caught, whatever decided it in the end".
            $query->where(static fn (Builder $q) => $q->where('rule', $rule)->orWhereJsonContains('triggered', $rule));
        }

        if (($filters['country'] ?? null) !== null) {
            $query->where('country', strtoupper((string) $filters['country']));
        }

        if (($filters['email'] ?? null) !== null) {
            $query->where('email_hash', $this->trail->emailPseudonym((string) $filters['email']));
        }

        if (($filters['ip'] ?? null) !== null) {
            $query->where('ip_hash', $this->trail->ipPseudonym(RadarAddresses::canonicalIp((string) $filters['ip']) ?? (string) $filters['ip']));
        }

        if (($filters['device'] ?? null) !== null) {
            $query->where('device_hash', strtolower((string) $filters['device']));
        }

        if (($filters['enforced'] ?? null) !== null) {
            $query->where('enforced', (bool) $filters['enforced']);
        }

        if (($filters['from'] ?? null) instanceof Carbon) {
            $query->where('assessed_at', '>=', $filters['from']);
        }

        if (($filters['to'] ?? null) instanceof Carbon) {
            $query->where('assessed_at', '<=', $filters['to']);
        }

        // Newest first, so the page AFTER a cursor holds the decisions older than it.
        if ($after !== null) {
            $query->where('id', '<', $after);
        }

        $rows = $query->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $visible = array_values($rows->take($limit)->all());
        $last = $visible === [] ? null : $visible[count($visible) - 1];

        return ['rows' => $visible, 'has_more' => $hasMore, 'next_cursor' => $hasMore && $last !== null ? $last->id : null];
    }

    public function find(string $id): ?RiskDecision
    {
        return $this->query()->whereKey($id)->first();
    }

    /**
     * The names behind the `rule` keys on these rows — a rule's own name, a built-in's label,
     * a list's — so a decision reads "Block — Office VPN only", not `rule:01J…`.
     *
     * @param  list<RiskDecision>  $rows
     * @return array<string, string>
     */
    public function ruleNames(array $rows): array
    {
        $names = [];
        $ruleIds = [];

        foreach ($rows as $row) {
            foreach ([$row->rule, ...($row->triggered ?? [])] as $key) {
                if (is_string($key) && str_starts_with($key, 'rule:')) {
                    $ruleIds[] = substr($key, 5);
                }
            }
        }

        foreach (RadarRule::query()->whereIn('id', array_values(array_unique($ruleIds)))->get(['id', 'name']) as $rule) {
            $names['rule:'.$rule->id] = $rule->name;
        }

        foreach (RadarBuiltin::cases() as $builtin) {
            $names['builtin:'.$builtin->value] = $builtin->label();
        }

        foreach (['deny', 'allow'] as $list) {
            foreach (['ip' => 'IP', 'email' => 'address', 'email_domain' => 'mail domain', 'device' => 'device'] as $kind => $label) {
                $names[$list.'_list:'.$kind] = ucfirst($list).' list ('.$label.')';
            }
        }

        return $names;
    }

    /**
     * @return Builder<RiskDecision>
     */
    private function query(): Builder
    {
        $environment = $this->environments()->current()?->environmentKey();
        $query = RiskDecision::query();

        return $environment === null ? $query->whereRaw('1 = 0') : $query->where('environment_id', $environment);
    }
}
