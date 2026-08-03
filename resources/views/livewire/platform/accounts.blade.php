<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
use Carbon\CarbonInterface;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\Accounts;
use Cbox\Id\Platform\Enums\AccountStatus;
use Cbox\Id\Platform\Models\Account;
use Cbox\Id\Platform\Models\AccountMember;
use Cbox\Id\Platform\Models\Project;
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
 */
new #[Layout('components.layouts.workspace', ['title' => 'Accounts', 'width' => '72rem'])] class extends Component
{
    /** Re-check operator AUTHORITY on every request, including Livewire actions. */
    public function boot(ConsoleScope $scope): void
    {
        abort_unless($scope->isPlatformOperator(), 404);
    }

    /**
     * Suspend or reactivate an account. Idempotent on the contract's side, so the
     * current status decides the direction rather than a separate flag.
     *
     * The audit entry is written by the CONTRACT, not here. It used to be written at
     * this call site because {@see Accounts} took no actor — unlike
     * {@see \Cbox\Id\Organization\Contracts\Organizations::suspend()}, which has always
     * audited internally. laravel-id v0.64.0 closed that asymmetry: both verbs now take
     * an `$actorId` and record on the account's own chain themselves. That matters
     * beyond tidiness — an audit written at the call site is one a second caller can
     * silently forget, and this screen was the only caller there had ever been.
     */
    public function toggleStatus(string $id, Accounts $accounts, ConsoleScope $scope): void
    {
        $actorId = $scope->operator()?->id;
        if ($actorId === null) {
            abort(403);
        }

        $account = $accounts->find($id);
        if ($account === null) {
            return;
        }

        $suspending = $account->isActive();

        if ($suspending) {
            $accounts->suspend($id, $actorId);
        } else {
            $accounts->reactivate($id, $actorId);
        }

        $this->dispatch('toast', message: $suspending
            ? 'Account suspended — its members can no longer sign in and its environments stop serving auth.'
            : 'Account reactivated.');
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        $accounts = Account::query()->orderBy('created_at')->get();

        /** @var Collection<string, int> $memberCounts */
        $memberCounts = AccountMember::query()->selectRaw('account_id, count(*) as c')
            ->groupBy('account_id')->pluck('c', 'account_id');

        /** @var Collection<string, int> $projectCounts */
        $projectCounts = Project::query()->selectRaw('account_id, count(*) as c')
            ->groupBy('account_id')->pluck('c', 'account_id');

        /** @var Collection<string, int> $environmentCounts */
        $environmentCounts = Environment::query()->selectRaw('account_id, count(*) as c')
            ->whereNotNull('account_id')
            ->groupBy('account_id')->pluck('c', 'account_id');

        return [
            'rows' => $accounts->map(function (Account $account) use ($memberCounts, $projectCounts, $environmentCounts): array {
                // The package's Account model does not declare the Eloquent timestamp
                // columns as @property, so read created_at off the attribute bag and
                // narrow it rather than trusting an undeclared property.
                $createdAt = $account->getAttribute('created_at');

                return [
                    'id' => $account->id,
                    'name' => $account->name,
                    'active' => $account->isActive(),
                    'members' => (int) ($memberCounts[$account->id] ?? 0),
                    'projects' => (int) ($projectCounts[$account->id] ?? 0),
                    'environments' => (int) ($environmentCounts[$account->id] ?? 0),
                    'created_at' => $createdAt instanceof CarbonInterface ? $createdAt->toDayDateTimeString() : null,
                ];
            })->all(),
        ];
    }
}; ?>

<div>
    <div class="cbx-page-header">
        <div>
            <p class="cbx-page-eyebrow">Platform</p>
            <h1 class="cbx-page-title">Accounts</h1>
            <p class="cbx-page-desc">Every customer workspace on this install. Suspending one signs out its members and stops every environment it owns from serving auth.</p>
        </div>
    </div>

    <div class="cbx-panel overflow-hidden mt-8">
        <div class="hidden sm:grid px-5 py-3 border-b text-xs font-medium uppercase tracking-wide"
             style="border-color:var(--border);color:var(--faint);grid-template-columns:2.5fr 1fr 1fr 1fr 1.4fr auto">
            <span>Account</span><span>Members</span><span>Projects</span><span>Environments</span><span>Created</span><span></span>
        </div>

        @forelse ($rows as $row)
            <div wire:key="account-{{ $row['id'] }}" class="px-5 py-3 border-b flex flex-col gap-2 sm:grid sm:items-center sm:gap-4"
                 style="border-color:var(--border);grid-template-columns:2.5fr 1fr 1fr 1fr 1.4fr auto">
                <div class="min-w-0">
                    <p class="text-sm font-semibold truncate">
                        {{ $row['name'] }}
                        @unless ($row['active'])
                            <span class="cbx-pill cbx-pill--destructive align-middle ml-1"><span class="dot"></span>Suspended</span>
                        @endunless
                    </p>
                    <p class="text-xs font-mono truncate" style="color:var(--faint)">{{ $row['id'] }}</p>
                </div>

                <div class="text-sm"><span class="sm:hidden" style="color:var(--faint)">Members: </span>{{ $row['members'] }}</div>
                <div class="text-sm"><span class="sm:hidden" style="color:var(--faint)">Projects: </span>{{ $row['projects'] }}</div>
                <div class="text-sm"><span class="sm:hidden" style="color:var(--faint)">Environments: </span>{{ $row['environments'] }}</div>
                <div class="text-xs" style="color:var(--faint)">{{ $row['created_at'] ?? '—' }}</div>

                {{-- A reversible two-way switch, so wire:confirm rather than the
                     type-to-confirm dialog: that component exists for actions with no way
                     back, and it stamps the operator's pinned ENVIRONMENT into the dialog,
                     which is meaningless for an account (accounts sit above every plane).
                     The copy still names the account and its blast radius. --}}
                <div class="flex items-center gap-1 sm:justify-self-end">
                    <button wire:click="toggleStatus('{{ $row['id'] }}')"
                            class="btn {{ $row['active'] ? 'btn-ghost' : 'btn-primary' }} btn-sm"
                            wire:loading.attr="disabled"
                            wire:confirm="{{ $row['active']
                                ? 'Suspend '.$row['name'].'? Its members are signed out and all '.$row['environments'].' environment(s) it owns stop serving auth on the next request. You can reactivate it here.'
                                : 'Reactivate '.$row['name'].'? Its members can sign in again and its environments resume serving auth.' }}">
                        {{ $row['active'] ? 'Suspend' : 'Reactivate' }}
                    </button>
                </div>
            </div>
        @empty
            <div class="px-5 py-10 text-center text-sm" style="color:var(--faint)">
                No accounts on this install yet. An account is created when someone signs up for a workspace.
            </div>
        @endforelse
    </div>

    <p class="mt-4 text-xs" style="color:var(--faint)">
        Suspension is the only lever here, and it is reversible. Nothing on this screen
        deletes or purges an account.
    </p>
</div>
