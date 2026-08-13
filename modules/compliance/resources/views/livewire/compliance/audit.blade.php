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

    /**
     * How many entries back from the head the badge verifies. See {@see verification()}.
     */
    private const WINDOW = 500;

    /**
     * The result of a full end-to-end verification, once somebody has asked for one.
     *
     * A RESULT, not a mode. It was a boolean flag that `verification()` read on every
     * render — so one click made every later keystroke in the filter boxes re-run the
     * unbounded verification, permanently restoring the exact failure this window exists
     * to prevent. Holding the answer instead means the expensive pass runs once, when
     * asked, and the page keeps showing what it found.
     *
     * Not `public`, so it is not settable from the wire: `$verifiedEverything` was, which
     * made "a deliberate ask" something any client could assert for itself.
     *
     * @var array{valid: bool, count: int, brokenAt: int|null, reason: string|null}|null
     */
    private ?array $fullVerification = null;

    /**
     * Verify the whole chain, end to end, however long it is.
     *
     * A button rather than the default, because this is the expensive operation and it is
     * the one an auditor asks for deliberately — not something a page should do to itself
     * while somebody types in a filter box.
     */
    public function verifyEverything(): void
    {
        $result = app(AuditLog::class)->verifyChain($this->scope());

        $this->fullVerification = [
            'valid' => $result->valid,
            'count' => $result->verifiedCount,
            'brokenAt' => $result->brokenAtSequence,
            'reason' => $result->reason,
        ];
    }

    /**
     * The chain-verification status shown at the top of the page.
     *
     * VERIFIES THE TAIL, NOT ALL OF HISTORY. `verifyChain()` reads every row in its range
     * and re-hashes each one in PHP, and this runs inside `with()` — which Livewire calls
     * again on every action and on every debounced keystroke in the two filter inputs
     * below. On a tenant with a few hundred thousand entries, which is months of ordinary
     * sign-ins, the page took tens of seconds and then died on the memory limit, and
     * typing made it worse.
     *
     * The window is anchored: a range starting past the beginning picks its previous hash
     * up from the entry before it, so linkage is checked rather than assumed. And what a
     * short window CANNOT see — entries deleted off the tail, or a wiped scope — is
     * exactly what `verifyChain()` cross-checks against the last signed checkpoint on
     * every call regardless of range. So the cheap answer is honest about a real
     * property; it is simply a narrower one, and the page says which.
     */
    private function verification(): ChainVerification
    {
        $full = $this->fullVerification;

        if ($full !== null) {
            return $full['valid'] || $full['brokenAt'] === null
                ? ChainVerification::valid($full['count'])
                : ChainVerification::broken($full['brokenAt'], (string) $full['reason']);
        }

        $log = app(AuditLog::class);
        $scope = $this->scope();

        return $log->verifyChain($scope, fromSequence: max(1, $log->headSequence($scope) - self::WINDOW + 1));
    }

    /** @return array{entries: list<AuditEntry>, verification: ChainVerification, verifiedEverything: bool} */
    public function with(): array
    {
        return [
            'entries' => $this->entries(),
            'verification' => $this->verification(),
            'verifiedEverything' => $this->fullVerification !== null,
        ];
    }
}; ?>

<div class="space-y-6">
    {{-- The subtitle no longer promises searching "across organizations": it never did
         that safely, and the field that appeared to offer it was the leak. --}}
    <x-page-header title="Audit trail" :help="\App\Platform\Help\HelpTopic::ActivityLog"
                   subtitle="The same append-only, hash-chained trail as the activity log, with the chain verified end to end." />

    {{-- `-strong`, NOT the base tokens. app.css says why the strong pair exists: the base
         `--success` and `--destructive` measure under 4.5:1 on their own soft wash, and
         this text is 12px. "Chain broken" — the most important message on the page — was
         the worse of the two at 3.94:1.

         role=status so the result is announced when it changes after "Verify the whole
         chain"; the button below is otherwise the only control here, and activating it
         used to produce no announcement at all. --}}
    <div role="status" aria-live="polite" class="flex flex-wrap items-center gap-3 rounded-xl border p-4 text-sm"
        style="{{ $verification->valid ? 'border-color:color-mix(in oklch, var(--success) 20%, transparent);background:var(--success-soft);color:var(--success-strong)' : 'border-color:color-mix(in oklch, var(--destructive) 20%, transparent);background:var(--destructive-soft);color:var(--destructive-strong)' }}">
        <span class="font-medium">
            {{ $verification->valid ? 'Chain verified' : 'Chain broken' }}
        </span>
        <span class="text-xs opacity-80">
            @if (! $verification->valid)
                Broke at sequence {{ $verification->brokenAtSequence }} — {{ $verification->reason }}.
            @elseif ($verifiedEverything)
                All {{ number_format($verification->verifiedCount) }} entries checked; hashes and linkage intact.
            @else
                {{-- SAYS WHICH ENTRIES, because "chain verified" over a window is only an
                     honest badge if it names the window. The tail is what a page can
                     re-check on every render; the whole chain is a deliberate ask. --}}
                The most recent {{ number_format($verification->verifiedCount) }} entries checked; hashes and
                linkage intact, and the last signed checkpoint still anchors the entry it was taken over.
            @endif
        </span>

        {{-- ALWAYS RENDERED, so activating it does not delete the element that has focus.
             It used to be inside `@if (! $verifiedEverything)`: one click removed the only
             control on the page, dumping focus to <body> with nothing announced. --}}
        <button type="button" wire:click="verifyEverything" wire:loading.attr="disabled" wire:target="verifyEverything"
            class="btn btn-ghost btn-sm ms-auto shrink-0">
            <span wire:loading.remove wire:target="verifyEverything">
                {{ $verifiedEverything ? 'Check the whole chain again' : 'Verify the whole chain' }}
            </span>
            <span wire:loading wire:target="verifyEverything">Verifying&hellip;</span>
        </button>
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
