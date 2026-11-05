import type { Translator } from '@/i18n';
import { useTranslator } from '@/i18n';
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

function statusPill(row: ConnectionRow, tc: Translator['t']): { tone: PillTone; label: string } {
    if (row.verdict === 'failing') {
        return { tone: 'warning', label: tc('console.failing_3903780c') };
    }

    if (row.status !== 'active') {
        const labels = {
            draft: tc('console.draft_ebf12ef4'),
            paused: tc('console.paused_e159b061'),
            disabled: tc('console.disabled_75081b59'),
            suspended: tc('console.suspended_e392a389'),
        };
        return {
            tone: 'neutral',
            label: labels[row.status as keyof typeof labels] ?? row.status.replace('_', ' '),
        };
    }

    return row.verdict === 'idle'
        ? { tone: 'neutral', label: tc('console.active_no_traffic_yet_f5107c20') }
        : { tone: 'success', label: tc('console.active_92340695') };
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
    const { locale } = useTranslator();

    const { t: tc } = useTranslator();

    const scope = wholeEnvironment
        ? tc('console.in_this_environment_d3511377')
        : tc('console.for_this_organization_a02ed7fb');

    return (
        <div className="space-y-6">
            <PageHeader
                help={help}
                description={tc('console.every_live_connection_value0_single_sign_on__867a6c7a', {
                    value0: scope,
                })}
                actions={
                    <Button asChild variant="primary" className="shrink-0">
                        <Link href={catalogHref}>
                            <Icon name="plus" className="w-4 h-4" />
                            {tc('console.new_connection_729bceee')}
                        </Link>
                    </Button>
                }
            />

            <LinkTabs
                label={tc('console.status_920e413c')}
                tabs={statuses.map((option) => ({
                    key: option.key === '' ? 'all' : option.key,
                    label: `${option.label} (${option.count.toLocaleString(locale)})`,
                    href: option.href,
                    current: option.selected,
                }))}
            />

            <div className="flex flex-wrap items-center gap-3">
                <div style={{ minWidth: '16rem' }}>
                    <Select
                        aria-label={tc('console.type_baaddf70')}
                        value={types.find((option) => option.selected)?.key || 'all'}
                        onValueChange={(key) => {
                            const chosen = types.find((option) => (option.key || 'all') === key);

                            if (chosen !== undefined) {
                                router.visit(chosen.href, { preserveScroll: true });
                            }
                        }}
                        options={types.map((option) => ({
                            value: option.key || 'all',
                            label: `${option.label} (${option.count.toLocaleString(locale)})`,
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
                {pagination.total}{' '}
                {pagination.total === 1
                    ? tc('console.connection_b38d9d16')
                    : tc('console.connections_1e5fac86')}
                {tc('console.shown_d2edca72')}
            </output>

            <div className="card overflow-hidden">
                {connections.length === 0 ? (
                    filtered ? (
                        <EmptyState
                            icon="filter"
                            title={tc('console.nothing_matches_these_filters_2999c712')}
                            description={tc(
                                'console.no_connection_of_that_type_and_status_clear__b3ef151a',
                            )}
                            actions={
                                <Button asChild>
                                    <Link href={clearHref}>
                                        {tc('console.clear_filters_7179ea00')}
                                    </Link>
                                </Button>
                            }
                        />
                    ) : (
                        <EmptyState
                            icon="connections"
                            title={tc('console.no_connections_yet_8697571e')}
                            help={help}
                            description={tc(
                                'console.nothing_is_connected_value0_yet_the_catalog__ca6154d3',
                                { value0: scope },
                            )}
                            steps={[
                                tc('console.pick_a_type_in_the_catalog_single_sign_on_di_9a947bbb'),
                                tc('console.its_setup_form_opens_with_the_provider_alrea_7b06e5df'),
                                tc('console.once_it_is_saved_it_is_listed_here_with_whet_5ceb7c7c'),
                            ]}
                            actions={
                                <Button asChild variant="primary">
                                    <Link href={catalogHref}>
                                        <Icon name="plus" className="w-4 h-4" />
                                        {tc('console.new_connection_729bceee')}
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
                noun={tc('console.connection_b38d9d16')}
                pluralNoun={tc('console.connections_1e5fac86')}
                href={(page) => listHref('', page)}
            />
        </div>
    );
}

function ConnectionLine({ row }: { row: ConnectionRow }) {
    const { locale } = useTranslator();

    const { t: tc } = useTranslator();

    const pill = statusPill(row, tc);

    const body = (
        <>
            <div className="min-w-0 flex-1 basis-64">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium [overflow-wrap:anywhere]">{row.name}</span>
                    <Badge>{row.type}</Badge>
                    <Badge>{row.organization ?? tc('console.whole_environment_fc5a0810')}</Badge>
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
                        tc('console.no_activity_recorded_fcfa3087')
                    ) : (
                        <time
                            dateTime={row.lastActivityAt}
                            title={absoluteTime(row.lastActivityAt, locale)}
                        >
                            {tc('console.last_activity_0c278ed1')}
                            {relativeTime(row.lastActivityAt, locale)}
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
