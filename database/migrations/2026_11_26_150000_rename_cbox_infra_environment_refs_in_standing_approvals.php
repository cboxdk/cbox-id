<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cbox Infra's environment ids are `ifenv_…`, no longer `env_…` (2026-11-26: a contested id
 * prefix goes to the app that owns the domain, and Cbox ID owns `env`).
 *
 * `standing_approvals.environment_ref` is the APP's environment id, never one of ours, and
 * every row belongs to Cbox Infra: only a client registered for its `cbox_infra_change`
 * authorization details can hold a grant. So every `env_…` ref is Infra's old id, and is
 * rewritten here, active and revoked rows alike. Grants still match `env_…` on the way in until
 * 2027-01-15 (StandingApprovals), so a Dash that still sends the old form keeps deploying.
 *
 * Self-contained: it must mean the same after that tolerance is gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rename('env_', 'ifenv_');
    }

    public function down(): void
    {
        $this->rename('ifenv_', 'env_');
    }

    private function rename(string $from, string $to): void
    {
        DB::table('standing_approvals')
            // `!` escapes the `_`: SQLite's LIKE has no escape character unless one is named.
            ->whereRaw("environment_ref LIKE ? ESCAPE '!'", [str_replace('_', '!_', $from).'%'])
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($from, $to): void {
                foreach ($rows as $row) {
                    if (! is_string($row->environment_ref)) {
                        continue;
                    }

                    DB::table('standing_approvals')->where('id', $row->id)
                        ->update(['environment_ref' => $to.substr($row->environment_ref, strlen($from))]);
                }
            });
    }
};
