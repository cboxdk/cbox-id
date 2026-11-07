import { useTranslator as useFormatTranslator } from '@/i18n';
import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import { absoluteTime } from '@/lib/time';
import type { HelpContent, OrganizationFilter, PageProps } from '@/types';
import {
    AuditEventList,
    type AuditEventRow,
    Button,
    EmptyState,
    Field,
    Icon,
    Input,
    OrganizationFilterChip,
    PageHeader,
    Pill,
} from '@/ui';

interface Filters {
    action: string;
    actor: string;
    target: string;
    from: string;
    to: string;
}

interface ExportRow {
    id: string;
    state: 'pending' | 'ready' | 'failed' | 'expired';
    rowCount: number | null;
    createdAt: string;
    downloadHref: string | null;
}

type Props = PageProps<{
    help: HelpContent;
    events: AuditEventRow[];
    filters: Filters;
    nextHref: string | null;
    firstHref: string | null;
    /** No organization is chosen: every organization's events. */
    environmentWide: boolean;
    organizationFilter: OrganizationFilter | null;
    exports: ExportRow[];
    exportHref: string;
    /** The organization an environment-console export is for; null for every one, or on an organization's own console. */
    exportOrganization: string | null;
    /** The environment console's schemas & retention page; null on an organization's console. */
    schemasHref: string | null;
}>;

const EXPORT_TONES = {
    pending: 'info',
    ready: 'success',
    failed: 'destructive',
    expired: 'neutral',
} as const;

/**
 * The audit events an app sends about its customers — read here by the app's own team
 * (every organization, or one) and by a customer's administrators (their own).
 */
export default function AuditLogsIndex({
    events,
    filters,
    nextHref,
    firstHref,
    environmentWide,
    organizationFilter,
    exports,
    exportHref,
    exportOrganization,
    schemasHref,
    help,
}: Props) {
    const { t: consoleLastText } = useConsoleTranslator();
    const { locale: consoleLocale } = useFormatTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [draft, setDraft] = useState<Filters>(filters);
    const exporter = useForm<Filters & { organization: string }>({
        ...filters,
        organization: exportOrganization ?? '',
    });
    const filtered = Object.values(filters).some((value) => value !== '');
    // A refusal of the export lands on the page's own error bag, not on a field of this form.
    const exportError = usePage().props.errors.export;

    const apply = (event: FormEvent): void => {
        event.preventDefault();
        const query = Object.fromEntries(Object.entries(draft).filter(([, value]) => value !== ''));

        router.get(
            window.location.pathname,
            {
                ...query,
                ...(organizationFilter?.selected
                    ? { organization: organizationFilter.selected.id }
                    : {}),
            },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <PageHeader
                help={help}
                description={
                    environmentWide
                        ? consoleText(
                              'console.the_audit_events_your_app_sends_about_the_organizations_19cde284',
                          )
                        : consoleText(
                              'console.the_audit_events_the_app_records_about_this_organizatio_5a83961e',
                          )
                }
                actions={
                    <div className="flex items-center gap-2">
                        {schemasHref !== null && (
                            <Button asChild>
                                <Link href={schemasHref}>
                                    {consoleRich('console._part1_schemas_retention_b1bdf59a', {
                                        part1: <Icon name="sliders" className="w-4 h-4" />,
                                    })}
                                </Link>
                            </Button>
                        )}
                        <Button
                            variant="primary"
                            loading={exporter.processing}
                            onClick={() => exporter.post(exportHref, { preserveScroll: true })}
                        >
                            {consoleRich('console._part1_export_csv_468c54ce', {
                                part1: <Icon name="download" className="w-4 h-4" />,
                            })}
                        </Button>
                    </div>
                }
            />

            {typeof exportError === 'string' && (
                <p role="alert" className="field-error mt-3">
                    {exportError}
                </p>
            )}

            <form onSubmit={apply} className="mt-6 grid gap-3 sm:grid-cols-6 items-end">
                <Field label={consoleT('console.action_64cff131')} className="sm:col-span-2">
                    <Input
                        value={draft.action}
                        placeholder="invoice.voided"
                        onChange={(event) => setDraft({ ...draft, action: event.target.value })}
                    />
                </Field>
                <Field label={consoleT('console.actor_id_ecbb48b4')}>
                    <Input
                        value={draft.actor}
                        onChange={(event) => setDraft({ ...draft, actor: event.target.value })}
                    />
                </Field>
                <Field label={consoleT('console.target_id_32a09089')}>
                    <Input
                        value={draft.target}
                        onChange={(event) => setDraft({ ...draft, target: event.target.value })}
                    />
                </Field>
                <Field label={consoleT('console.from_21819769')}>
                    <Input
                        type="date"
                        value={draft.from}
                        onChange={(event) => setDraft({ ...draft, from: event.target.value })}
                    />
                </Field>
                <Field label={consoleT('console.to_f4b06ef6')}>
                    <Input
                        type="date"
                        value={draft.to}
                        onChange={(event) => setDraft({ ...draft, to: event.target.value })}
                    />
                </Field>
                <div className="sm:col-span-6 flex flex-wrap items-center gap-2">
                    {organizationFilter !== null && (
                        <OrganizationFilterChip filter={organizationFilter} />
                    )}
                    <Button type="submit">
                        {consoleRich('console._part1_filter_735b6e83', {
                            part1: <Icon name="search" className="w-4 h-4" />,
                        })}
                    </Button>
                    {filtered && (
                        <Button asChild variant="ghost">
                            <Link href={window.location.pathname}>
                                {consoleT('console.clear_filters_7179ea00')}
                            </Link>
                        </Button>
                    )}
                </div>
            </form>

            <div className="mt-6">
                {events.length === 0 ? (
                    <div className="rounded-xl border" style={{ borderColor: 'var(--border)' }}>
                        <EmptyState
                            icon="audit"
                            equivalent="audit_logs.events.create"
                            title={
                                filtered
                                    ? consoleText('console.no_events_match_f29f67a5')
                                    : consoleText('console.no_audit_events_yet_bb438bd9')
                            }
                            description={
                                filtered
                                    ? consoleText(
                                          'console.nothing_recorded_matches_these_filters_widen_the_range_804705cd',
                                      )
                                    : consoleText(
                                          'console.your_app_sends_these_one_call_per_batch_of_events_each_42f272f6',
                                      )
                            }
                        />
                    </div>
                ) : (
                    <AuditEventList
                        events={events}
                        labels={{
                            caption: consoleLastText('console.audit_events_cc672a8a'),
                            actor: consoleLastText('console.actor_449995c4'),
                            targets: consoleLastText('console.target_978354db'),
                            location: consoleLastText('console.location_15b61974'),
                        }}
                    />
                )}

                {(firstHref !== null || nextHref !== null) && (
                    <nav
                        aria-label={consoleT('console.pages_9046da16')}
                        className="mt-4 flex items-center justify-between"
                    >
                        {firstHref !== null ? (
                            <Button asChild variant="ghost">
                                <Link href={firstHref} preserveScroll>
                                    {consoleT('console.newest_events_37d3ab85')}
                                </Link>
                            </Button>
                        ) : (
                            <span />
                        )}
                        {nextHref !== null && (
                            <Button asChild variant="ghost">
                                <Link href={nextHref} preserveScroll>
                                    {consoleT('console.older_events_672bb013')}
                                </Link>
                            </Button>
                        )}
                    </nav>
                )}
            </div>

            {exports.length > 0 && (
                <section className="mt-10">
                    <h2 className="text-sm font-semibold">
                        {consoleT('console.recent_exports_53e38c72')}
                    </h2>
                    <ul className="mt-3 rounded-xl border" style={{ borderColor: 'var(--border)' }}>
                        {exports.map((item, index) => (
                            <li
                                key={item.id}
                                className="flex items-center gap-3 p-3 text-sm"
                                style={
                                    index < exports.length - 1
                                        ? { borderBottom: '1px solid var(--border)' }
                                        : undefined
                                }
                            >
                                <span className="mono text-xs" style={{ color: 'var(--faint)' }}>
                                    {absoluteTime(item.createdAt, consoleLocale)}
                                </span>
                                <Pill tone={EXPORT_TONES[item.state]}>{item.state}</Pill>
                                {item.rowCount !== null && (
                                    <span style={{ color: 'var(--muted)' }}>
                                        {item.rowCount}{' '}
                                        {item.rowCount === 1
                                            ? consoleText('console.event_b8e1f80b')
                                            : consoleText('console.events_862417b9')}
                                    </span>
                                )}
                                {item.downloadHref !== null && (
                                    <a className="ml-auto link" href={item.downloadHref}>
                                        {consoleT('console.download_csv_a4023b89')}
                                    </a>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </>
    );
}

AuditLogsIndex.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
