import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Button, EmptyState, Field, Icon, Input, PageHeader, Table, Td, TdMono, Th } from '@/ui';

interface Run {
    id: string;
    when: string | null;
    status: string;
    entries: number;
    scopes: number;
    sink: string;
}

type Props = PageProps<{
    /** The run history is environment bookkeeping — see the controller. */
    showsRuns: boolean;
    runs: Run[];
    needsOrganization: boolean;
    subjectId: string;
    subjectEntryCount: number | null;
    downloadHref: string;
}>;

export default function Exports({
    showsRuns,
    runs,
    needsOrganization,
    subjectId,
    subjectEntryCount,
    downloadHref,
}: Props) {
    const [subject, setSubject] = useState(subjectId);
    const [building, setBuilding] = useState(false);

    useEffect(() => {
        if (subject === subjectId) {
            return;
        }

        const timer = setTimeout(() => {
            const query = subject === '' ? '' : `?subject=${encodeURIComponent(subject)}`;

            router.get(
                `${window.location.pathname}${query}`,
                {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 500);

        return () => clearTimeout(timer);
    }, [subject, subjectId]);

    return (
        <div className="space-y-6">
            <PageHeader description="Ship the audit trail to your SIEM or cold archive, and run data-subject exports." />

            {/*
                NO BUTTONS HERE. Both jobs act on EVERY chain in the environment — an export
                advances every tenant's cursor, retention checkpoints every scope — so one
                tenant's admin pressing either would move another tenant's position. They are
                operator work and they run on the schedule; these cards say so, because the
                two buttons that used to sit here called methods that only ever returned 403.
            */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div className="card p-5">
                    <h2 className="text-sm font-semibold">Audit export</h2>
                    <p className="mt-1 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        Runs on the schedule every five minutes. Cursor-based and idempotent — only
                        entries newer than the last shipped position are sent.
                    </p>
                    <p className="mt-3 text-xs mono" style={{ color: 'var(--muted-foreground)' }}>
                        php artisan id-compliance:export
                    </p>
                </div>

                <div className="card p-5">
                    <h2 className="text-sm font-semibold">Retention</h2>
                    <p className="mt-1 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        Runs on the schedule daily. The trail is append-only and hash-chained, so
                        retention <strong>never deletes entries</strong>: it signs a fresh
                        checkpoint per chain and relies on the export sink to archive to cold
                        storage.
                    </p>
                    <p className="mt-3 text-xs mono" style={{ color: 'var(--muted-foreground)' }}>
                        php artisan id-compliance:retention
                    </p>
                </div>
            </div>

            {showsRuns && (
                <section>
                    <h2 className="mb-3 text-sm font-semibold">Recent export runs</h2>

                    {runs.length === 0 ? (
                        <EmptyState
                            icon="audit"
                            title="No export runs yet"
                            description="The scheduled export records a run here every five minutes once schedule:run is running and a sink is configured."
                        />
                    ) : (
                        <div className="card overflow-hidden">
                            <div className="overflow-x-auto">
                                <Table caption="Recent scheduled export runs across every chain in this environment">
                                    <thead>
                                        <tr>
                                            <Th>When</Th>
                                            <Th>Status</Th>
                                            <Th className="text-right">Entries</Th>
                                            <Th className="text-right">Scopes</Th>
                                            <Th>Sink</Th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {runs.map((run) => (
                                            <tr key={run.id}>
                                                <TdMono className="whitespace-nowrap text-xs">
                                                    {run.when ?? '—'}
                                                </TdMono>
                                                <Td>
                                                    <span
                                                        className={`badge ${run.status === 'completed' ? 'badge-success' : 'badge-danger'}`}
                                                    >
                                                        {run.status}
                                                    </span>
                                                </Td>
                                                <TdMono className="text-right">
                                                    {run.entries.toLocaleString()}
                                                </TdMono>
                                                <TdMono className="text-right">
                                                    {run.scopes.toLocaleString()}
                                                </TdMono>
                                                <Td style={{ color: 'var(--muted-foreground)' }}>
                                                    {run.sink}
                                                </Td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </Table>
                            </div>
                        </div>
                    )}
                </section>
            )}

            <section>
                <h2 className="mb-3 text-sm font-semibold">Data-subject export (GDPR access)</h2>
                <p className="mb-3 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                    Look up a subject's audit trail (actions they performed) for a portable access
                    request. Erasure is not offered: redacting a hash-chained entry would break the
                    trail's tamper-evidence — see the docs.
                </p>

                {needsOrganization ? (
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        Choose an organization above to run a data-subject export — a bundle is
                        bounded by the organization whose trail it comes from.
                    </p>
                ) : (
                    <div style={{ maxWidth: '28rem' }}>
                        <Field label="Subject (actor id)">
                            <Input
                                placeholder="user id"
                                value={subject}
                                onChange={(event) => setSubject(event.target.value)}
                            />
                        </Field>
                    </div>
                )}

                {/*
                    An <output>, because this card appears after a debounced keystroke: without
                    it the only thing that can report "no matches" is silent to a screen reader.
                */}
                <output className="block">
                    {subjectEntryCount !== null && (
                        <div className="card mt-4 p-4 text-sm">
                            <p>
                                At most{' '}
                                <span className="font-semibold mono">
                                    {subjectEntryCount.toLocaleString()}
                                </span>{' '}
                                {subjectEntryCount === 1 ? 'audit entry' : 'audit entries'} for{' '}
                                <span className="mono">{subjectId}</span>.
                            </p>
                            {/*
                                "At most", which is strictly what it is: the number sums both
                                directions, so an entry where this person is both actor and
                                target counts twice. The bundle deduplicates by sequence, so the
                                file is exact — getting an exact number HERE would mean reading
                                every sequence, which is the sweep this screen exists without.
                            */}
                            <p className="mt-1 text-xs" style={{ color: 'var(--faint)' }}>
                                An upper bound — the file itself is exact and deduplicated.
                            </p>

                            <Button
                                variant="primary"
                                className="mt-3"
                                loading={building}
                                onClick={() => {
                                    setBuilding(true);
                                    router.post(
                                        downloadHref,
                                        { subject: subjectId },
                                        {
                                            preserveScroll: true,
                                            onFinish: () => setBuilding(false),
                                        },
                                    );
                                }}
                            >
                                <Icon name="audit" className="w-4 h-4" />
                                {building ? 'Building the bundle…' : 'Download the bundle (JSON)'}
                            </Button>
                        </div>
                    )}
                </output>
            </section>
        </div>
    );
}

Exports.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
