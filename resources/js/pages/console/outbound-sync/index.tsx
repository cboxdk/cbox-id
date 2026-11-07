import { useTranslator as usePublicTranslator } from '@/i18n';
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
} from '@/ui';

interface ConnectionRow {
    id: string;
    name: string;
    baseUrl: string;
    /** The organization it provisions, or null when it covers the whole environment. */
    scope: string | null;
    active: boolean;
    lastError: string | null;
    href: string;
}

type Props = PageProps<{
    connections: ConnectionRow[];
    pagination: PaginationState;
    search: string;
    createHref: string;
    help: HelpContent;
    /** The environment-wide list's Organization chip; null where the list is one organization's already. */
    organizationFilter: OrganizationFilter | null;
}>;

export default function OutboundSyncIndex({
    connections,
    pagination,
    search,
    createHref,
    help,
    organizationFilter,
}: Props) {
    const { t: tc } = usePublicTranslator();
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
                    'console.push_your_people_into_the_apps_that_need_their_own_copy_249f03c2',
                )}
                actions={
                    <Button asChild variant="primary" className="shrink-0">
                        <Link href={createHref}>
                            {consoleRich('console._part1_new_connection_bce987f9', {
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
                    placeholder={consoleUiText('console.search_by_name_4ae2b333')}
                    aria-label={consoleT('console.search_outbound_connections_d6171f84')}
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
                {connections.length === 0 ? (
                    search !== '' ? (
                        <EmptyState
                            icon="search"
                            title={consoleFinalText('console.no_connections_match_part1_14089864', {
                                part1: search,
                            })}
                            description={consoleT(
                                'console.no_outbound_connection_matches_that_name_try_a_differen_2fb976c3',
                            )}
                        />
                    ) : (
                        <EmptyState
                            icon="directory"
                            equivalent="provisioning.targets.create"
                            title={consoleT('console.no_outbound_sync_yet_6f16faf1')}
                            description={consoleT(
                                'console.apps_that_keep_their_own_user_list_drift_out_of_step_th_fc8e8671',
                            )}
                            steps={[
                                consoleRuntimeText(
                                    'console.paste_the_app_s_scim_base_url_and_the_credential_it_iss_4908336e',
                                ),
                                consoleRuntimeText(
                                    'console.choose_how_it_authenticates_a_bearer_token_or_oauth_cli_a5be3b55',
                                ),
                                consoleRuntimeText(
                                    'console.save_it_joins_changes_and_departures_start_flowing_from_3ec32b01',
                                ),
                            ]}
                            actions={
                                <Button asChild variant="primary">
                                    <Link href={createHref}>
                                        {consoleRich('console._part1_new_connection_bce987f9', {
                                            part1: <Icon name="plus" className="w-4 h-4" />,
                                        })}
                                    </Link>
                                </Button>
                            }
                        />
                    )
                ) : (
                    connections.map((connection, index) => (
                        <Link
                            key={connection.id}
                            href={connection.href}
                            className="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)]"
                            style={
                                index < connections.length - 1
                                    ? { borderBottom: '1px solid var(--border)' }
                                    : undefined
                            }
                        >
                            <div className="min-w-0 flex-1">
                                <div className="flex items-center gap-2 flex-wrap">
                                    <span className="font-medium truncate">{connection.name}</span>
                                    <Badge>
                                        {connection.scope ??
                                            tc('console.environment_wide_cd1204b6')}
                                    </Badge>
                                </div>
                                {connection.lastError !== null ? (
                                    // A push that has started failing means people are
                                    // drifting out of step downstream — the one thing worth
                                    // seeing without opening the row.
                                    <p
                                        className="text-xs truncate"
                                        style={{ color: 'var(--destructive)' }}
                                    >
                                        {connection.lastError}
                                    </p>
                                ) : (
                                    <p
                                        className="text-xs truncate mono"
                                        style={{ color: 'var(--faint)' }}
                                    >
                                        {connection.baseUrl}
                                    </p>
                                )}
                            </div>

                            <Pill tone={connection.active ? 'success' : 'warning'}>
                                {connection.active
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

            <Pagination
                pagination={pagination}
                href={(page) => listHref(search, page)}
            />
        </>
    );
}

OutboundSyncIndex.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
