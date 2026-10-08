import { Link, router, useForm } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import { useTranslator } from '@/i18n';
import PortalLayout from '@/layouts/PortalLayout';
import type { PageProps } from '@/types';
import {
    AuditEventList,
    type AuditEventRow,
    Button,
    EmptyState,
    Field,
    Icon,
    Input,
    PageHeader,
} from '@/ui';

interface Filters {
    action: string;
    actor: string;
    target: string;
    from: string;
    to: string;
}

type Props = PageProps<{
    organizationName: string | null;
    events: AuditEventRow[];
    filters: Filters;
    nextHref: string | null;
    firstHref: string | null;
    exportHref: string;
    exportLimit: number;
    finishHref: string;
    /** Back to the checklist, when the link covers more than the audit logs. */
    homeHref: string | null;
}>;

/**
 * THE ADMIN PORTAL'S AUDIT LOGS — an organization's admin, holding a one-time link and no
 * account, reading what the application recorded about their organization. Read-only;
 * the CSV is a plain download of what the filters select.
 */
export default function PortalAuditLogs({
    organizationName,
    events,
    filters,
    nextHref,
    firstHref,
    exportHref,
    exportLimit,
    finishHref,
    homeHref,
}: Props) {
    const { t } = useTranslator();
    const [draft, setDraft] = useState<Filters>(filters);
    const finish = useForm({});
    const filtered = Object.values(filters).some((value) => value !== '');

    const apply = (event: FormEvent): void => {
        event.preventDefault();
        router.get(
            window.location.pathname,
            Object.fromEntries(Object.entries(draft).filter(([, value]) => value !== '')),
            { preserveScroll: true },
        );
    };

    return (
        <div>
            {homeHref !== null && (
                <Link
                    href={homeHref}
                    className="text-sm inline-flex items-center gap-1 mb-3"
                    style={{ color: 'var(--muted-foreground)' }}
                >
                    <Icon name="arrow-left" className="w-3.5 h-3.5" /> {t('portal.nav.back')}
                </Link>
            )}
            <PageHeader
                eyebrow={null}
                title={
                    organizationName === null
                        ? t('portal.audit_logs.heading')
                        : t('portal.audit_logs.heading_for', { organization: organizationName })
                }
                description={t('portal.audit_logs.description')}
                actions={
                    <Button asChild>
                        <a href={exportHref} download>
                            <Icon name="download" className="w-4 h-4" />
                            {t('portal.audit_logs.export')}
                        </a>
                    </Button>
                }
            />
            <p className="mt-2 text-xs" style={{ color: 'var(--faint)' }}>
                {t('portal.audit_logs.export_note', { limit: exportLimit.toLocaleString() })}
            </p>

            <form onSubmit={apply} className="mt-6 grid gap-3 sm:grid-cols-5 items-end">
                <Field label={t('portal.audit_logs.action')}>
                    <Input
                        value={draft.action}
                        onChange={(event) => setDraft({ ...draft, action: event.target.value })}
                    />
                </Field>
                <Field label={t('portal.audit_logs.actor')}>
                    <Input
                        value={draft.actor}
                        onChange={(event) => setDraft({ ...draft, actor: event.target.value })}
                    />
                </Field>
                <Field label={t('portal.audit_logs.target')}>
                    <Input
                        value={draft.target}
                        onChange={(event) => setDraft({ ...draft, target: event.target.value })}
                    />
                </Field>
                <Field label={t('portal.audit_logs.from')}>
                    <Input
                        type="date"
                        value={draft.from}
                        onChange={(event) => setDraft({ ...draft, from: event.target.value })}
                    />
                </Field>
                <Field label={t('portal.audit_logs.to')}>
                    <Input
                        type="date"
                        value={draft.to}
                        onChange={(event) => setDraft({ ...draft, to: event.target.value })}
                    />
                </Field>
                <div className="sm:col-span-5 flex items-center gap-2">
                    <Button type="submit">
                        <Icon name="search" className="w-4 h-4" />
                        {t('portal.audit_logs.filter')}
                    </Button>
                    {filtered && (
                        <Button asChild variant="ghost">
                            <Link href={window.location.pathname}>
                                {t('portal.audit_logs.clear')}
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
                            title={
                                filtered
                                    ? t('portal.audit_logs.empty')
                                    : t('portal.audit_logs.empty_none')
                            }
                        />
                    </div>
                ) : (
                    <>
                        <output className="sr-only">
                            {t('portal.audit_logs.count', { count: events.length })}
                        </output>
                        <AuditEventList
                            events={events}
                            labels={{
                                caption: t('portal.audit_logs.heading'),
                                actor: t('portal.audit_logs.actor'),
                                targets: t('portal.audit_logs.targets'),
                                location: t('portal.audit_logs.location'),
                            }}
                        />
                    </>
                )}

                {(firstHref !== null || nextHref !== null) && (
                    <nav className="mt-4 flex items-center justify-between">
                        {firstHref !== null ? (
                            <Button asChild variant="ghost">
                                <Link href={firstHref} preserveScroll>
                                    {t('portal.audit_logs.newer')}
                                </Link>
                            </Button>
                        ) : (
                            <span />
                        )}
                        {nextHref !== null && (
                            <Button asChild variant="ghost">
                                <Link href={nextHref} preserveScroll>
                                    {t('portal.audit_logs.older')}
                                </Link>
                            </Button>
                        )}
                    </nav>
                )}
            </div>

            <div
                className="mt-8 flex items-center justify-end gap-2 border-t pt-5"
                style={{ borderColor: 'var(--border)' }}
            >
                <Button
                    variant="primary"
                    loading={finish.processing}
                    onClick={() => finish.post(finishHref)}
                >
                    <Icon name="check" className="w-4 h-4" /> {t('portal.audit_logs.done')}
                </Button>
            </div>
        </div>
    );
}

PortalAuditLogs.layout = (page: React.ReactNode) => <PortalLayout>{page}</PortalLayout>;
