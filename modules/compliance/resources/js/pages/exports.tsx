import { useTranslator } from '@/i18n';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, OrganizationFilter, PageProps } from '@/types';
import {
    Button,
    EmptyState,
    Field,
    FilterChips,
    Icon,
    Input,
    OrganizationFilterChip,
    PageHeader,
    Table,
    Td,
    TdMono,
    Th,
} from '@/ui';

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
    /** On the environment's own page: the chip that goes to one organization's exports. */
    organizationFilter: OrganizationFilter | null;
    subjectId: string;
    subjectEntryCount: number | null;
    downloadHref: string;
    help: HelpContent;
}>;

export default function Exports({
    showsRuns,
    runs,
    needsOrganization,
    organizationFilter,
    subjectId,
    subjectEntryCount,
    downloadHref,
    help,
}: Props) {
    const { locale } = useTranslator();

    const { t: tc } = useTranslator();

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
            <PageHeader
                help={help}
                description={tc('console.ship_the_audit_trail_to_your_siem_or_cold_ar_b777e4b4')}
            />

            {/*
                NO BUTTONS HERE. Both jobs act on EVERY chain in the environment — an export
                advances every tenant's cursor, retention checkpoints every scope — so one
                tenant's admin pressing either would move another tenant's position. They are
                operator work and they run on the schedule; these cards say so, because the
                two buttons that used to sit here called methods that only ever returned 403.
            */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div className="card p-5">
                    <h2 className="text-sm font-semibold">{tc('console.audit_export_6215b564')}</h2>
                    <p className="mt-1 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        {tc('console.runs_on_the_schedule_every_five_minutes_curs_830d7536')}
                    </p>
                    <p className="mt-3 text-xs mono" style={{ color: 'var(--muted-foreground)' }}>
                        {tc('console.php_artisan_id_compliance_export_a7898bc2')}
                    </p>
                </div>

                <div className="card p-5">
                    <h2 className="text-sm font-semibold">{tc('console.retention_3b1f58d9')}</h2>
                    <p className="mt-1 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        {tc('console.runs_on_the_schedule_daily_the_trail_is_appe_715337d6')}
                        <strong>{tc('console.never_deletes_entries_8e4d1de2')}</strong>
                        {tc('console.it_signs_a_fresh_checkpoint_per_chain_and_re_8e26cad9')}
                    </p>
                    <p className="mt-3 text-xs mono" style={{ color: 'var(--muted-foreground)' }}>
                        {tc('console.php_artisan_id_compliance_retention_b63e26c9')}
                    </p>
                </div>
            </div>

            {showsRuns && (
                <section>
                    <h2 className="mb-3 text-sm font-semibold">
                        {tc('console.recent_export_runs_bb0e8ac0')}
                    </h2>

                    {runs.length === 0 ? (
                        <EmptyState
                            icon="audit"
                            title={tc('console.no_export_runs_yet_8e77ca79')}
                            description={tc(
                                'console.the_scheduled_export_records_a_run_here_ever_6e615811',
                            )}
                        />
                    ) : (
                        <div className="card overflow-hidden">
                            <div className="overflow-x-auto">
                                <Table
                                    caption={tc(
                                        'console.recent_scheduled_export_runs_across_every_ch_dcd65409',
                                    )}
                                >
                                    <thead>
                                        <tr>
                                            <Th>{tc('console.when_cf9c7aa2')}</Th>
                                            <Th>{tc('console.status_920e413c')}</Th>
                                            <Th className="text-right">
                                                {tc('console.entries_7cb76b4a')}
                                            </Th>
                                            <Th className="text-right">
                                                {tc('console.scopes_0d5644ff')}
                                            </Th>
                                            <Th>{tc('console.sink_35d86691')}</Th>
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
                                                    {run.entries.toLocaleString(locale)}
                                                </TdMono>
                                                <TdMono className="text-right">
                                                    {run.scopes.toLocaleString(locale)}
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
                <h2 className="mb-3 text-sm font-semibold">
                    {tc('console.data_subject_export_gdpr_access_5ece6451')}
                </h2>
                <p className="mb-3 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                    {tc('console.look_up_a_subject_s_audit_trail_actions_they_71f49e12')}
                </p>

                {needsOrganization ? (
                    <div className="space-y-3">
                        {organizationFilter !== null && (
                            <FilterChips>
                                <OrganizationFilterChip filter={organizationFilter} />
                            </FilterChips>
                        )}
                        <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                            {tc('console.a_data_subject_export_is_bounded_by_the_orga_326e9035')}
                        </p>
                    </div>
                ) : (
                    <div style={{ maxWidth: '28rem' }}>
                        <Field label={tc('console.subject_actor_id_5f87b678')}>
                            <Input
                                placeholder={tc('console.user_id_9b55b418')}
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
                                {tc('console.at_most_6797626f')}{' '}
                                <span className="font-semibold mono">
                                    {subjectEntryCount.toLocaleString(locale)}
                                </span>{' '}
                                {subjectEntryCount === 1
                                    ? tc('console.audit_entry_b45ffb38')
                                    : tc('console.audit_entries_2e2e75ab')}
                                {tc('console.for_a3fa3964')}{' '}
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
                                {tc(
                                    'console.an_upper_bound_the_file_itself_is_exact_and__1fc09fe1',
                                )}
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
                                {building
                                    ? tc('console.building_the_bundle_265bc262')
                                    : tc('console.download_the_bundle_json_ed321ec0')}
                            </Button>
                        </div>
                    )}
                </output>
            </section>
        </div>
    );
}

Exports.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
