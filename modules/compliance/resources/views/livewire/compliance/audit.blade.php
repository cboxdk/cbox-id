<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
use Cbox\Id\AuditQuery\Contracts\AuditReader;
use Cbox\Id\AuditQuery\ValueObjects\AuditQueryFilter;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Audit\ValueObjects\ChainVerification;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Console › Audit trail — one component, both planes. The same append-only, hash-chained
 * record the activity log shows, with the chain verified end to end.
 */
new #[Layout('components.layouts.console', ['title' => 'Audit trail'])] class extends Component
{
    /**
     * Route middleware does not gate this page by ROLE: the routes carry a session gate
     * (`platform.auth` on one plane, `env.admin` on the other) and `console.feature`, and
     * neither is a role check. The nav hides the area from a plain member, which is
     * styling, not authorization — the URL is typeable. boot() rather than mount(), so it
     * re-runs on every Livewire message and not just the first render.
     */
    public function boot(): void
    {
        app(ConsoleScope::class)->assertMayAdminister();
    }

    public string $action = '';

    public string $actorId = '';

    /**
     * The chain this page reads: the acting organization's, and nothing else.
     *
     * This used to be a text input bound to a public property and passed straight to the
     * reader. The only guard on the page is "is an admin" — of THEIR org — so any org
     * admin could type a peer organization's id and read that tenant's entire trail:
     * sign-ins, actor ids, IPs, member changes, role grants, SSO configuration. It now
     * comes from the scope, which no request can influence on the organization plane.
     *
     * Null means the SYSTEM trail here, not "every organization": the reader answers a
     * null organization with `whereNull('organization_id')`, and the audit log keeps one
     * chain per scope. So an environment administrator who has chosen no organization
     * sees the environment's own entries — the actions with no tenant — rather than a
     * merged view of every tenant's chain, which is not a chain and could not be
     * verified as one. Narrowing to a tenant is the organization picker.
     */
    private function scope(): ?string
    {
        return app(ConsoleScope::class)->organizationId();
    }

    /** @return list<AuditEntry> */
    private function entries(): array
    {
        return app(AuditReader::class)->query(new AuditQueryFilter(
            organizationId: $this->scope(),
            action: trim($this->action) === '' ? null : trim($this->action),
            actorId: trim($this->actorId) === '' ? null : trim($this->actorId),
            limit: 50,
        ))->items;
    }

    /** The chain-verification status for the scope currently being viewed. */
    private function verification(): ChainVerification
    {
        return app(AuditLog::class)->verifyChain($this->scope());
    }

    /** @return array{entries: list<AuditEntry>, verification: ChainVerification} */
    public function with(): array
    {
        return [
            'entries' => $this->entries(),
            'verification' => $this->verification(),
        ];
    }
}; ?>

<div class="space-y-6">
    {{-- The subtitle no longer promises searching "across organizations": it never did
         that safely, and the field that appeared to offer it was the leak. --}}
    <x-page-header title="Audit trail" :help="\App\Platform\Help\HelpTopic::ActivityLog"
                   subtitle="The same append-only, hash-chained trail as the activity log, with the chain verified end to end." />

    <div class="flex items-center gap-3 rounded-xl border p-4 text-sm"
        style="{{ $verification->valid ? 'border-color:color-mix(in oklch, var(--success) 20%, transparent);background:var(--success-soft);color:var(--success)' : 'border-color:color-mix(in oklch, var(--destructive) 20%, transparent);background:var(--destructive-soft);color:var(--destructive)' }}">
        <span class="font-medium">
            {{ $verification->valid ? 'Chain verified' : 'Chain broken' }}
        </span>
        <span class="text-xs opacity-80">
            @if ($verification->valid)
                {{ number_format($verification->verifiedCount) }} entrie(s) checked; hashes and linkage intact.
            @else
                Broke at sequence {{ $verification->brokenAtSequence }} — {{ $verification->reason }}.
            @endif
        </span>
    </div>

    {{-- No organization filter: this page shows YOUR organization's trail and no other.
         It used to accept one, which made the id in a text box the only thing standing
         between an org admin and every other tenant's records. --}}
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <label class="block">
            <span class="label">Action</span>
            <input type="text" wire:model.live.debounce.400ms="action" placeholder="e.g. auth.login"
                class="input mt-1 w-full" />
        </label>
        <label class="block">
            <span class="label">Actor</span>
            <input type="text" wire:model.live.debounce.400ms="actorId" placeholder="actor id"
                class="input mt-1 w-full" />
        </label>
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th class="text-right" style="width:1%">Seq</th>
                        <th scope="col">When</th>
                        <th scope="col">Action</th>
                        <th scope="col">Actor</th>
                        <th scope="col">Target</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($entries as $entry)
                        <tr>
                            <td class="text-right mono text-xs" style="color:var(--faint)">{{ $entry->sequence }}</td>
                            <td class="whitespace-nowrap mono text-xs" style="color:var(--muted)">{{ $entry->recorded_at?->diffForHumans() }}</td>
                            <td class="font-medium whitespace-nowrap" style="color:var(--foreground)">{{ $entry->action }}</td>
                            <td style="color:var(--muted)">{{ $entry->actor_type->value }}{{ $entry->actor_id ? ' · '.$entry->actor_id : '' }}</td>
                            <td style="color:var(--muted)">{{ $entry->target_type ? $entry->target_type.' · '.$entry->target_id : '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <div class="cbx-empty">
                                    <h3>No audit entries match</h3>
                                    <p>Adjust the filters above, or clear them to see the full trail.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
