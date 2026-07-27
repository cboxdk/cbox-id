<?php

declare(strict_types=1);

use Cbox\Id\Compliance\Dsr\SubjectDataBundle;
use Cbox\Id\Compliance\Dsr\SubjectDataExport;
use Cbox\Id\Compliance\Export\ExportAuditTrail;
use Cbox\Id\Compliance\Models\AuditExportRun;
use Cbox\Id\Compliance\Retention\RetentionPolicy;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public string $subjectId = '';

    public function runExport(): void
    {
        app(ExportAuditTrail::class)->run();
    }

    public function applyRetention(): void
    {
        app(RetentionPolicy::class)->apply();
    }

    /** @return Collection<int, AuditExportRun> */
    private function runs(): Collection
    {
        return AuditExportRun::query()->latest('id')->limit(20)->get();
    }

    /** Null until a subject id is entered — the bundle is only built on demand. */
    private function subject(): ?SubjectDataBundle
    {
        $id = trim($this->subjectId);

        return $id === '' ? null : app(SubjectDataExport::class)->forSubject($id);
    }

    /** @return array{runs: Collection<int, AuditExportRun>, subject: SubjectDataBundle|null} */
    public function with(): array
    {
        return [
            'runs' => $this->runs(),
            'subject' => $this->subject(),
        ];
    }
}; ?>

<div class="space-y-6">
    <div class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title">Exports &amp; retention</h1>
            <p class="cbx-page-desc">
                Ship the audit trail to your SIEM or cold archive, and run data-subject exports.
            </p>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div class="card p-5">
            <h2 class="text-sm font-semibold" style="color:var(--foreground)">Audit export</h2>
            <p class="mt-1 text-xs" style="color:var(--muted)">
                Cursor-based and idempotent — only entries newer than the last shipped position are sent.
            </p>
            <button type="button" wire:click="runExport" class="btn btn-primary btn-sm mt-4">
                Run export now
            </button>
        </div>

        <div class="card p-5">
            <h2 class="text-sm font-semibold" style="color:var(--foreground)">Retention</h2>
            <p class="mt-1 text-xs" style="color:var(--muted)">
                The trail is append-only and hash-chained, so retention <strong>never deletes entries</strong>.
                Applying it signs a fresh checkpoint per chain and relies on the export sink to archive to cold storage.
            </p>
            <button type="button" wire:click="applyRetention" class="btn btn-ghost btn-sm mt-4">
                Checkpoint &amp; anchor
            </button>
        </div>
    </div>

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
                                        <p>Run an export above and completed runs will appear here.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section>
        <h2 class="mb-3 text-sm font-semibold" style="color:var(--foreground)">Data-subject export (GDPR access)</h2>
        <p class="mb-3 text-xs" style="color:var(--muted)">
            Look up a subject's audit trail (actions they performed) for a portable access request. Erasure is not offered:
            redacting a hash-chained entry would break the trail's tamper-evidence — see the docs.
        </p>
        <label class="block max-w-md">
            <span class="label">Subject (actor id)</span>
            <input type="text" wire:model.live.debounce.500ms="subjectId" placeholder="user id"
                class="input mt-1 w-full" />
        </label>

        @if ($subject)
            <div class="card mt-4 p-4 text-sm">
                <p style="color:var(--foreground)">
                    <span class="font-semibold mono">{{ number_format($subject->auditEntryCount()) }}</span>
                    audit entrie(s) found for <span class="mono">{{ $subject->subjectId }}</span>.
                </p>
            </div>
        @endif
    </section>
</div>
