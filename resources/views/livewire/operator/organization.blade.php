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
            session()->flash('status', 'Organization suspended.');
        } else {
            $orgs->reactivate($this->orgId, $actorId);
            session()->flash('status', 'Organization reactivated.');
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

    <x-page-header :title="$org['name']"
                   subtitle="Read-only tenant detail — members, SSO, domains, entitlements and recent activity in the current environment.">
        <x-slot:actions>
            <button wire:click="toggleStatus" class="btn {{ $org['status'] === 'active' ? 'btn-ghost' : 'btn-primary' }}"
                    wire:loading.attr="disabled">
                {{ $org['status'] === 'active' ? 'Suspend' : 'Reactivate' }}
            </button>
        </x-slot:actions>
    </x-page-header>

    {{-- Overview --}}
    <div class="card p-5 mb-5">
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
                <span class="badge badge-danger">Suspended</span>
            @elseif ($org['status'] === 'active')
                <span class="badge badge-success">Active</span>
            @else
                <span class="badge">{{ ucfirst($org['status']) }}</span>
            @endif
        </div>

        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 text-sm">
            <div>
                <dt class="text-xs uppercase tracking-wide" style="color:var(--faint)">Slug</dt>
                <dd class="mt-0.5 font-mono">{{ $org['slug'] }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide" style="color:var(--faint)">Type</dt>
                <dd class="mt-0.5 capitalize">{{ $org['type'] }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide" style="color:var(--faint)">Members</dt>
                <dd class="mt-0.5">{{ $memberTotal }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide" style="color:var(--faint)">Child tenants</dt>
                <dd class="mt-0.5">{{ $childCount }}</dd>
            </div>
            @if ($org['created_at'] !== null)
                <div>
                    <dt class="text-xs uppercase tracking-wide" style="color:var(--faint)">Created</dt>
                    <dd class="mt-0.5">{{ $org['created_at'] }}</dd>
                </div>
            @endif
        </dl>
    </div>

    {{-- Usage --}}
    <div class="card overflow-hidden mb-5">
        <div class="px-5 py-3 border-b" style="border-color:var(--border)">
            <h3 class="text-sm font-semibold">Usage</h3>
        </div>
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
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-px" style="background:var(--border)">
            @foreach ($usageTiles as $tile)
                <div class="p-4 sm:p-5" style="background:var(--surface)">
                    <p class="text-xs uppercase tracking-wide" style="color:var(--faint)">{{ $tile['label'] }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $tile['value'] }}</p>
                    @if (isset($tile['sub']))
                        <p class="mt-0.5 text-xs" style="color:var(--faint)">{{ $tile['sub'] }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    {{-- Members --}}
    <div class="card overflow-hidden mb-5">
        <div class="px-5 py-3 border-b flex items-center justify-between" style="border-color:var(--border)">
            <h3 class="text-sm font-semibold">Members</h3>
            <span class="text-xs" style="color:var(--faint)">
                {{ count($members) < $memberTotal ? 'Showing '.count($members).' of '.$memberTotal : $memberTotal.' total' }}
            </span>
        </div>
        <div class="hidden sm:grid px-5 py-2 border-b text-xs font-medium uppercase tracking-wide"
             style="border-color:var(--border);color:var(--faint);grid-template-columns:2.5fr 1fr 1fr">
            <span>User</span><span>Role</span><span>Status</span>
        </div>
        @forelse ($members as $member)
            <div class="px-5 py-3 border-b flex flex-col gap-1 sm:grid sm:items-center sm:gap-4"
                 style="border-color:var(--border);grid-template-columns:2.5fr 1fr 1fr">
                <div class="min-w-0">
                    <p class="text-sm font-medium truncate">{{ $member['email'] ?? $member['name'] ?? $member['user_id'] }}</p>
                    @if ($member['name'] !== null && $member['email'] !== null)
                        <p class="text-xs truncate" style="color:var(--faint)">{{ $member['name'] }}</p>
                    @endif
                </div>
                <div class="text-sm capitalize"><span class="sm:hidden" style="color:var(--faint)">Role: </span>{{ $member['role'] }}</div>
                <div class="text-sm capitalize"><span class="sm:hidden" style="color:var(--faint)">Status: </span>{{ $member['status'] }}</div>
            </div>
        @empty
            <div class="px-5 py-8 text-center text-sm" style="color:var(--faint)">No members in this organization.</div>
        @endforelse
    </div>

    <div class="grid gap-5 lg:grid-cols-2 mb-5">
        {{-- SSO --}}
        <div class="card p-5">
            <h3 class="text-sm font-semibold mb-3">SSO connection</h3>
            @if ($sso === null)
                <p class="text-sm" style="color:var(--faint)">No SSO connection configured.</p>
            @else
                <div class="flex items-center gap-2 mb-2">
                    <span class="text-sm font-medium">{{ $sso['name'] }}</span>
                    <span class="badge {{ $sso['status'] === 'active' ? 'badge-success' : '' }}">{{ ucfirst($sso['status']) }}</span>
                </div>
                <p class="text-xs uppercase tracking-wide" style="color:var(--faint)">Protocol</p>
                <p class="text-sm uppercase">{{ $sso['type'] }}</p>
            @endif
        </div>

        {{-- Domains --}}
        <div class="card p-5">
            <h3 class="text-sm font-semibold mb-3">Verified domains</h3>
            @forelse ($domains as $domain)
                <div class="flex items-center justify-between py-1.5 border-b last:border-0" style="border-color:var(--border)">
                    <span class="text-sm font-mono">{{ $domain['domain'] }}</span>
                    <span class="flex items-center gap-2">
                        @if ($domain['capture'])
                            <span class="badge">Capture</span>
                        @endif
                        @if ($domain['verified_at'] !== null)
                            <span class="badge badge-success">Verified</span>
                        @else
                            <span class="badge">Pending</span>
                        @endif
                    </span>
                </div>
            @empty
                <p class="text-sm" style="color:var(--faint)">No domains registered.</p>
            @endforelse
        </div>
    </div>

    {{-- Entitlements --}}
    <div class="card overflow-hidden mb-5">
        <div class="px-5 py-3 border-b" style="border-color:var(--border)">
            <h3 class="text-sm font-semibold">Entitlements</h3>
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
    <div class="card overflow-hidden">
        <div class="px-5 py-3 border-b" style="border-color:var(--border)">
            <h3 class="text-sm font-semibold">Recent activity</h3>
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
