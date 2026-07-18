<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use Cbox\Id\Kernel\Usage\Enums\UsageMetric;
use Cbox\Id\Organization\Models\Environment;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Workspace › Billing — the account plane's plan & real usage. Billing lives at the
 * ACCOUNT (not per environment or per organization), aggregated across every
 * environment the account owns — the WorkOS/Clerk/Frontegg model.
 *
 * Every figure here is queried live from the account's own environments — the
 * environment allowance, the tenants and SSO connections that drive enterprise
 * charges, and this month's sign-ins from the usage meter. Nothing is fabricated.
 */
new #[Layout('components.layouts.workspace', ['title' => 'Billing'])] class extends Component
{
    public function mount(AccountAuth $auth)
    {
        // Billing is visible to roles that can read it (owner/admin/billing + the
        // read-only viewer) — not a technical Developer role.
        if (! ($auth->current()?->role->canReadBilling() ?? false)) {
            return redirect()->route('workspace.home');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function with(AccountAuth $auth): array
    {
        $account = $auth->current()?->account;
        $limit = $account?->environment_limit ?? 0;

        $envIds = $account === null
            ? collect()
            : Environment::query()->where('account_id', $account->id)->pluck('id');

        $used = $envIds->count();
        $monthStart = now()->startOfMonth()->format('Y-m-d');

        return [
            'account' => $account,
            'limit' => $limit,
            'used' => $used,
            'pct' => $limit > 0 ? min(100, (int) round($used / max(1, $limit) * 100)) : 0,
            // Real usage across the account's environments — the figures billing is based on.
            'organizations' => $envIds->isEmpty() ? 0 : DB::table('organizations')->whereIn('environment_id', $envIds)->count(),
            'connections' => $envIds->isEmpty() ? 0 : DB::table('connections')->whereIn('environment_id', $envIds)->count(),
            'signins' => $envIds->isEmpty() ? 0 : (int) DB::table('usage_counters')
                ->whereIn('environment_id', $envIds)
                ->where('metric', UsageMetric::Login->value)
                ->where('period', '>=', $monthStart)
                ->sum('count'),
        ];
    }
}; ?>

<div>
    <div>
        <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Billing</h1>
        <p class="mt-1 text-sm" style="color:var(--muted)">Your plan and live usage across every environment this account owns.</p>
    </div>

    {{-- Plan & environment allowance. --}}
    <div class="mt-6 rounded-xl border p-5" style="border-color:var(--border)">
        <div class="flex items-center justify-between gap-4">
            <div>
                <p class="text-sm" style="color:var(--muted)">Plan</p>
                <p class="mt-0.5 font-semibold" style="font-size:1.1rem">{{ ucfirst($account?->status ?? 'active') }} account</p>
            </div>
        </div>

        <div class="mt-5">
            <div class="flex items-center justify-between text-sm">
                <span class="font-medium">Environments</span>
                <span style="color:var(--muted)">{{ $used }} of {{ $limit }}</span>
            </div>
            <div class="mt-2 h-2 rounded-full overflow-hidden" style="background:var(--surface-2)">
                <div class="h-full rounded-full" style="width:{{ $pct }}%;background:var(--accent)"></div>
            </div>
            <p class="mt-2 text-xs" style="color:var(--faint)">Your plan includes {{ $limit }} isolated {{ \Illuminate\Support\Str::plural('environment', $limit) }} (e.g. production and staging).</p>
        </div>
    </div>

    {{-- Live usage — the figures enterprise billing is based on. --}}
    <div class="mt-4 grid grid-cols-3 gap-3">
        @php
            $stats = [
                ['label' => 'Organizations', 'value' => $organizations, 'hint' => 'tenants'],
                ['label' => 'SSO connections', 'value' => $connections, 'hint' => 'billed'],
                ['label' => 'Sign-ins', 'value' => $signins, 'hint' => 'this month'],
            ];
        @endphp
        @foreach ($stats as $stat)
            <div class="rounded-xl border p-4" style="border-color:var(--border)">
                <p class="text-2xl font-semibold tabular-nums">{{ number_format($stat['value']) }}</p>
                <p class="mt-1 text-sm font-medium">{{ $stat['label'] }}</p>
                <p class="text-xs" style="color:var(--faint)">{{ $stat['hint'] }}</p>
            </div>
        @endforeach
    </div>

    <div class="mt-4 rounded-xl border p-5" style="border-color:var(--border)">
        <p class="font-medium">How pricing works</p>
        <p class="mt-1 text-sm" style="color:var(--muted)">
            Cbox ID is usage-based: you're billed on monthly active users and enterprise connections
            (SSO &amp; SCIM), totalled across all your environments. Development and staging carry no
            connection charge.
        </p>
    </div>
</div>
