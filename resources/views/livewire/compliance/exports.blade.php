<?php

use Cbox\Id\Compliance\Dsr\SubjectDataExport;
use Cbox\Id\Compliance\Export\ExportAuditTrail;
use Cbox\Id\Compliance\Models\AuditExportRun;
use Cbox\Id\Compliance\Retention\RetentionPolicy;

use function Livewire\Volt\{computed, state};

state(['subjectId' => '']);

$runs = computed(fn () => AuditExportRun::query()->latest('id')->limit(20)->get());

$subject = computed(function () {
    $id = trim($this->subjectId);

    if ($id === '') {
        return null;
    }

    return app(SubjectDataExport::class)->forSubject($id);
});

$runExport = function (): void {
    app(ExportAuditTrail::class)->run();
};

$applyRetention = function (): void {
    app(RetentionPolicy::class)->apply();
};

?>

<div class="mx-auto max-w-5xl px-4 py-8">
    <header class="mb-6">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">Exports &amp; retention</h1>
        <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
            Ship the audit trail to your SIEM or cold archive, and run data-subject exports.
        </p>
    </header>

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div class="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
            <h2 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">Audit export</h2>
            <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                Cursor-based and idempotent — only entries newer than the last shipped position are sent.
            </p>
            <button type="button" wire:click="runExport"
                class="mt-4 inline-flex items-center rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                Run export now
            </button>
        </div>

        <div class="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
            <h2 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">Retention</h2>
            <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                The trail is append-only and hash-chained, so retention <strong>never deletes entries</strong>.
                Applying it signs a fresh checkpoint per chain and relies on the export sink to archive to cold storage.
            </p>
            <button type="button" wire:click="applyRetention"
                class="mt-4 inline-flex items-center rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800">
                Checkpoint &amp; anchor
            </button>
        </div>
    </div>

    <section class="mb-8">
        <h2 class="mb-3 text-sm font-semibold text-neutral-900 dark:text-neutral-100">Recent export runs</h2>
        <div class="overflow-x-auto rounded-xl border border-neutral-200 dark:border-neutral-800">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                <thead class="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500 dark:bg-neutral-900 dark:text-neutral-400">
                    <tr>
                        <th class="px-4 py-3 font-medium">When</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium text-right tabular-nums">Entries</th>
                        <th class="px-4 py-3 font-medium text-right tabular-nums">Scopes</th>
                        <th class="px-4 py-3 font-medium">Sink</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/60">
                    @forelse ($this->runs as $run)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3 text-neutral-500 dark:text-neutral-400">{{ $run->finished_at?->diffForHumans() ?? $run->created_at?->diffForHumans() }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $run->status === 'completed' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-rose-100 text-rose-800 dark:bg-rose-500/15 dark:text-rose-300' }}">
                                    {{ $run->status }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-neutral-700 dark:text-neutral-300">{{ number_format($run->entries_exported) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-neutral-700 dark:text-neutral-300">{{ number_format($run->scopes_scanned) }}</td>
                            <td class="px-4 py-3 text-neutral-500 dark:text-neutral-400">{{ class_basename($run->sink ?? '—') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10 text-center text-neutral-400">No export runs yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section>
        <h2 class="mb-3 text-sm font-semibold text-neutral-900 dark:text-neutral-100">Data-subject export (GDPR access)</h2>
        <p class="mb-3 text-xs text-neutral-500 dark:text-neutral-400">
            Look up a subject's audit trail (actions they performed) for a portable access request. Erasure is not offered:
            redacting a hash-chained entry would break the trail's tamper-evidence — see the docs.
        </p>
        <label class="block max-w-md">
            <span class="text-xs font-medium uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Subject (actor id)</span>
            <input type="text" wire:model.live.debounce.500ms="subjectId" placeholder="user id"
                class="mt-1 w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm text-neutral-900 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100" />
        </label>

        @php($subject = $this->subject)
        @if ($subject)
            <div class="mt-4 rounded-xl border border-neutral-200 bg-white p-4 text-sm dark:border-neutral-800 dark:bg-neutral-900">
                <p class="text-neutral-700 dark:text-neutral-300">
                    <span class="font-semibold tabular-nums">{{ number_format($subject->auditEntryCount()) }}</span>
                    audit entrie(s) found for <span class="font-mono">{{ $subject->subjectId }}</span>.
                </p>
            </div>
        @endif
    </section>
</div>
