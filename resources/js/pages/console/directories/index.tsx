import { useTranslator } from '@/i18n';
import { Link, router, usePage } from '@inertiajs/react';
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
    CopyButton,
    EmptyState,
    FilterChips,
    Icon,
    Input,
    OrganizationFilterChip,
    PageHeader,
    Pagination,
    Panel,
    Pill,
} from '@/ui';

interface DirectoryRow {
    id: string;
    name: string;
    provider: string;
    pull: boolean;
    active: boolean;
    status: string;
    lastSyncError: string | null;
    owner: string;
    href: string;
}

type Props = PageProps<{
    help: HelpContent;
    directories: DirectoryRow[];
    pagination: PaginationState;
    search: string;
    mayAdminister: boolean;
    entitled: boolean;
    showsEveryOrganization: boolean;
    /** The environment-wide list's Organization chip; null on a page about one organization. */
    organizationFilter: OrganizationFilter | null;
    scimBaseUrl: string;
    createHref: string;
    /** A portal link is for ONE organization's IT admin: null where the page is about none. */
    inviteHref: string | null;
}>;

export default function DirectoriesIndex({
    help,
    directories,
    pagination,
    search,
    mayAdminister,
    entitled,
    showsEveryOrganization,
    organizationFilter,
    scimBaseUrl,
    createHref,
    inviteHref,
}: Props) {
    const { t: tc } = useTranslator();

    // The setup link is a credential in a URL, so it rides the flash channel and never
    // the props — props are written into the browser's history entry.
    const portalUrl = usePage().flash.portalUrl;

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

    const canAct = mayAdminister && entitled;

    return (
        <>
            <PageHeader
                help={help}
                description={tc('console.let_your_identity_provider_create_update_and_1208087e')}
                actions={
                    canAct ? (
                        <>
                            {inviteHref !== null && (
                                <Button
                                    icon="members"
                                    className="shrink-0"
                                    onClick={() =>
                                        router.post(inviteHref, {}, { preserveScroll: true })
                                    }
                                >
                                    {tc('console.invite_your_it_admin_3aba7671')}
                                </Button>
                            )}
                            <Button asChild variant="primary" className="shrink-0">
                                <Link href={createHref}>
                                    <Icon name="plus" className="w-4 h-4" />
                                    {tc('console.new_directory_000ddb55')}
                                </Link>
                            </Button>
                        </>
                    ) : undefined
                }
            />

            <div className="mt-8 space-y-6">
                {!entitled && (
                    <div className="card">
                        <EmptyState
                            icon="directory"
                            title={tc('console.syncing_users_in_is_an_enterprise_feature_c3b68cec')}
                            help={help}
                            description={tc(
                                'console.contact_your_account_team_to_enable_it_for_t_c4ba4d29',
                            )}
                        />
                    </div>
                )}

                {organizationFilter !== null && (
                    <FilterChips>
                        <OrganizationFilterChip filter={organizationFilter} />
                    </FilterChips>
                )}

                {portalUrl !== undefined && mayAdminister && (
                    <Panel
                        className="p-5"
                        style={{
                            borderColor: 'color-mix(in oklch, var(--accent) 40%, transparent)',
                        }}
                    >
                        <div className="flex items-center gap-2 font-semibold">
                            <Icon name="members" className="w-4 h-4" />
                            {tc('console.setup_link_for_your_it_admin_df0a3a89')}
                        </div>
                        <p className="mt-1 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                            {tc('console.send_this_single_use_link_to_whoever_configu_e8056999')}
                        </p>
                        <p
                            className="mt-3 mono text-xs rounded-lg px-3 py-2 select-all break-all"
                            style={{
                                background: 'var(--secondary)',
                                border: '1px solid var(--border)',
                            }}
                        >
                            {portalUrl}
                        </p>
                    </Panel>
                )}

                <div>
                    <Input
                        type="search"
                        style={{ maxWidth: '24rem' }}
                        placeholder={tc('console.search_by_name_4ae2b333')}
                        aria-label={tc('console.search_directories_72c57fbd')}
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
                            ? tc('console.directory_33317878')
                            : tc('console.directories_374cb37a')}{' '}
                        {tc('console.found_ddbb3871')}
                    </output>
                </div>

                <div
                    className="rounded-xl border overflow-hidden"
                    style={{ borderColor: 'var(--border)' }}
                >
                    {directories.length === 0 ? (
                        search !== '' ? (
                            <EmptyState
                                icon="search"
                                title={tc('console.no_matches_for_value0_617e79ef', {
                                    value0: search,
                                })}
                                description={tc(
                                    'console.no_directory_matches_that_name_try_a_differe_bfc6957c',
                                )}
                            />
                        ) : (
                            <EmptyState
                                icon="directory"
                                equivalent="directories.create"
                                title={tc('console.no_directory_connected_yet_d4e7a522')}
                                help={help}
                                description={tc(
                                    'console.today_somebody_adds_and_removes_people_here__01774e72',
                                )}
                                steps={[
                                    tc(
                                        'console.choose_scim_if_your_provider_pushes_to_us_go_99b715bb',
                                    ),
                                    tc(
                                        'console.for_scim_paste_the_endpoint_and_the_token_we_4dcda8c9',
                                    ),
                                    tc(
                                        'console.for_a_pull_directory_paste_its_credentials_a_581eae82',
                                    ),
                                    tc(
                                        'console.map_the_groups_you_sync_onto_roles_so_access_246e648d',
                                    ),
                                ]}
                                actions={
                                    canAct ? (
                                        <Button asChild variant="primary">
                                            <Link href={createHref}>
                                                <Icon name="plus" className="w-4 h-4" />
                                                {tc('console.new_directory_000ddb55')}
                                            </Link>
                                        </Button>
                                    ) : undefined
                                }
                            />
                        )
                    ) : (
                        directories.map((directory, index) => (
                            <Link
                                key={directory.id}
                                href={directory.href}
                                className="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)]"
                                style={
                                    index < directories.length - 1
                                        ? { borderBottom: '1px solid var(--border)' }
                                        : undefined
                                }
                            >
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2 flex-wrap">
                                        <span className="font-medium truncate">
                                            {directory.name}
                                        </span>
                                        {showsEveryOrganization && <Badge>{directory.owner}</Badge>}
                                    </div>
                                    {directory.lastSyncError !== null && (
                                        <p
                                            className="text-xs truncate"
                                            style={{ color: 'var(--destructive)' }}
                                        >
                                            {directory.lastSyncError}
                                        </p>
                                    )}
                                </div>
                                <Badge>{directory.provider}</Badge>
                                <Pill tone={directory.active ? 'success' : 'warning'}>
                                    {directory.active
                                        ? tc('console.active_92340695')
                                        : directory.status}
                                </Pill>
                                <Icon
                                    name="chevron"
                                    className="w-4 h-4 shrink-0"
                                    style={{
                                        color: 'var(--faint)',
                                        transform: 'rotate(-90deg)',
                                    }}
                                />
                            </Link>
                        ))
                    )}
                </div>

                <Pagination
                    pagination={pagination}
                    noun={tc('console.directory_33317878')}
                    pluralNoun={tc('console.directories_374cb37a')}
                    href={(page) => listHref(search, page)}
                />

                {/*
                    The endpoint a provider posts to. On the list rather than on one
                    directory's page because it is the same for every SCIM directory in the
                    environment — it is the platform's address, not a property of any row.
                */}
                {entitled && (
                    <Panel
                        title={tc('console.scim_endpoint_59109538')}
                        description={tc(
                            'console.paste_this_into_your_identity_provider_with__6f366a6e',
                        )}
                    >
                        <div className="flex items-center gap-2">
                            <code className="flex-1 min-w-0 truncate mono text-sm">
                                {scimBaseUrl}
                            </code>
                            <CopyButton value={scimBaseUrl} />
                        </div>
                    </Panel>
                )}
            </div>
        </>
    );
}

DirectoriesIndex.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
