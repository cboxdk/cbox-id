import { useTranslator } from '@/i18n';
import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import { listHref } from '@/lib/listHref';
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
    Input,
    OrganizationFilterChip,
    PageHeader,
    Pagination,
    Pill,
    Tooltip,
} from '@/ui';

interface HookRow {
    id: string;
    url: string;
    point: string;
    pointDescription: string;
    /** The organization it belongs to, or null when the environment owns it. */
    owner: string | null;
    active: boolean;
    href: string;
}

type Props = PageProps<{
    help: HelpContent;
    hooks: HookRow[];
    pagination: PaginationState;
    search: string;
    createHref: string;
    /** The environment-wide list's Organization chip; null where the list is one organization's already. */
    organizationFilter: OrganizationFilter | null;
}>;

export default function HooksIndex({
    help,
    hooks,
    pagination,
    search,
    createHref,
    organizationFilter,
}: Props) {
    const { t: tc } = useTranslator();

    const [term, setTerm] = useState(search);

    useEffect(() => {
        if (term === search) {
            return;
        }

        const timer = setTimeout(() => {
            router.get(
                listHref(term),
                {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);

        return () => clearTimeout(timer);
    }, [term, search]);

    return (
        <>
            <PageHeader
                help={help}
                description={tc('console.your_endpoint_is_called_in_the_middle_of_an__ab67a6b0')}
                actions={
                    <Button asChild variant="primary" className="shrink-0">
                        <Link href={createHref}>
                            <Icon name="plus" className="w-4 h-4" />
                            {tc('console.register_endpoint_1e7b84db')}
                        </Link>
                    </Button>
                }
            />

            <div className="mt-6 space-y-3">
                {organizationFilter !== null && (
                    <FilterChips>
                        <OrganizationFilterChip filter={organizationFilter} />
                    </FilterChips>
                )}
                <Input
                    type="search"
                    style={{ maxWidth: '24rem' }}
                    placeholder={tc('console.search_by_url_98cb36da')}
                    aria-label={tc('console.search_hooks_1f85b0cc')}
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                />
                {/*
                    SC 4.1.3: the list is replaced on a debounced keystroke with no focus
                    change, so the count is the only thing that can report the filter
                    narrowed to nothing.
                */}
                <output className="sr-only">
                    {pagination.total}{' '}
                    {pagination.total === 1
                        ? tc('console.hook_0648298b')
                        : tc('console.hooks_a9fa9fda')}
                    {tc('console.found_599e25a3')}
                </output>
            </div>

            <div
                className="mt-4 rounded-xl border overflow-hidden"
                style={{ borderColor: 'var(--border)' }}
            >
                {hooks.length === 0 ? (
                    search !== '' ? (
                        <EmptyState
                            icon="search"
                            title={tc('console.no_hooks_match_value0_6f2388b4', { value0: search })}
                            description={tc(
                                'console.no_endpoint_url_matches_that_search_try_a_di_45378b36',
                            )}
                        />
                    ) : (
                        <EmptyState
                            icon="webhooks"
                            equivalent="hooks.create"
                            title={tc('console.no_hooks_registered_2469ba6e')}
                            help={help}
                            description={tc(
                                'console.most_integrations_want_webhooks_instead_thos_576be07b',
                            )}
                            steps={[
                                tc('console.register_the_endpoint_and_choose_the_hook_po_bd5f70f2'),
                                tc('console.verify_the_signature_on_every_call_and_answe_5219ed81'),
                                tc('console.fail_open_or_closed_deliberately_decide_now__daf1e068'),
                            ]}
                            actions={
                                <Button asChild variant="primary">
                                    <Link href={createHref}>
                                        <Icon name="plus" className="w-4 h-4" />
                                        {tc('console.register_endpoint_1e7b84db')}
                                    </Link>
                                </Button>
                            }
                        />
                    )
                ) : (
                    hooks.map((hook, index) => (
                        <Link
                            key={hook.id}
                            href={hook.href}
                            className="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)]"
                            style={
                                index < hooks.length - 1
                                    ? { borderBottom: '1px solid var(--border)' }
                                    : undefined
                            }
                        >
                            <div className="min-w-0 flex-1">
                                <div className="flex items-center gap-2 flex-wrap">
                                    {/*
                                        The hook point NAMES the moment your endpoint is
                                        called, and the whole page turns on it — so what it
                                        means is reachable here rather than only on the
                                        detail page.
                                    */}
                                    <Tooltip content={hook.pointDescription}>
                                        <span>
                                            <Badge>{hook.point}</Badge>
                                        </span>
                                    </Tooltip>
                                    <Badge>
                                        {hook.owner ?? tc('console.all_organizations_e3002b23')}
                                    </Badge>
                                </div>
                                <p
                                    className="mt-1 text-xs truncate mono"
                                    style={{ color: 'var(--faint)' }}
                                >
                                    {hook.url}
                                </p>
                            </div>

                            <Pill tone={hook.active ? 'success' : 'warning'}>
                                {hook.active
                                    ? tc('console.active_92340695')
                                    : tc('console.paused_e159b061')}
                            </Pill>

                            <Icon
                                name="chevron"
                                className="w-4 h-4 shrink-0"
                                style={{ color: 'var(--faint)', transform: 'rotate(-90deg)' }}
                            />
                        </Link>
                    ))
                )}
            </div>

            <Pagination
                pagination={pagination}
                noun={tc('console.hook_0648298b')}
                pluralNoun={tc('console.hooks_a9fa9fda')}
                href={(page) => listHref(search, page)}
            />
        </>
    );
}

HooksIndex.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
