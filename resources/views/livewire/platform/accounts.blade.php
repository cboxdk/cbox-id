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
    <x-page-header title="Accounts"
                   subtitle="Every customer workspace on this install. Suspending one signs out its members and stops every environment it owns from serving auth." />

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
                            <tr wire:key="account-{{ $row['id'] }}">
                                <td>
                                    <p class="font-semibold">
                                        {{ $row['name'] }}
                                        @unless ($row['active'])
                                            <span class="cbx-pill cbx-pill--destructive align-middle ml-1"><span class="dot"></span>Suspended</span>
                                        @endunless
                                    </p>
                                    <p class="text-xs font-mono" style="color:var(--faint)">{{ $row['id'] }}</p>
                                </td>
                                <td class="text-right tabular-nums">{{ $row['members'] }}</td>
                                <td class="text-right tabular-nums">{{ $row['projects'] }}</td>
                                <td class="text-right tabular-nums">{{ $row['environments'] }}</td>
                                <td class="whitespace-nowrap text-xs" style="color:var(--faint)">{{ $row['created_at'] ?? '—' }}</td>

                                {{-- A reversible two-way switch, so wire:confirm rather than the
                                     type-to-confirm dialog: that component exists for actions with no way
                                     back, and it stamps the operator's pinned ENVIRONMENT into the dialog,
                                     which is meaningless for an account (accounts sit above every plane).
                                     The copy still names the account and its blast radius. --}}
                                <td class="text-right whitespace-nowrap">
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
            deletes or purges an account.
        </p>
    @endif
</div>
