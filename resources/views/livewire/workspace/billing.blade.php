<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use Cbox\Id\Kernel\Usage\Enums\UsageMetric;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\Projects;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Workspace › Billing — the account plane's usage rollup and per-project plans. Since
 * the plan/billing anchor lives on the PROJECT (one account can own several
 * independently-billed IdP products, the Clerk model), this page lists each project's
 * plan + environment allowance, then rolls up account-wide usage.
 *
 * Every figure is queried live from the account's own environments — nothing is
 * fabricated.
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
    public function with(AccountAuth $auth, Projects $projects): array
    {
        $account = $auth->current()?->account;

        // Per-project plan + allowance (the billing anchor is the project).
        $projectRows = $account === null ? [] : $projects->forAccount($account->id)->map(fn ($p): array => [
            'id' => $p->id,
            'name' => $p->name,
            'used' => Environment::query()->where('project_id', $p->id)->count(),
            'limit' => $p->environment_limit,
        ])->all();

        // Account-wide usage — the figures charges are based on, across every project.
        $envIds = $account === null
            ? collect()
            : Environment::query()->where('account_id', $account->id)->pluck('id');
        $monthStart = now()->startOfMonth()->format('Y-m-d');

        return [
            'account' => $account,
            'projects' => $projectRows,
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
    <x-page-header title="Billing" subtitle="Plans are per project; usage rolls up across every environment this account owns." />

    {{-- Per-project plans (the billing anchor is the project). --}}
    <div class="mt-6 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        <div class="p-4" style="border-bottom:1px solid var(--border)"><p class="text-sm font-medium">Projects</p></div>
        @forelse ($projects as $project)
            <a href="{{ route('workspace.projects.show', $project['id']) }}"
               class="flex items-center justify-between gap-4 p-4 transition-colors hover:bg-[var(--surface-2)] {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0">
                    <p class="font-medium truncate">{{ $project['name'] }}</p>
                    <p class="text-xs" style="color:var(--faint)">Early access — free</p>
                </div>
                <span class="text-sm tabular-nums shrink-0" style="color:var(--muted)">{{ $project['used'] }} of {{ $project['limit'] }} {{ \Illuminate\Support\Str::plural('environment', $project['limit']) }}</span>
            </a>
        @empty
            <div class="cbx-empty"><div class="cbx-empty-icon"><x-icon name="layers" class="w-5 h-5" /></div><h3>No projects yet</h3><p>Each project you create appears here with its plan and environment allowance.</p></div>
        @endforelse
    </div>

    {{-- Live usage — the figures enterprise billing is based on, across all projects. --}}
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
            Each project is billed on its own plan — usage-based on monthly active users and enterprise
            connections (SSO &amp; SCIM) across that project's environments. Sandbox environments carry no
            connection charge. Per-project billing arrives with general availability; every project is
            free during early access.
        </p>
    </div>
</div>
