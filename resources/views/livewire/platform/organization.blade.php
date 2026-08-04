<?php

declare(strict_types=1);

use Carbon\CarbonInterface;
use App\Platform\Console\ConsoleScope;
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
new #[Layout('components.layouts.workspace', ['title' => 'Organization', 'width' => '72rem'])] class extends Component
{
    public string $orgId = '';

    /** Re-check operator AUTHORITY on every request, including Livewire actions. */
    public function boot(ConsoleScope $scope): void
    {
        abort_unless($scope->isPlatformOperator(), 404);
    }

    public function mount(string $organization, Organizations $orgs): void
    {
        // Scoped lookup: an org outside the current plane returns null → 404, so we
        // never render (or leak) anything from another environment.
        $org = $orgs->find($organization);
        abort_if($org === null, 404);

        $this->orgId = $org->id;
    }

    public function toggleStatus(Organizations $orgs, ConsoleScope $scope): void
    {
        $org = $orgs->find($this->orgId);
        if ($org === null) {
            return;
        }

        // Route the status change through the Organizations contract so it is
        // attributed to the acting operator and recorded on the tenant's audit
        // trail — a direct ->update() would bypass both.
        $actorId = $scope->operator()?->id;
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

    /** @return array<string, mixed> */
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

        // The package's Organization model does not declare the Eloquent timestamp
        // columns as @property, so read created_at off the attribute bag and narrow it
        // here rather than trusting an undeclared property.
        $createdAt = $org->getAttribute('created_at');

        return [
            'org' => [
                'id' => $org->id,
                'name' => $org->name,
                'slug' => $org->slug,
                'status' => $org->status->value,
                'type' => $org->type->value,
                'created_at' => $createdAt instanceof CarbonInterface ? $createdAt->toDayDateTimeString() : null,
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
        <a href="{{ route('platform.organizations') }}" wire:navigate
           class="inline-flex items-center gap-1 text-sm" style="color:var(--muted)">
            <span aria-hidden="true">&larr;</span> Back to organizations
        </a>
    </div>

    {{-- An explicit eyebrow, the one case the component documents: this is a resource
         detail page, so it has no nav entry of its own to be derived from. It says
         "Platform" because that is the rail area highlighted behind it — it used to say
         "Organization", which names the resource type rather than where you are standing,
         and so disagreed with the sidebar on the one label whose only job is orientation. --}}
    <x-page-header eyebrow="Platform" :title="$org['name']"
                   subtitle="Tenant detail in the target environment — members, SSO, domains, entitlements and recent activity. The only thing changed from this page is the tenant's status.">
        <x-slot:actions>
            {{-- Same confirmation as the Organizations list, and for the same reason: a
                 bare click here signs out every member of a live tenant. --}}
            <button wire:click="toggleStatus" class="btn {{ $org['status'] === 'active' ? 'btn-ghost' : 'btn-primary' }}"
                    wire:loading.attr="disabled" wire:target="toggleStatus"
                    wire:confirm="{{ $org['status'] === 'active'
                        ? 'Suspend '.$org['name'].'? Its '.$memberTotal.' member(s) can no longer sign in to this tenant, and any app relying on it stops authenticating them. Sub-organizations are not suspended with it. You can reactivate it here.'
                        : 'Reactivate '.$org['name'].'? Its members can sign in again immediately.' }}">
                <span class="spinner" wire:loading wire:target="toggleStatus" aria-hidden="true"></span>
                {{ $org['status'] === 'active' ? 'Suspend' : 'Reactivate' }}
            </button>
        </x-slot:actions>
    </x-page-header>

    {{-- Overview --}}
    <div class="cbx-panel mb-5 mt-8">
        <div class="cbx-panel-body">
            @if (count($ancestors) > 0)
                <nav aria-label="Breadcrumb" class="mb-3 text-xs flex flex-wrap items-center gap-1" style="color:var(--faint)">
                    @foreach ($ancestors as $ancestor)
                        <a href="{{ route('platform.organization', $ancestor['id']) }}" wire:navigate class="hover:underline">{{ $ancestor['name'] }}</a>
                        <span aria-hidden="true">/</span>
                    @endforeach
                    <span style="color:var(--muted)">{{ $org['name'] }}</span>
                </nav>
            @endif

            <div class="flex flex-wrap items-center gap-2 mb-4">
                {{-- h2 throughout this page's sections: the h1 is the tenant name above,
                     and jumping straight to h3 leaves a screen-reader user with no level
                     to navigate by (WCAG 1.3.1). --}}
                <h2 class="text-base font-semibold">{{ $org['name'] }}</h2>
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
        <h2 class="text-sm font-semibold mb-3">Usage</h2>
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
            <h2 class="cbx-panel-title">Members</h2>
            <span class="text-xs" style="color:var(--faint)">
                {{ count($members) < $memberTotal ? 'Showing '.count($members).' of '.$memberTotal : $memberTotal.' total' }}
            </span>
        </div>
        @if (count($members) === 0)
            <div class="px-5 py-8 text-center text-sm" style="color:var(--faint)">
                No members in this organization yet — nobody can sign in to it until somebody is invited or provisioned in.
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">User</th>
                            <th scope="col">Role</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-right">Support</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($members as $member)
                            <tr wire:key="member-{{ $member['user_id'] }}">
                                <td>
                                    <p class="font-medium">{{ $member['email'] ?? $member['name'] ?? $member['user_id'] }}</p>
                                    @if ($member['name'] !== null && $member['email'] !== null)
                                        <p class="text-xs" style="color:var(--faint)">{{ $member['name'] }}</p>
                                    @endif
                                </td>
                                <td class="capitalize whitespace-nowrap">{{ $member['role'] }}</td>
                                <td class="whitespace-nowrap"><span class="cbx-pill {{ $member['status'] === 'active' ? 'cbx-pill--success' : ($member['status'] === 'suspended' ? 'cbx-pill--destructive' : 'cbx-pill--warning') }}"><span class="dot"></span><span class="capitalize">{{ $member['status'] }}</span></span></td>
                                {{-- Step into this member's session for support. Heavily rail-guarded:
                                     the console is read-only while impersonating, credential changes
                                     are blocked, a justification is required, and the session
                                     self-terminates after 30 minutes. Owners and admins are never
                                     impersonable — their elevated surface is off-limits. --}}
                                <td class="text-right">
                                    @if (in_array($member['role'], ['owner', 'admin'], true))
                                        <span class="text-xs" style="color:var(--faint)">Not impersonable</span>
                                    @else
                                        <form method="POST" action="{{ route('platform.impersonate', $member['user_id']) }}"
                                              class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-end"
                                              x-on:submit="if (! window.confirm(@js('Impersonate '.($member['email'] ?? $member['user_id']).'? Everything you do will be logged.'))) $event.preventDefault()">
                                            @csrf
                                            <input type="hidden" name="organization" value="{{ $org['id'] }}">
                                            <input type="text" name="reason" required maxlength="200"
                                                   placeholder="Reason for access"
                                                   class="input text-xs" style="max-width:12rem"
                                                   aria-label="Reason for impersonating {{ $member['email'] ?? $member['user_id'] }}">
                                            <button type="submit" class="btn btn-ghost text-xs" wire:loading.attr="disabled">Impersonate</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="grid gap-5 lg:grid-cols-2 mb-5">
        {{-- SSO --}}
        <div class="cbx-panel">
            <div class="cbx-panel-body">
                <h2 class="text-sm font-semibold mb-3">SSO connection</h2>
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
                <h2 class="text-sm font-semibold mb-3">Verified domains</h2>
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
            <h2 class="cbx-panel-title">Entitlements</h2>
        </div>
        @if ($entitlements === [])
            <div class="px-5 py-8 text-center text-sm" style="color:var(--faint)">
                No entitlements set for this organization — it runs on the deployment's defaults.
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">Key</th>
                            <th scope="col">Value</th>
                            <th scope="col">Enforcement</th>
                            <th scope="col">Source</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entitlements as $entitlement)
                            <tr>
                                <td class="font-mono">{{ $entitlement['key'] }}</td>
                                <td class="font-mono text-xs" style="color:var(--muted)">{{ json_encode($entitlement['value']) }}</td>
                                <td class="whitespace-nowrap">{{ $entitlement['mode'] }}</td>
                                <td class="capitalize whitespace-nowrap">{{ $entitlement['source'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Recent audit --}}
    <div class="cbx-panel overflow-hidden">
        <div class="cbx-panel-header">
            <h2 class="cbx-panel-title">Recent activity</h2>
        </div>
        @if ($recent === [])
            <div class="px-5 py-8 text-center text-sm" style="color:var(--faint)">
                No recent activity recorded for this tenant. Sign-ins, role changes and connection edits appear here as they happen.
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">Action</th>
                            <th scope="col">Actor</th>
                            <th scope="col">Recorded</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recent as $event)
                            <tr>
                                <td class="font-mono">{{ $event['action'] }}</td>
                                <td class="text-xs" style="color:var(--muted)">{{ $event['actor_type'] }}{{ $event['actor_id'] !== null ? ' · '.$event['actor_id'] : '' }}</td>
                                <td class="whitespace-nowrap text-xs" style="color:var(--faint)">{{ $event['recorded_at'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
