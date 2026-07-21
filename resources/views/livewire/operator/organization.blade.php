<?php

declare(strict_types=1);

use App\Platform\OperatorAuth;
use Cbox\Id\AuditQuery\Contracts\AuditReader;
use Cbox\Id\AuditQuery\ValueObjects\AuditQueryFilter;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Models\VerifiedDomain;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\MfaFactor;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Authorization\Contracts\EntitlementReader;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\Models\ServiceAccount;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\OrganizationHierarchy;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Membership;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * A read-only drill-down into a single tenant, WITHOUT switching the console.
 * Every read runs inside the operator's currently-targeted environment (pinned by
 * SetEnvironment from the operator's ENV_KEY), so the org-scoped contracts resolve
 * naturally to the current plane. An id that isn't in the current plane simply
 * won't be found — that is the correct deny-by-default (404), and the operator must
 * target that org's plane first. The one mutation, suspend/reactivate, routes
 * through the Organizations contract exactly like the tenant list, so it is
 * attributed to the acting operator and recorded on the tenant's audit trail.
 */
new #[Layout('components.layouts.operator', ['title' => 'Organization'])] class extends Component
{
    public string $orgId = '';

    /** Re-check operator auth on every request, including Livewire actions. */
    public function boot(OperatorAuth $auth): void
    {
        abort_unless($auth->check(), 403);
    }

    public function mount(string $organization, Organizations $orgs): void
    {
        // Scoped lookup: an org outside the current plane returns null → 404, so we
        // never render (or leak) anything from another environment.
        $org = $orgs->find($organization);
        abort_if($org === null, 404);

        $this->orgId = $org->id;
    }

    public function toggleStatus(Organizations $orgs, OperatorAuth $auth): void
    {
        $org = $orgs->find($this->orgId);
        if ($org === null) {
            return;
        }

        // Route the status change through the Organizations contract so it is
        // attributed to the acting operator and recorded on the tenant's audit
        // trail — a direct ->update() would bypass both.
        $actorId = $auth->id();
        if ($actorId === null) {
            abort(403);
        }

        if ($org->status === OrganizationStatus::Active) {
            $orgs->suspend($this->orgId, $actorId);
            $this->dispatch('toast', message: 'Organization suspended.');
        } else {
            $orgs->reactivate($this->orgId, $actorId);
            $this->dispatch('toast', message: 'Organization reactivated.');
        }
    }

    public function with(
        Organizations $orgs,
        Memberships $memberships,
        Subjects $subjects,
        OrganizationHierarchy $hierarchy,
        Connections $connections,
        DomainVerification $domains,
        EntitlementReader $entitlements,
        AuditReader $audit,
    ): array {
        $org = $orgs->find($this->orgId);
        abort_if($org === null, 404);

        // Members — resolve each subject for a human-readable email/name, capped so
        // a huge tenant can't blow up the page (the total is shown alongside).
        $allMemberships = $memberships->forOrganization($this->orgId);
        $members = $allMemberships->take(50)->map(function (Membership $m) use ($subjects): array {
            $subject = $subjects->find($m->user_id);

            return [
                'user_id' => $m->user_id,
                'email' => $subject?->email,
                'name' => $subject?->name,
                'role' => $m->role,
                'status' => $m->status->value,
            ];
        })->all();

        // Usage — a compact roll-up for this one tenant. The operator reached this
        // page in-plane (SetEnvironment pinned the org's environment), so every
        // environment-owned model below resolves to the right plane directly, no
        // scope escape needed. The org's user set comes from the members already
        // loaded above — a single whereIn, never a re-query of memberships.
        $memberUserIds = $allMemberships->pluck('user_id')->unique()->values()->all();
        $memberUserCount = count($memberUserIds);

        // Users with at least one CONFIRMED MFA factor (COUNT DISTINCT user_id).
        $mfaUsers = $memberUserCount === 0 ? 0 : MfaFactor::query()
            ->whereIn('user_id', $memberUserIds)
            ->whereNotNull('confirmed_at')
            ->distinct()
            ->count('user_id');

        // Active (non-revoked, non-expired) sessions belonging to the org's users.
        $activeSessions = $memberUserCount === 0 ? 0 : Session::query()
            ->whereIn('user_id', $memberUserIds)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->count();

        // Recent sign-ins — user.login events on the tenant's trail in the last 30
        // days. The AuditReader paginates oldest-first with a 500-row cap and no
        // time predicate, so we read the login-filtered window and count those
        // inside the 30-day boundary (a per-tenant drill-down never approaches the
        // cap; the tile is a recent-activity signal, not a billing figure).
        $signInWindowStart = now()->subDays(30);
        $signInPage = $audit->query(new AuditQueryFilter(
            organizationId: $this->orgId,
            action: 'user.login',
            limit: 500,
        ));
        $recentSignIns = 0;
        foreach ($signInPage->items as $entry) {
            if ($entry->recorded_at !== null && $entry->recorded_at->greaterThanOrEqualTo($signInWindowStart)) {
                $recentSignIns++;
            }
        }

        // SSO — the org's active connection, if any.
        $connection = $connections->forOrganization($this->orgId);
        $sso = $connection === null ? null : [
            'type' => $connection->type->value,
            'name' => $connection->name,
            'status' => $connection->status->value,
        ];

        // Verified domains.
        $domainList = array_map(fn (VerifiedDomain $d): array => [
            'domain' => $d->domain,
            'verified_at' => $d->verified_at?->toDayDateTimeString(),
            'capture' => $d->capture,
        ], $domains->forOrganization($this->orgId));

        // Entitlements — key → value + enforcement/source.
        $entitlementList = [];
        foreach ($entitlements->all($this->orgId) as $key => $value) {
            $entitlementList[] = [
                'key' => $key,
                'value' => $value->value,
                'mode' => $value->mode->value,
                'source' => $value->source->value,
            ];
        }

        // Recent audit — the AuditReader paginates oldest-first with no descending
        // primitive, so we read a capped window (200) and take its newest tail (20)
        // for display. Comfortably covers a per-tenant drill-down's recent activity.
        $page = $audit->query(new AuditQueryFilter(organizationId: $this->orgId, limit: 200));
        $recent = array_map(fn (AuditEntry $e): array => [
            'action' => $e->action,
            'actor_type' => $e->actor_type->value,
            'actor_id' => $e->actor_id,
            'recorded_at' => $e->recorded_at?->toDayDateTimeString(),
        ], array_slice(array_reverse($page->items), 0, 20));

        // Hierarchy — ancestors (breadcrumb) and a strict-descendant count.
        $ancestors = [];
        foreach ($hierarchy->ancestors($this->orgId) as $ancestorId) {
            $ancestor = $orgs->find($ancestorId);
            if ($ancestor !== null) {
                $ancestors[] = ['id' => $ancestor->id, 'name' => $ancestor->name];
            }
        }

        return [
            'org' => [
                'id' => $org->id,
                'name' => $org->name,
                'slug' => $org->slug,
                'status' => $org->status->value,
                'type' => $org->type->value,
                'created_at' => $org->created_at?->toDayDateTimeString(),
            ],
            'members' => $members,
            'memberTotal' => $allMemberships->count(),
            'usage' => [
                'members' => $memberUserCount,
                'mfaUsers' => $mfaUsers,
                'mfaAdoption' => $memberUserCount === 0 ? 0 : (int) round($mfaUsers / $memberUserCount * 100),
                'sessions' => $activeSessions,
                'connections' => $sso === null ? 0 : 1,
                'domains' => count($domainList),
                'clients' => Client::query()->where('organization_id', $this->orgId)->count(),
                'serviceAccounts' => ServiceAccount::query()->where('organization_id', $this->orgId)->count(),
                'signIns' => $recentSignIns,
            ],
            'childCount' => count($hierarchy->descendants($this->orgId)),
            'sso' => $sso,
            'domains' => $domainList,
            'entitlements' => $entitlementList,
            'recent' => $recent,
            'ancestors' => $ancestors,
        ];
    }
}; ?>

<div>
    <div class="mb-5">
        <a href="{{ route('operator.organizations') }}" wire:navigate
           class="inline-flex items-center gap-1 text-sm" style="color:var(--muted)">
            <span aria-hidden="true">&larr;</span> Back to organizations
        </a>
    </div>

    <div class="cbx-page-header">
        <div>
            <p class="cbx-page-eyebrow">Organization</p>
            <h1 class="cbx-page-title">{{ $org['name'] }}</h1>
            <p class="cbx-page-desc">Read-only tenant detail — members, SSO, domains, entitlements and recent activity in the current environment.</p>
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="toggleStatus" class="btn {{ $org['status'] === 'active' ? 'btn-ghost' : 'btn-primary' }}"
                    wire:loading.attr="disabled">
                {{ $org['status'] === 'active' ? 'Suspend' : 'Reactivate' }}
            </button>
        </div>
    </div>

    {{-- Overview --}}
    <div class="cbx-panel mb-5 mt-8">
        <div class="cbx-panel-body">
            @if (count($ancestors) > 0)
                <nav aria-label="Breadcrumb" class="mb-3 text-xs flex flex-wrap items-center gap-1" style="color:var(--faint)">
                    @foreach ($ancestors as $ancestor)
                        <a href="{{ route('operator.organization', $ancestor['id']) }}" wire:navigate class="hover:underline">{{ $ancestor['name'] }}</a>
                        <span aria-hidden="true">/</span>
                    @endforeach
                    <span style="color:var(--muted)">{{ $org['name'] }}</span>
                </nav>
            @endif

            <div class="flex flex-wrap items-center gap-2 mb-4">
                <h3 class="text-base font-semibold">{{ $org['name'] }}</h3>
                @if ($org['status'] === 'suspended')
                    <span class="cbx-pill cbx-pill--destructive"><span class="dot"></span>Suspended</span>
                @elseif ($org['status'] === 'active')
                    <span class="cbx-pill cbx-pill--success"><span class="dot"></span>Active</span>
                @else
                    <span class="cbx-pill"><span class="dot"></span>{{ ucfirst($org['status']) }}</span>
                @endif
            </div>

            <dl>
                <div class="cbx-kv"><dt>Slug</dt><dd>{{ $org['slug'] }}</dd></div>
                <div class="cbx-kv"><dt>Type</dt><dd class="prose capitalize">{{ $org['type'] }}</dd></div>
                <div class="cbx-kv"><dt>Members</dt><dd>{{ $memberTotal }}</dd></div>
                <div class="cbx-kv"><dt>Child tenants</dt><dd>{{ $childCount }}</dd></div>
                @if ($org['created_at'] !== null)
                    <div class="cbx-kv"><dt>Created</dt><dd>{{ $org['created_at'] }}</dd></div>
                @endif
            </dl>
        </div>
    </div>

    {{-- Usage --}}
    <section class="mb-5">
        <h3 class="text-sm font-semibold mb-3">Usage</h3>
        @php
            $usageTiles = [
                ['label' => 'Members', 'value' => number_format($usage['members'])],
                ['label' => 'MFA adoption', 'value' => $usage['mfaAdoption'].'%', 'sub' => $usage['mfaUsers'].' of '.$usage['members'].' with MFA'],
                ['label' => 'Active sessions', 'value' => number_format($usage['sessions'])],
                ['label' => 'Sign-ins (30d)', 'value' => number_format($usage['signIns'])],
                ['label' => 'SSO connections', 'value' => number_format($usage['connections'])],
                ['label' => 'Verified domains', 'value' => number_format($usage['domains'])],
                ['label' => 'API clients', 'value' => number_format($usage['clients'])],
                ['label' => 'Service accounts', 'value' => number_format($usage['serviceAccounts'])],
            ];
        @endphp
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            @foreach ($usageTiles as $tile)
                <div class="cbx-stat">
                    <div class="min-w-0">
                        <p class="cbx-stat-value">{{ $tile['value'] }}</p>
                        <p class="cbx-stat-label">{{ $tile['label'] }}</p>
                        @if (isset($tile['sub']))
                            <p class="cbx-stat-label mt-1">{{ $tile['sub'] }}</p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Members --}}
    <div class="cbx-panel overflow-hidden mb-5">
        <div class="cbx-panel-header">
            <h3 class="cbx-panel-title">Members</h3>
            <span class="text-xs" style="color:var(--faint)">
                {{ count($members) < $memberTotal ? 'Showing '.count($members).' of '.$memberTotal : $memberTotal.' total' }}
            </span>
        </div>
        <div class="hidden sm:grid px-5 py-2 border-b text-xs font-medium uppercase tracking-wide"
             style="border-color:var(--border);color:var(--faint);grid-template-columns:2.5fr 1fr 1fr auto">
            <span>User</span><span>Role</span><span>Status</span><span class="text-right">Support</span>
        </div>
        @forelse ($members as $member)
            <div class="px-5 py-3 border-b flex flex-col gap-1 sm:grid sm:items-center sm:gap-4"
                 style="border-color:var(--border);grid-template-columns:2.5fr 1fr 1fr auto">
                <div class="min-w-0">
                    <p class="text-sm font-medium truncate">{{ $member['email'] ?? $member['name'] ?? $member['user_id'] }}</p>
                    @if ($member['name'] !== null && $member['email'] !== null)
                        <p class="text-xs truncate" style="color:var(--faint)">{{ $member['name'] }}</p>
                    @endif
                </div>
                <div class="text-sm capitalize"><span class="sm:hidden" style="color:var(--faint)">Role: </span>{{ $member['role'] }}</div>
                <div class="text-sm"><span class="sm:hidden" style="color:var(--faint)">Status: </span><span class="cbx-pill {{ $member['status'] === 'active' ? 'cbx-pill--success' : ($member['status'] === 'suspended' ? 'cbx-pill--destructive' : 'cbx-pill--warning') }}"><span class="dot"></span><span class="capitalize">{{ $member['status'] }}</span></span></div>
                {{-- Step into this member's session for support. Heavily rail-guarded:
                     the console is read-only while impersonating, credential changes
                     are blocked, a justification is required, and the session
                     self-terminates after 30 minutes. Owners and admins are never
                     impersonable — their elevated surface is off-limits. --}}
                @if (in_array($member['role'], ['owner', 'admin'], true))
                    <span class="sm:text-right text-xs" style="color:var(--faint)">Not impersonable</span>
                @else
                    <form method="POST" action="{{ route('operator.impersonate', $member['user_id']) }}"
                          class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-end"
                          onsubmit="return confirm('Impersonate {{ $member['email'] ?? $member['user_id'] }}? Everything you do will be logged.');">
                        @csrf
                        <input type="hidden" name="organization" value="{{ $org['id'] }}">
                        <input type="text" name="reason" required maxlength="200"
                               placeholder="Reason for access"
                               class="input text-xs" style="max-width:12rem"
                               aria-label="Reason for impersonating {{ $member['email'] ?? $member['user_id'] }}">
                        <button type="submit" class="btn btn-ghost text-xs" wire:loading.attr="disabled">Impersonate</button>
                    </form>
                @endif
            </div>
        @empty
            <div class="px-5 py-8 text-center text-sm" style="color:var(--faint)">No members in this organization.</div>
        @endforelse
    </div>

    <div class="grid gap-5 lg:grid-cols-2 mb-5">
        {{-- SSO --}}
        <div class="cbx-panel">
            <div class="cbx-panel-body">
                <h3 class="text-sm font-semibold mb-3">SSO connection</h3>
                @if ($sso === null)
                    <p class="text-sm" style="color:var(--faint)">No SSO connection configured.</p>
                @else
                    <div class="flex items-center gap-2 mb-2">
                        <span class="text-sm font-medium">{{ $sso['name'] }}</span>
                        <span class="cbx-pill {{ $sso['status'] === 'active' ? 'cbx-pill--success' : '' }}"><span class="dot"></span>{{ ucfirst($sso['status']) }}</span>
                    </div>
                    <p class="text-xs uppercase tracking-wide" style="color:var(--faint)">Protocol</p>
                    <p class="text-sm uppercase">{{ $sso['type'] }}</p>
                @endif
            </div>
        </div>

        {{-- Domains --}}
        <div class="cbx-panel">
            <div class="cbx-panel-body">
                <h3 class="text-sm font-semibold mb-3">Verified domains</h3>
                @forelse ($domains as $domain)
                    <div class="flex items-center justify-between py-1.5 border-b last:border-0" style="border-color:var(--border)">
                        <span class="text-sm font-mono">{{ $domain['domain'] }}</span>
                        <span class="flex items-center gap-2">
                            @if ($domain['capture'])
                                <span class="cbx-pill">Capture</span>
                            @endif
                            @if ($domain['verified_at'] !== null)
                                <span class="cbx-pill cbx-pill--success"><span class="dot"></span>Verified</span>
                            @else
                                <span class="cbx-pill cbx-pill--warning"><span class="dot"></span>Pending</span>
                            @endif
                        </span>
                    </div>
                @empty
                    <p class="text-sm" style="color:var(--faint)">No domains registered.</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Entitlements --}}
    <div class="cbx-panel overflow-hidden mb-5">
        <div class="cbx-panel-header">
            <h3 class="cbx-panel-title">Entitlements</h3>
        </div>
        <div class="hidden sm:grid px-5 py-2 border-b text-xs font-medium uppercase tracking-wide"
             style="border-color:var(--border);color:var(--faint);grid-template-columns:2fr 2fr 1fr 1fr">
            <span>Key</span><span>Value</span><span>Enforcement</span><span>Source</span>
        </div>
        @forelse ($entitlements as $entitlement)
            <div class="px-5 py-3 border-b flex flex-col gap-1 sm:grid sm:items-center sm:gap-4"
                 style="border-color:var(--border);grid-template-columns:2fr 2fr 1fr 1fr">
                <div class="text-sm font-mono truncate">{{ $entitlement['key'] }}</div>
                <div class="text-xs font-mono truncate" style="color:var(--muted)">{{ json_encode($entitlement['value']) }}</div>
                <div class="text-sm"><span class="sm:hidden" style="color:var(--faint)">Enforcement: </span>{{ $entitlement['mode'] }}</div>
                <div class="text-sm capitalize"><span class="sm:hidden" style="color:var(--faint)">Source: </span>{{ $entitlement['source'] }}</div>
            </div>
        @empty
            <div class="px-5 py-8 text-center text-sm" style="color:var(--faint)">No entitlements set for this organization.</div>
        @endforelse
    </div>

    {{-- Recent audit --}}
    <div class="cbx-panel overflow-hidden">
        <div class="cbx-panel-header">
            <h3 class="cbx-panel-title">Recent activity</h3>
        </div>
        @forelse ($recent as $event)
            <div class="px-5 py-3 border-b flex flex-col gap-1 sm:grid sm:items-center sm:gap-4"
                 style="border-color:var(--border);grid-template-columns:2fr 2fr 1.5fr">
                <div class="text-sm font-mono truncate">{{ $event['action'] }}</div>
                <div class="text-xs truncate" style="color:var(--muted)">{{ $event['actor_type'] }}{{ $event['actor_id'] !== null ? ' · '.$event['actor_id'] : '' }}</div>
                <div class="text-xs" style="color:var(--faint)">{{ $event['recorded_at'] ?? '—' }}</div>
            </div>
        @empty
            <div class="px-5 py-8 text-center text-sm" style="color:var(--faint)">No recent activity recorded.</div>
        @endforelse
    </div>
</div>
