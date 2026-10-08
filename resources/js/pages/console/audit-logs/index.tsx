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
                        ? 'The audit events your app sends about the organizations it serves, newest first — every organization’s. Each organization’s events form a hash chain of their own.'
                        : 'The audit events the app records about this organization, newest first. Read-only: nothing here can be changed.'
                }
                actions={
                    <div className="flex items-center gap-2">
                        {schemasHref !== null && (
                            <Button asChild>
                                <Link href={schemasHref}>
                                    <Icon name="sliders" className="w-4 h-4" />
                                    Schemas & retention
                                </Link>
                            </Button>
                        )}
                        <Button
                            variant="primary"
                            loading={exporter.processing}
                            onClick={() => exporter.post(exportHref, { preserveScroll: true })}
                        >
                            <Icon name="download" className="w-4 h-4" />
                            Export CSV
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
                <Field label="Action" className="sm:col-span-2">
                    <Input
                        value={draft.action}
                        placeholder="invoice.voided"
                        onChange={(event) => setDraft({ ...draft, action: event.target.value })}
                    />
                </Field>
                <Field label="Actor ID">
                    <Input
                        value={draft.actor}
                        onChange={(event) => setDraft({ ...draft, actor: event.target.value })}
                    />
                </Field>
                <Field label="Target ID">
                    <Input
                        value={draft.target}
                        onChange={(event) => setDraft({ ...draft, target: event.target.value })}
                    />
                </Field>
                <Field label="From">
                    <Input
                        type="date"
                        value={draft.from}
                        onChange={(event) => setDraft({ ...draft, from: event.target.value })}
                    />
                </Field>
                <Field label="To">
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
                        <Icon name="search" className="w-4 h-4" />
                        Filter
                    </Button>
                    {filtered && (
                        <Button asChild variant="ghost">
                            <Link href={window.location.pathname}>Clear filters</Link>
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
                            title={filtered ? 'No events match' : 'No audit events yet'}
                            description={
                                filtered
                                    ? 'Nothing recorded matches these filters. Widen the range or clear a filter.'
                                    : 'Your app sends these: one call per batch of events, each naming the organization it happened in. They appear here — and to that organization’s own admins — as they arrive.'
                            }
                        />
                    </div>
                ) : (
                    <AuditEventList
                        events={events}
                        labels={{
                            caption: 'Audit events',
                            actor: 'Actor',
                            targets: 'Target',
                            location: 'Location',
                        }}
                    />
                )}

                {(firstHref !== null || nextHref !== null) && (
                    <nav aria-label="Pages" className="mt-4 flex items-center justify-between">
                        {firstHref !== null ? (
                            <Button asChild variant="ghost">
                                <Link href={firstHref} preserveScroll>
                                    Newest events
                                </Link>
                            </Button>
                        ) : (
                            <span />
                        )}
                        {nextHref !== null && (
                            <Button asChild variant="ghost">
                                <Link href={nextHref} preserveScroll>
                                    Older events
                                </Link>
                            </Button>
                        )}
                    </nav>
                )}
            </div>

            {exports.length > 0 && (
                <section className="mt-10">
                    <h2 className="text-sm font-semibold">Recent exports</h2>
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
                                    {absoluteTime(item.createdAt)}
                                </span>
                                <Pill tone={EXPORT_TONES[item.state]}>{item.state}</Pill>
                                {item.rowCount !== null && (
                                    <span style={{ color: 'var(--muted)' }}>
                                        {item.rowCount} {item.rowCount === 1 ? 'event' : 'events'}
                                    </span>
                                )}
                                {item.downloadHref !== null && (
                                    <a className="ml-auto link" href={item.downloadHref}>
                                        Download CSV
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
