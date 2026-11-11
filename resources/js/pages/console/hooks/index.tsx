import { useTranslator as useConsoleTranslator } from '@/i18n';
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
    const { t: consoleRuntimeText } = useConsoleTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
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
                description={consoleT(
                    'console.your_endpoint_is_called_in_the_middle_of_an_operation_a_ab67a6b0',
                )}
                actions={
                    <Button asChild variant="primary" className="shrink-0">
                        <Link href={createHref}>
                            {consoleRich('console._part1_register_endpoint_a3b45cbc', {
                                part1: <Icon name="plus" className="w-4 h-4" />,
                            })}
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
                    placeholder={consoleUiText('console.search_by_url_98cb36da')}
                    aria-label={consoleT('console.search_hooks_1f85b0cc')}
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                />
                {/*
                    SC 4.1.3: the list is replaced on a debounced keystroke with no focus
                    change, so the count is the only thing that can report the filter
                    narrowed to nothing.
                */}
                <output className="sr-only">
                    {consoleRich('console.search_results_found', { count: pagination.total })}
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
                            title={consoleFinalText('console.no_hooks_match_part1_85b93364', {
                                part1: search,
                            })}
                            description={consoleT(
                                'console.no_endpoint_url_matches_that_search_try_a_different_one_45378b36',
                            )}
                        />
                    ) : (
                        <EmptyState
                            icon="webhooks"
                            equivalent="hooks.create"
                            title={consoleT('console.no_hooks_registered_2469ba6e')}
                            help={help}
                            description={consoleT(
                                'console.most_integrations_want_webhooks_instead_those_run_after_576be07b',
                            )}
                            steps={[
                                consoleRuntimeText(
                                    'console.register_the_endpoint_and_choose_the_hook_point_it_answ_bd5f70f2',
                                ),
                                consoleRuntimeText(
                                    'console.verify_the_signature_on_every_call_and_answer_within_th_5219ed81',
                                ),
                                consoleRuntimeText(
                                    'console.fail_open_or_closed_deliberately_decide_now_what_should_daf1e068',
                                ),
                            ]}
                            actions={
                                <Button asChild variant="primary">
                                    <Link href={createHref}>
                                        {consoleRich('console._part1_register_endpoint_a3b45cbc', {
                                            part1: <Icon name="plus" className="w-4 h-4" />,
                                        })}
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
                                        {hook.owner ??
                                            consoleRuntimeText(
                                                'console.all_organizations_e3002b23',
                                            )}
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
                                    ? consoleText('console.active_92340695')
                                    : consoleText('console.paused_e159b061')}
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

            <Pagination pagination={pagination} href={(page) => listHref(search, page)} />
        </>
    );
}

HooksIndex.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
