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
} from '@/ui';

interface AppRow {
    id: string;
    name: string;
    clientId: string;
    firstParty: boolean;
    platformOwned: boolean;
    roleCount: number;
    kindLabel: string;
    owner: string | null;
    href: string;
}

type Props = PageProps<{
    help: HelpContent;
    clients: AppRow[];
    pagination: PaginationState;
    platformApps: AppRow[];
    search: string;
    /** No organization is chosen: every app in the environment is in view. */
    showsEveryOrganization: boolean;
    mayAdminister: boolean;
    createHref: string;
    /** The environment-wide list's Organization chip; null where the list is one organization's already. */
    organizationFilter: OrganizationFilter | null;
}>;

export default function Clients({
    help,
    clients,
    pagination,
    platformApps,
    search,
    showsEveryOrganization,
    mayAdminister,
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

    // Debounced, and back to page one: a filter applied while on page four otherwise asks
    // for a page of a list that no longer has one.
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
                    'console.every_app_that_signs_people_in_through_cbox_id_or_calls_bf1c3a89',
                )}
                actions={
                    mayAdminister ? (
                        <Button asChild variant="primary" className="shrink-0">
                            <Link href={createHref}>
                                {consoleRich('console._part1_new_app_f8e9ca57', {
                                    part1: <Icon name="plus" className="w-4 h-4" />,
                                })}
                            </Link>
                        </Button>
                    ) : undefined
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
                    placeholder={consoleUiText('console.search_by_name_or_client_id_4231796c')}
                    aria-label={consoleT('console.search_apps_a10a36fa')}
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
                {clients.length === 0 ? (
                    search !== '' ? (
                        <EmptyState
                            icon="clients"
                            title={consoleT('console.no_matching_apps_798636bc')}
                            description={consoleFinalText(
                                'console.no_app_matches_part1_try_a_different_name_or_client_id_e5aa7b40',
                                { part1: search },
                            )}
                        />
                    ) : (
                        <EmptyState
                            icon="clients"
                            equivalent="apps.create"
                            actions={
                                mayAdminister ? (
                                    <Button asChild variant="primary">
                                        <Link href={createHref}>
                                            {consoleRich('console._part1_new_app_f8e9ca57', {
                                                part1: <Icon name="plus" className="w-4 h-4" />,
                                            })}
                                        </Link>
                                    </Button>
                                ) : undefined
                            }
                            title={consoleT('console.no_apps_of_your_own_yet_3fdb87bf')}
                            help={help}
                            description={
                                consoleRuntimeText(
                                    'console.an_app_registered_here_can_sign_your_people_in_and_read_f35bdb6c',
                                ) +
                                (!showsEveryOrganization && platformApps.length > 0
                                    ? consoleText(
                                          'console._the_platform_apps_below_belong_to_this_environment_rat_9f21136f',
                                      )
                                    : '')
                            }
                            steps={[
                                consoleRuntimeText(
                                    'console.register_the_app_one_per_app_per_environment_bc8f1427',
                                ),
                                consoleRuntimeText(
                                    'console.copy_its_client_id_and_secret_into_the_app_s_configurat_1948de40',
                                ),
                                consoleRuntimeText(
                                    'console.add_the_exact_url_cbox_id_may_send_people_back_to_after_dfcd0f5e',
                                ),
                                consoleRuntimeText(
                                    'console.have_the_app_declare_the_roles_it_understands_so_you_ca_9074afe0',
                                ),
                            ]}
                        />
                    )
                ) : (
                    clients.map((client, index) => (
                        <AppLink
                            key={client.id}
                            app={client}
                            last={index === clients.length - 1}
                            showOwner={showsEveryOrganization}
                        />
                    ))
                )}
            </div>

            <div className="mt-4">
                <Pagination pagination={pagination} href={(page) => listHref(search, page)} />
            </div>

            {/*
                Apps the ENVIRONMENT owns rather than the organization being administered:
                not registered here, but a first-party one can skip this organization's
                consent screen — so the page shows those integrations rather than
                looking like it lost an app.
            */}
            {!showsEveryOrganization && platformApps.length > 0 && (
                <section className="mt-8">
                    <h2
                        className="text-xs font-medium uppercase mb-3"
                        style={{ color: 'var(--muted-foreground)', letterSpacing: '0.06em' }}
                    >
                        {consoleRich('console.platform_apps_part1_part2_24834281', {
                            part1: ' ',
                            part2: (
                                <span style={{ textTransform: 'none', fontWeight: 400 }}>
                                    {consoleT(
                                        'console._owned_by_this_environment_not_by_this_organization_fir_33b76fa6',
                                    )}
                                </span>
                            ),
                        })}
                    </h2>
                    <div
                        className="rounded-xl border overflow-hidden"
                        style={{ borderColor: 'var(--border)' }}
                    >
                        {platformApps.map((client, index) => (
                            <AppLink
                                key={client.id}
                                app={client}
                                last={index === platformApps.length - 1}
                                showOwner={false}
                                platformOwned
                            />
                        ))}
                    </div>
                </section>
            )}
        </>
    );
}

function AppLink({
    app,
    last,
    showOwner,
    platformOwned = false,
}: {
    app: AppRow;
    last: boolean;
    showOwner: boolean;
    platformOwned?: boolean;
}) {
    const { t: consoleText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    return (
        <Link
            href={app.href}
            className="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)]"
            style={last ? undefined : { borderBottom: '1px solid var(--border)' }}
        >
            <div className="min-w-0 flex-1">
                <span className="font-medium truncate">{app.name}</span>
                <p className="text-sm truncate mono" style={{ color: 'var(--muted-foreground)' }}>
                    {app.clientId}
                </p>
            </div>

            {platformOwned && <Badge>{consoleT('console.platform_owned_c601b99a')}</Badge>}
            {showOwner && app.owner !== null && <Badge>{app.owner}</Badge>}
            {app.roleCount > 0 && (
                <Badge>
                    {app.roleCount}{' '}
                    {app.roleCount === 1
                        ? consoleText('console.role_4b168d88')
                        : consoleText('console.roles_66cf5513')}
                </Badge>
            )}
            {app.firstParty && !platformOwned && (
                <span
                    className="text-xs rounded-full px-2 py-0.5"
                    style={{ background: 'var(--accent-soft)', color: 'var(--accent-strong)' }}
                >
                    {consoleT('console.first_party_ee374836')}
                </span>
            )}
            <Badge>{app.kindLabel}</Badge>

            <Icon
                name="chevron"
                className="w-4 h-4 shrink-0"
                style={{ color: 'var(--faint)', transform: 'rotate(-90deg)' }}
            />
        </Link>
    );
}

Clients.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
