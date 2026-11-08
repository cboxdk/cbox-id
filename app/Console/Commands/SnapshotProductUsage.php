<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Billing\UsageMeters;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Hourly usage that no domain event carries: stock (users, domains, connections…) and
 * activity that only exists as rows (tokens, one-time codes, MFA factors, passkeys).
 *
 * Each figure becomes one row in the product usage outbox with a stable id per workspace,
 * meter and hour, so a rerun, an overlap or a catch-up never counts anything twice. The
 * reporter sends them on like any other usage. Only customer projects are measured; the
 * platform root environment has no project and no customer.
 */
final class SnapshotProductUsage extends Command
{
    protected $signature = 'billing:snapshot-product-usage {--hours=3 : Closed hours of row-based activity to (re)count}';

    protected $description = 'Record hourly identity stock and row-based activity for central billing.';

    public function handle(): int
    {
        /** @var array<string, string> $workspaces environment id → owning workspace */
        $workspaces = DB::table('environments')->join('projects', 'projects.id', '=', 'environments.project_id')
            ->whereNotNull('projects.organization_id')->pluck('projects.organization_id', 'environments.id')
            ->filter(static fn (mixed $workspace): bool => is_string($workspace) && $workspace !== '')->all();
        if ($workspaces === []) {
            return self::SUCCESS;
        }

        $hour = CarbonImmutable::now()->startOfHour();
        $rows = [];
        foreach ($this->stock($hour) as $meter => $counts) {
            foreach (self::byWorkspace($counts, $workspaces) as $workspace => $value) {
                $rows[] = self::row("stock:{$meter}:{$workspace}:".$hour->format('YmdH'), $workspace, $meter, $hour, $value);
            }
        }

        $hours = max(1, min(48, (int) $this->option('hours')));
        for ($back = $hours; $back >= 1; $back--) {
            $from = $hour->subHours($back);
            foreach ($this->activity($from, $from->addHour()) as $meter => $counts) {
                foreach (self::byWorkspace($counts, $workspaces) as $workspace => $value) {
                    $rows[] = self::row("hourly:{$meter}:{$workspace}:".$from->format('YmdH'), $workspace, $meter, $from, $value);
                }
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('product_billing_usage_outbox')->insertOrIgnore($chunk);
        }
        $this->info(sprintf('Recorded %d usage figures.', count($rows)));

        return self::SUCCESS;
    }

    /** @return array<string, array<string, int>> meter → environment id → count */
    private function stock(CarbonImmutable $at): array
    {
        $count = static fn (string $table, ?callable $where = null): array => self::perEnvironment($table, $where);

        return [
            'users' => $count('users', static fn (Builder $q) => $q->where('status', 'active')),
            'organizations' => $count('organizations', static fn (Builder $q) => $q->where('status', '!=', 'deleted')),
            'memberships' => $count('memberships', static fn (Builder $q) => $q->where('status', 'active')),
            'environments' => array_fill_keys(array_filter(DB::table('environments')->whereNotNull('project_id')
                ->where('status', '!=', 'deleted')->pluck('id')->all(), 'is_string'), 1),
            'sso_connections' => $count('connections', static fn (Builder $q) => $q->where(
                static fn (Builder $q) => $q->whereNull('provider')->orWhere('provider', ''))),
            'social_connections' => $count('connections', static fn (Builder $q) => $q->whereNotNull('provider')->where('provider', '!=', '')),
            'directories' => $count('directories', static fn (Builder $q) => $q->where('status', '!=', 'deleted')),
            'verified_domains' => $count('verified_domains', static fn (Builder $q) => $q->whereNotNull('verified_at')),
            'apps' => $count('oauth_clients'),
            'api_keys' => $count('environment_api_keys', static fn (Builder $q) => $q->whereNull('revoked_at')
                ->where(static fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $at))),
        ];
    }

    /** @return array<string, array<string, int>> meter → environment id → rows created in [from, to) */
    private function activity(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $created = static fn (string $table): array => self::perEnvironment($table,
            static fn (Builder $q) => $q->where('created_at', '>=', $from)->where('created_at', '<', $to));

        return [
            'access_tokens_issued' => $created('oauth_access_tokens'),
            'refresh_tokens_issued' => $created('oauth_refresh_tokens'),
            'otp_codes_sent' => $created('otp_challenges'),
            'mfa_enrollments' => $created('mfa_factors'),
            'passkeys_registered' => $created('webauthn_credentials'),
        ];
    }

    /** @return array<string, int> */
    private static function perEnvironment(string $table, ?callable $where = null): array
    {
        $query = DB::table($table)->whereNotNull('environment_id');
        if ($where !== null) {
            $where($query);
        }

        $counts = [];
        foreach ($query->groupBy('environment_id')->selectRaw('environment_id, count(*) as aggregate')->get() as $row) {
            $environment = $row->environment_id ?? null;
            $n = $row->aggregate ?? null;
            if (is_string($environment) && (is_int($n) || (is_string($n) && ctype_digit($n)))) {
                $counts[$environment] = (int) $n;
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, string>  $workspaces
     * @return array<string, int>
     */
    private static function byWorkspace(array $counts, array $workspaces): array
    {
        $totals = [];
        foreach ($counts as $environment => $n) {
            $workspace = $workspaces[$environment] ?? null;
            if ($workspace !== null && $n > 0) {
                $totals[$workspace] = ($totals[$workspace] ?? 0) + $n;
            }
        }

        return $totals;
    }

    /** @return array<string, mixed> */
    private static function row(string $id, string $workspace, string $meter, CarbonImmutable $at, int $value): array
    {
        return [
            'event_id' => $id, 'workspace_id' => $workspace, 'meter' => UsageMeters::key($meter),
            'occurred_at' => $at, 'value' => $value, 'unique_key' => null, 'dimensions' => null,
        ];
    }
}
