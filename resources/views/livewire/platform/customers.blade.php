<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
use Carbon\CarbonInterface;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Kernel\Tenancy\Contracts\TenantContext;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * The operator's account console — every customer workspace on the install, and the
 * platform's off-switch for one.
 *
 * {@see Accounts::suspend()} has always been implemented (it also drops each owned
 * environment's resolution-cache entry, so the environments stop serving auth on the
 * NEXT request rather than at a TTL) but had no caller anywhere: operators got
 * environments, organizations, operators, security, usage and search, and no way to
 * reach an account at all. A junk or abusive signup could not be turned off.
 *
 * Accounts sit ABOVE the tenancy — like operators, they are not environment-owned — so
 * every query here is global and spans planes, exactly like the Environments screen.
 * Suspension is reversible on this same screen; nothing here deletes or purges, which
 * is a separate, later stage.
 *
 * The counts are LINKS. They used to be numbers and nothing else — this file contained
 * no `route()` call at all — so an operator learned that Acme has three projects and
 * three environments and had nowhere to click: the only way to find out which three was
 * the flat environments list and prior knowledge of Acme's plane names. Every count now
 * lands on the account's own page (`platform.customers.show`), where account → project →
 * environment is walkable.
 */
new #[Layout('components.layouts.platform', ['title' => 'Accounts', 'width' => '72rem'])] class extends Component
{
    /** Re-check operator AUTHORITY on every request, including Livewire actions. */
    public function boot(ConsoleScope $scope): void
    {
        abort_unless($scope->isPlatformOperator(), 404);
    }

    /**
     * Suspend or reactivate a customer. Idempotent on the contract's side, so the current
     * status decides the direction rather than a separate flag.
     *
     * The audit entry is written by the CONTRACT, not here. It used to be written at this
     * call site because the account writer took no actor, unlike
     * {@see Organizations::suspend()} which has always audited internally. An audit written
     * at the call site is one a second caller can silently forget, and this screen was the
     * only caller there had ever been.
     *
     * IN THE PLATFORM ROOT, both the read and the write: `organizations` is
     * environment-owned, so a suspension issued from a tenant host would find nothing to
     * suspend and report success. The account plane sat outside tenancy and never had to
     * think about it, which is exactly why it is easy to lose in the fold.
     */
    public function toggleStatus(string $id, Organizations $organizations, ConsoleScope $scope, PlatformRoot $platformRoot): void
    {
        $actorId = $scope->operator()?->id;
        if ($actorId === null) {
            abort(403);
        }

        $suspending = $platformRoot->run(function () use ($organizations, $id, $actorId): ?bool {
            $organization = $organizations->find($id);

            if ($organization === null) {
                return null;
            }

            $suspending = ! $organization->status->revokesAccess();

            $suspending
                ? $organizations->suspend($id, $actorId)
                : $organizations->reactivate($id, $actorId);

            return $suspending;
        });

        if ($suspending === null) {
            return;
        }

        $this->dispatch('toast', message: $suspending
            ? 'Customer suspended — its members can no longer sign in and its environments stop serving auth.'
            : 'Customer reactivated.');
    }

    /** @return array<string, mixed> */
    public function with(PlatformRoot $platformRoot): array
    {
        // IN THE PLATFORM ROOT: customers ARE organizations now, and organizations are
        // environment-owned. Read under whatever scope the request happened to carry, this
        // page shows a tenant's own end-user organizations — or, off a tenant host, nothing
        // at all. The account plane never had to think about it because `accounts` sat
        // outside tenancy entirely.
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $platformRoot->run(function (): array {
            $organizations = Organization::query()->orderBy('created_at')->get();

            // TENANT SCOPE SUSPENDED. A membership is tenant-owned and the tenant scope is
            // deny-by-default, so this roll-up across every customer counts ZERO from a
            // request that has no tenant in context — silently, and the page would render
            // "0 members" for organizations that have several.
            /** @var Collection<string, int> $memberCounts */
            $memberCounts = app(TenantContext::class)->withoutScope(
                static fn () => Membership::query()->selectRaw('organization_id, count(*) as c')
                    ->groupBy('organization_id')->pluck('c', 'organization_id'),
            );

            /** @var Collection<string, int> $projectCounts */
            $projectCounts = Project::query()->selectRaw('organization_id, count(*) as c')
                ->groupBy('organization_id')->pluck('c', 'organization_id');

            // Environments hang off PROJECTS, so the count runs through them rather than
            // off a denormalized owner column — `environments.account_id` was that column.
            /** @var Collection<string, int> $environmentCounts */
            $environmentCounts = Environment::query()
                ->join('projects', 'projects.id', '=', 'environments.project_id')
                ->selectRaw('projects.organization_id as organization_id, count(*) as c')
                ->groupBy('projects.organization_id')
                ->pluck('c', 'organization_id');

            return $organizations->map(function (Organization $organization) use ($memberCounts, $projectCounts, $environmentCounts): array {
                // The package's model does not declare the Eloquent timestamp columns as
                // @property, so read created_at off the attribute bag and narrow it rather
                // than trusting an undeclared property.
                $createdAt = $organization->getAttribute('created_at');

                return [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'active' => ! $organization->status->revokesAccess(),
                    'members' => (int) ($memberCounts[$organization->id] ?? 0),
                    'projects' => (int) ($projectCounts[$organization->id] ?? 0),
                    'environments' => (int) ($environmentCounts[$organization->id] ?? 0),
                    'created_at' => $createdAt instanceof CarbonInterface ? $createdAt->toDayDateTimeString() : null,
                ];
            })->all();
        }) ?? [];

        return ['rows' => $rows];
    }
}; ?>

<div>
    <x-page-header title="Accounts"
                   subtitle="Every customer workspace on this install. Open one to walk its projects and environments; suspending it signs out its members and stops every environment it owns from serving auth." />

    <p role="status" aria-live="polite" class="sr-only">{{ count($rows) }} {{ \Illuminate\Support\Str::plural('account', count($rows)) }} found.</p>

    {{-- A real table, not a div grid with matching grid-template-columns. The two rows
         resolved their `fr` tracks against different content — the header's last cell was
         an empty span, the body's a button — so by "Created" the data sat 121px left of
         its own heading. A table cannot disagree with itself, and it is what the rest of
         the console uses, so a screen reader gets the column association too. --}}
    @if ($rows === [])
        <div class="cbx-empty mt-8">
            <div class="cbx-empty-icon"><x-icon name="settings" class="w-5 h-5" /></div>
            <h3>No accounts yet</h3>
            <p>An account appears here the moment somebody signs up for a workspace. Nothing to do until then.</p>
        </div>
    @else
        <div class="cbx-panel overflow-hidden mt-8">
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">Account</th>
                            <th scope="col" class="text-right">Members</th>
                            <th scope="col" class="text-right">Projects</th>
                            <th scope="col" class="text-right">Environments</th>
                            <th scope="col">Created</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php $accountHref = route('platform.customers.show', $row['id']); @endphp
                            <tr wire:key="account-{{ $row['id'] }}">
                                <td>
                                    <p class="font-semibold">
                                        <a href="{{ $accountHref }}" wire:navigate class="hover:underline">{{ $row['name'] }}</a>
                                        @unless ($row['active'])
                                            <span class="cbx-pill cbx-pill--destructive align-middle ml-1"><span class="dot"></span>Suspended</span>
                                        @endunless
                                    </p>
                                    <p class="text-xs font-mono" style="color:var(--faint)">{{ $row['id'] }}</p>
                                </td>
                                {{-- A count you cannot click is a fact you have to go and look for
                                     somewhere else. Each of these is the same destination — the
                                     account's own page — with an accessible name that says which
                                     part of it you are asking about, because "3" repeated three
                                     times is what a screen reader would otherwise announce. --}}
                                <td class="text-right tabular-nums">
                                    <a href="{{ $accountHref }}" wire:navigate class="hover:underline"
                                       aria-label="{{ $row['members'] }} {{ \Illuminate\Support\Str::plural('member', $row['members']) }} on {{ $row['name'] }}">{{ $row['members'] }}</a>
                                </td>
                                <td class="text-right tabular-nums">
                                    <a href="{{ $accountHref }}" wire:navigate class="hover:underline"
                                       aria-label="{{ $row['projects'] }} {{ \Illuminate\Support\Str::plural('project', $row['projects']) }} on {{ $row['name'] }}">{{ $row['projects'] }}</a>
                                </td>
                                <td class="text-right tabular-nums">
                                    <a href="{{ $accountHref }}" wire:navigate class="hover:underline"
                                       aria-label="{{ $row['environments'] }} {{ \Illuminate\Support\Str::plural('environment', $row['environments']) }} on {{ $row['name'] }}">{{ $row['environments'] }}</a>
                                </td>
                                <td class="whitespace-nowrap text-xs" style="color:var(--faint)">{{ $row['created_at'] ?? '—' }}</td>

                                {{-- A reversible two-way switch, so wire:confirm rather than the
                                     type-to-confirm dialog: that component exists for actions with no way
                                     back, and it stamps the operator's pinned ENVIRONMENT into the dialog,
                                     which is meaningless for an account (accounts sit above every plane).
                                     The copy still names the account and its blast radius. --}}
                                <td class="text-right whitespace-nowrap">
                                    <a href="{{ $accountHref }}" wire:navigate class="btn btn-ghost btn-sm">Open</a>
                                    <button wire:click="toggleStatus('{{ $row['id'] }}')"
                                            class="btn {{ $row['active'] ? 'btn-ghost' : 'btn-primary' }} btn-sm"
                                            wire:loading.attr="disabled"
                                            wire:target="toggleStatus('{{ $row['id'] }}')"
                                            wire:confirm="{{ $row['active']
                                                ? 'Suspend '.$row['name'].'? Its members are signed out and all '.$row['environments'].' environment(s) it owns stop serving auth on the next request. You can reactivate it here.'
                                                : 'Reactivate '.$row['name'].'? Its members can sign in again and its environments resume serving auth.' }}">
                                        <span class="spinner" wire:loading wire:target="toggleStatus('{{ $row['id'] }}')" aria-hidden="true"></span>
                                        {{ $row['active'] ? 'Suspend' : 'Reactivate' }}
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <p class="mt-4 text-xs" style="color:var(--faint)">
            Suspension is the only lever here, and it is reversible. Nothing on this screen
            deletes or purges an account. An install also holds environments that no account
            owns — the platform root, and any unattached leftover; both are named for what
            they are on <a href="{{ route('platform.environments') }}" wire:navigate class="underline">Environments</a>.
        </p>
    @endif
</div>
