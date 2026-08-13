<?php

declare(strict_types=1);

use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use App\Platform\OrganizationActivity;
use Cbox\Id\Compliance\Dsr\SubjectDataExport;
use Cbox\Id\Compliance\Export\ExportAuditTrail;
use Cbox\Id\Compliance\Models\AuditExportRun;
use Cbox\Id\Compliance\Retention\RetentionPolicy;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Console › Exports & retention — one component, both planes.
 *
 * The run history is ENVIRONMENT bookkeeping: `AuditExportRun` records how one scheduled
 * export went across every chain in the environment, and carries no organization. So it
 * is shown on the plane that owns the environment, and withheld from the plane that owns
 * one tenant — not because a tenant admin is untrusted, but because "17 scopes scanned"
 * describes other tenants' activity and answers nothing about their own.
 *
 * The data-subject export is the opposite: it is bounded by an organization, and is the
 * half of this page a tenant admin actually needs.
 */
new #[Layout('components.layouts.console', ['title' => 'Exports & retention'])] class extends Component
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

    public string $subjectId = '';

    /**
     * Whether the run history belongs to the reader.
     *
     * A run row has no organization — one run walks every chain in the environment — so
     * there is nothing to scope it BY, and a page that cannot scope a list must decide
     * who may see the whole of it. That is the environment's administrator.
     */
    private function showsRunHistory(): bool
    {
        return app(ConsoleScope::class)->plane() === ConsolePlane::Environment;
    }

    /**
     * Environment-scoped by the model's own BelongsToEnvironment, so this is this
     * environment's runs and never another's — but never narrower than that, which is
     * why {@see showsRunHistory()} decides whether it is read at all.
     *
     * @return Collection<int, AuditExportRun>
     */
    private function runs(): Collection
    {
        if (! $this->showsRunHistory()) {
            /** @var Collection<int, AuditExportRun> */
            return new Collection;
        }

        return AuditExportRun::query()->latest('id')->limit(20)->get();
    }

    /**
     * Null until a subject id is entered — the bundle is only built on demand, and only
     * ever from the acting organization's chain.
     *
     * A DSR bundle needs an organization to be bounded by, so with none resolved there is
     * nothing to build rather than something environment-wide: `forSubject()` takes a
     * string, and passing an environment-wide bundle to a data-subject request would ship
     * one person's records from every tenant they exist in.
     */
    /**
     * Produce the bundle and hand it over as a file.
     *
     * THE PAGE PROMISED THIS AND DID NOT DO IT. The section is titled "Data-subject
     * export (GDPR access)", the empty state says "to run a data-subject export", and
     * there was no button, no route and no command anywhere in the product —
     * `SubjectDataExport::forSubject()` was reachable from tests alone. A compliance
     * officer answering a subject access request could learn how many entries there were
     * and then had nowhere to go.
     *
     * Streamed rather than stored: the bundle is one person's audit history, and writing
     * it to disk to serve it would create a second copy of exactly the data a subject
     * access request exists to hand over once.
     */
    public function download(OrganizationActivity $activity): ?StreamedResponse
    {
        $scope = app(ConsoleScope::class);
        $scope->assertMayAdminister();

        $id = trim($this->subjectId);
        $organizationId = $scope->organizationId();

        if ($id === '' || $organizationId === null) {
            return null;
        }

        $bundle = app(SubjectDataExport::class)->forSubject($id, $organizationId);

        // RECORDED, because handing one person's audit trail to somebody is itself an
        // event that belongs in the trail. Who asked, for whom, and how much.
        $activity->record($organizationId, 'compliance.subject_export', $scope->actorId(),
            targetType: 'user', targetId: $id,
            context: ['entries' => $bundle->auditEntryCount()], request: request());

        return response()->streamDownload(
            fn () => print json_encode($bundle->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'subject-'.preg_replace('/[^A-Za-z0-9_-]/', '', $id).'.json',
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * How much there is, NOT the bundle itself.
     *
     * The screen renders one number, and building the bundle to get it meant two cursor
     * sweeps of the subject's entire audit history into memory — on a field bound with
     * `.live.debounce.500ms`, so a half-typed subject id did it too. The export itself is
     * still exact and still built by the run that produces it.
     */
    private function subjectEntryCount(): ?int
    {
        $id = trim($this->subjectId);
        $organizationId = app(ConsoleScope::class)->organizationId();

        return $id === '' || $organizationId === null
            ? null
            : app(SubjectDataExport::class)->countFor($id, $organizationId);
    }

    /**
     * @return array{runs: Collection<int, AuditExportRun>, showsRuns: bool, needsOrganization: bool, subjectEntryCount: int|null, subjectId: string}
     */
    public function with(): array
    {
        return [
            'runs' => $this->runs(),
            'subjectId' => trim($this->subjectId),
            'showsRuns' => $this->showsRunHistory(),
            // The view half. Without it the DSR field renders on the environment plane
            // with no organization chosen, accepts a subject id, and silently answers
            // nothing — a control that looks broken rather than one that says what it
            // needs.
            'needsOrganization' => app(ConsoleScope::class)->organizationId() === null,
            'subjectEntryCount' => $this->subjectEntryCount(),
        ];
    }
}; ?>

<div class="space-y-6">
    <x-page-header title="Exports & retention"
                   subtitle="Ship the audit trail to your SIEM or cold archive, and run data-subject exports." />

    {{-- No buttons here. Both jobs act on EVERY chain in the environment — an export
         advances every tenant's cursor, retention checkpoints every scope — so one
         tenant's admin pressing either would move another tenant's position. They are
         operator work, and they run on the schedule; these cards say so, because the two
         buttons that used to sit here called methods that only ever returned 403. --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div class="card p-5">
            <h2 class="text-sm font-semibold" style="color:var(--foreground)">Audit export</h2>
            <p class="mt-1 text-xs" style="color:var(--muted)">
                Runs on the schedule every five minutes. Cursor-based and idempotent — only entries newer
                than the last shipped position are sent.
            </p>
            <p class="mt-3 text-xs mono" style="color:var(--muted)">php artisan id-compliance:export</p>
        </div>

        <div class="card p-5">
            <h2 class="text-sm font-semibold" style="color:var(--foreground)">Retention</h2>
            <p class="mt-1 text-xs" style="color:var(--muted)">
                Runs on the schedule daily. The trail is append-only and hash-chained, so retention
                <strong>never deletes entries</strong>: it signs a fresh checkpoint per chain and relies on
                the export sink to archive to cold storage.
            </p>
            <p class="mt-3 text-xs mono" style="color:var(--muted)">php artisan id-compliance:retention</p>
        </div>
    </div>

    {{-- The view half of the scoping decision above. A run row describes an export
         across every chain in the environment and carries no organization, so on the
         organization plane there is nothing here that is the reader's — and rendering
         the table anyway would show one tenant how much of the environment's activity
         belongs to the others. --}}
    @if ($showsRuns)
    <section>
        <h2 class="mb-3 text-sm font-semibold" style="color:var(--foreground)">Recent export runs</h2>
        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">When</th>
                            <th scope="col">Status</th>
                            <th class="text-right">Entries</th>
                            <th class="text-right">Scopes</th>
                            <th scope="col">Sink</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($runs as $run)
                            <tr>
                                <td class="whitespace-nowrap mono text-xs" style="color:var(--muted)">{{ $run->finished_at?->diffForHumans() ?? $run->created_at?->diffForHumans() }}</td>
                                <td>
                                    <span class="badge {{ $run->status === 'completed' ? 'badge-success' : 'badge-danger' }}">
                                        {{ $run->status }}
                                    </span>
                                </td>
                                <td class="text-right mono" style="color:var(--foreground)">{{ number_format($run->entries_exported) }}</td>
                                <td class="text-right mono" style="color:var(--foreground)">{{ number_format($run->scopes_scanned) }}</td>
                                <td style="color:var(--muted)">{{ class_basename($run->sink ?? '—') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">
                                    <div class="cbx-empty">
                                        <h3>No export runs yet</h3>
                                        <p>The scheduled export records a run here every five minutes once
                                           <code>schedule:run</code> is running and a sink is configured.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>
    @endif

    <section>
        <h2 class="mb-3 text-sm font-semibold" style="color:var(--foreground)">Data-subject export (GDPR access)</h2>
        <p class="mb-3 text-xs" style="color:var(--muted)">
            Look up a subject's audit trail (actions they performed) for a portable access request. Erasure is not offered:
            redacting a hash-chained entry would break the trail's tamper-evidence — see the docs.
        </p>

        @if ($needsOrganization)
            <p class="text-sm" style="color:var(--muted)">
                Choose an organization above to run a data-subject export — a bundle is bounded by
                the organization whose trail it comes from.
            </p>
        @else
        <label class="block max-w-md">
            <span class="label">Subject (actor id)</span>
            <input type="text" wire:model.live.debounce.500ms="subjectId" placeholder="user id"
                class="input mt-1 w-full" />
        </label>
        @endif

        {{-- ROLE=STATUS, because this card morphs in after a debounced keystroke: without
             it the only thing that can report "no matches" is silent to a screen reader. --}}
        <div role="status" aria-live="polite">
            @if ($subjectEntryCount !== null)
                <div class="card mt-4 p-4 text-sm">
                    <p style="color:var(--foreground)">
                        At most <span class="font-semibold mono">{{ number_format($subjectEntryCount) }}</span>
                        {{ \Illuminate\Support\Str::plural('audit entry', $subjectEntryCount) }}
                        for <span class="mono">{{ $subjectId }}</span>.
                    </p>
                    {{-- "At most", which is strictly what it is: the number sums both
                         directions, so an entry where this person is both actor and target
                         counts twice. The bundle deduplicates by sequence, so the file is
                         exact — getting an exact number HERE would mean reading every
                         sequence, which is the sweep this screen exists without. --}}
                    <p class="mt-1 text-xs" style="color:var(--faint)">
                        An upper bound — the file itself is exact and deduplicated.
                    </p>

                    <button type="button" wire:click="download" class="btn btn-primary mt-3"
                            wire:loading.attr="disabled" wire:target="download">
                        <span wire:loading.remove wire:target="download">
                            <x-icon name="audit" class="w-4 h-4" /> Download the bundle (JSON)
                        </span>
                        <span wire:loading wire:target="download">Building the bundle&hellip;</span>
                    </button>
                </div>
            @endif
        </div>
    </section>
</div>
