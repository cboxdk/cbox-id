import { Link, router } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import { listHref } from '@/lib/listHref';
import { absoluteTime, relativeTime } from '@/lib/time';
import type {
    HelpContent,
    OrganizationFilter,
    PageProps,
    Pagination as PaginationState,
} from '@/types';
import {
    Badge,
    Button,
    EmptyState,
    FilterChips,
    Icon,
    LinkTabs,
    OrganizationFilterChip,
    PageHeader,
    Pagination,
    Pill,
    type PillTone,
    Select,
} from '@/ui';

interface ConnectionRow {
    id: string;
    type: string;
    name: string;
    target: string | null;
    /** The organization it belongs to; null is the whole environment's. */
    organization: string | null;
    status: string;
    /** healthy | failing | idle — or null where the connection records nothing to judge by. */
    verdict: string | null;
    problem: string | null;
    lastActivityAt: string | null;
    /** Its own page; null where this console cannot open it — `note` says where it is changed. */
    href: string | null;
    note: string | null;
}

interface FilterOption {
    key: string;
    label: string;
    count: number;
    href: string;
    selected: boolean;
}

type Props = PageProps<{
    help: HelpContent;
    connections: ConnectionRow[];
    pagination: PaginationState;
    filtered: boolean;
    types: FilterOption[];
    statuses: FilterOption[];
    /** The environment-wide list's Organization chip; null on a page about one organization. */
    organizationFilter: OrganizationFilter | null;
    catalogHref: string;
    /** This list with only the Organization chip kept. */
    clearHref: string;
    wholeEnvironment: boolean;
}>;

function statusPill(row: ConnectionRow): { tone: PillTone; label: string } {
    if (row.verdict === 'failing') {
        return { tone: 'warning', label: 'Failing' };
    }

    if (row.status !== 'active') {
        const words = row.status.replace('_', ' ');

        return { tone: 'neutral', label: words.charAt(0).toUpperCase() + words.slice(1) };
    }

    return row.verdict === 'idle'
        ? { tone: 'neutral', label: 'Active · no traffic yet' }
        : { tone: 'success', label: 'Active' };
}

/**
 * Connectors › Connections — every live connection, of every type, in one list.
 *
 * Failing rows sort first, and each row is its connection's own page: the list is where you
 * notice, the type's page is where you fix. Type and status narrow the list through the URL
 * like the Organization chip does, so "everything failing at Acme" is a link to send.
 */
export default function Connections({
    help,
    connections,
    pagination,
    filtered,
    types,
    statuses,
    organizationFilter,
    catalogHref,
    clearHref,
    wholeEnvironment,
}: Props) {
    const scope = wholeEnvironment ? 'in this environment' : 'for this organization';

    return (
        <div className="space-y-6">
            <PageHeader
                help={help}
                description={`Every live connection ${scope} — single sign-on, directories, provisioning, webhooks, hooks, log streams and pipes — with whether it is working. Open one to change it.`}
                actions={
                    <Button asChild variant="primary" className="shrink-0">
                        <Link href={catalogHref}>
                            <Icon name="plus" className="w-4 h-4" />
                            New connection
                        </Link>
                    </Button>
                }
            />

            <LinkTabs
                label="Status"
                tabs={statuses.map((option) => ({
                    key: option.key === '' ? 'all' : option.key,
                    label: `${option.label} (${option.count.toLocaleString()})`,
                    href: option.href,
                    current: option.selected,
                }))}
            />

            <div className="flex flex-wrap items-center gap-3">
                <div style={{ minWidth: '16rem' }}>
                    <Select
                        aria-label="Type"
                        value={types.find((option) => option.selected)?.key || 'all'}
                        onValueChange={(key) => {
                            const chosen = types.find((option) => (option.key || 'all') === key);

                            if (chosen !== undefined) {
                                router.visit(chosen.href, { preserveScroll: true });
                            }
                        }}
                        options={types.map((option) => ({
                            value: option.key || 'all',
                            label: `${option.label} (${option.count.toLocaleString()})`,
                        }))}
                    />
                </div>

                {organizationFilter !== null && (
                    <FilterChips>
                        <OrganizationFilterChip filter={organizationFilter} />
                    </FilterChips>
                )}
            </div>

            <output className="sr-only">
                {pagination.total} {pagination.total === 1 ? 'connection' : 'connections'} shown.
            </output>

            <div className="card overflow-hidden">
                {connections.length === 0 ? (
                    filtered ? (
                        <EmptyState
                            icon="filter"
                            title="Nothing matches these filters"
                            description="No connection of that type and status. Clear the filters to see every connection."
                            actions={
                                <Button asChild>
                                    <Link href={clearHref}>Clear filters</Link>
                                </Button>
                            }
                        />
                    ) : (
                        <EmptyState
                            icon="connections"
                            title="No connections yet"
                            help={help}
                            description={`Nothing is connected ${scope} yet. The catalog lists every kind of integration and starts the one you need.`}
                            steps={[
                                'Pick a type in the catalog — single sign-on, Directory Sync, an HR system, a webhook, a log stream.',
                                'Its setup form opens, with the provider already chosen where you picked one.',
                                'Once it is saved it is listed here, with whether it is working.',
                            ]}
                            actions={
                                <Button asChild variant="primary">
                                    <Link href={catalogHref}>
                                        <Icon name="plus" className="w-4 h-4" />
                                        New connection
                                    </Link>
                                </Button>
                            }
                        />
                    )
                ) : (
                    <ul>
                        {connections.map((row, index) => (
                            <li
                                key={`${row.type}-${row.id}`}
                                style={
                                    index < connections.length - 1
                                        ? { borderBottom: '1px solid var(--border)' }
                                        : undefined
                                }
                            >
                                <ConnectionLine row={row} />
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <Pagination
                pagination={pagination}
                noun="connection"
                href={(page) => listHref('', page)}
            />
        </div>
    );
}

function ConnectionLine({ row }: { row: ConnectionRow }) {
    const pill = statusPill(row);

    const body = (
        <>
            <div className="min-w-0 flex-1 basis-64">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium [overflow-wrap:anywhere]">{row.name}</span>
                    <Badge>{row.type}</Badge>
                    <Badge>{row.organization ?? 'Whole environment'}</Badge>
                </div>
                {row.target !== null && row.target !== row.name && (
                    <p
                        className="mt-0.5 text-xs truncate"
                        style={{ color: 'var(--muted-foreground)' }}
                    >
                        {row.target}
                    </p>
                )}
                {row.problem !== null && (
                    <p className="mt-0.5 text-xs" style={{ color: 'var(--destructive)' }}>
                        {row.problem}
                    </p>
                )}
                {row.note !== null && (
                    <p className="mt-0.5 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        {row.note}
                    </p>
                )}
            </div>
            <div className="flex shrink-0 flex-col items-start gap-1 sm:items-end">
                <Pill tone={pill.tone}>{pill.label}</Pill>
                <span className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                    {row.lastActivityAt === null ? (
                        'No activity recorded'
                    ) : (
                        <time
                            dateTime={row.lastActivityAt}
                            title={absoluteTime(row.lastActivityAt)}
                        >
                            Last activity {relativeTime(row.lastActivityAt)}
                        </time>
                    )}
                </span>
            </div>
        </>
    );

    if (row.href === null) {
        return <div className="flex flex-wrap items-start gap-3 p-4">{body}</div>;
    }

    return (
        <Link
            href={row.href}
            className="flex flex-wrap items-start gap-3 p-4 transition-colors hover:bg-[var(--surface-2)] sm:flex-nowrap"
            style={{ color: 'var(--foreground)' }}
        >
            {body}
            <Icon
                name="chevron"
                className="mt-1 hidden w-4 h-4 shrink-0 sm:block"
                style={{ color: 'var(--faint)', transform: 'rotate(-90deg)' }}
            />
        </Link>
    );
}

Connections.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
