import { useTranslator as usePublicTranslator } from '@/i18n';
import { useTranslator as useFormatTranslator } from '@/i18n';
import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import { Button, EmptyState, Icon, PageHeader, Panel, Pill, Table, Td, TdMono, Th } from '@/ui';

interface PlaneRow {
    id: string;
    name: string;
    slug: string;
    organizations: number;
    users: number;
    sessions: number;
}

interface TopOrganization {
    id: string;
    name: string;
    plane: string;
    members: number;
    href: string;
}

type Props = PageProps<{
    totals: {
        environments: number;
        organizations: number;
        users: number;
        sessions: number;
        connections: number;
        domains: number;
        clients: number;
    };
    breakdown: PlaneRow[];
    topOrganizations: TopOrganization[];
    help: HelpContent;
}>;

export default function PlatformUsage({ totals, breakdown, topOrganizations, help }: Props) {
    const { t: tc } = usePublicTranslator();
    const { locale: consoleLocale } = useFormatTranslator();
    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const tiles: [string, number][] = [
        ['Environments', totals.environments],
        ['Organizations', totals.organizations],
        ['Users', totals.users],
        [tc('console.active_sessions_61ad9909'), totals.sessions],
        [tc('console.sso_connections_2e2e48f8'), totals.connections],
        [tc('console.verified_domains_b01c494f'), totals.domains],
        [tc('console.api_clients_35c69da7'), totals.clients],
    ];

    return (
        <>
            <PageHeader
                help={help}
                description={consoleT(
                    'console.platform_wide_usage_across_every_environment_not_only_t_0e12a08a',
                )}
            />

            <div className="grid gap-3 grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 mb-5 mt-8">
                {tiles.map(([label, value]) => (
                    <div key={label} className="cbx-stat">
                        <div className="min-w-0">
                            <p className="cbx-stat-value">{value.toLocaleString(consoleLocale)}</p>
                            <p className="cbx-stat-label">{label}</p>
                        </div>
                    </div>
                ))}
            </div>

            <div className="mb-5">
                <Panel
                    title={consoleT('console.per_environment_breakdown_8d6b222d')}
                    action={
                        <span className="text-xs" style={{ color: 'var(--faint)' }}>
                            {breakdown.length}{' '}
                            {breakdown.length === 1
                                ? consoleText('console.environment_ba528516')
                                : consoleText('console.environments_d5f6d542')}
                        </span>
                    }
                >
                    {breakdown.length === 0 ? (
                        <EmptyState
                            icon="layers"
                            title={consoleT('console.no_environments_provisioned_yet_3a208207')}
                            description={consoleT(
                                'console.create_one_on_platform_environments_and_its_counts_appe_2e1a2d8f',
                            )}
                        />
                    ) : (
                        <div className="overflow-x-auto">
                            <Table
                                caption={consoleUiText(
                                    'console.every_environment_on_this_install_and_how_much_of_the_e_1d09cf29',
                                )}
                            >
                                <thead>
                                    <tr>
                                        <Th>{consoleT('console.environment_9e471951')}</Th>
                                        <Th className="text-right">
                                            {consoleT('console.organizations_2730183d')}
                                        </Th>
                                        <Th className="text-right">
                                            {consoleT('console.users_6b0cc904')}
                                        </Th>
                                        <Th className="text-right">
                                            {consoleT('console.active_sessions_61ad9909')}
                                        </Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {breakdown.map((plane) => (
                                        <tr key={plane.id}>
                                            <Td>
                                                <p className="font-medium">{plane.name}</p>
                                                <p
                                                    className="text-xs mono"
                                                    style={{ color: 'var(--faint)' }}
                                                >
                                                    {plane.slug}
                                                </p>
                                            </Td>
                                            <Td className="text-right tabular-nums">
                                                {plane.organizations.toLocaleString(consoleLocale)}
                                            </Td>
                                            <Td className="text-right tabular-nums">
                                                {plane.users.toLocaleString(consoleLocale)}
                                            </Td>
                                            <Td className="text-right tabular-nums">
                                                {plane.sessions.toLocaleString(consoleLocale)}
                                            </Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        </div>
                    )}
                </Panel>
            </div>

            <Panel
                title={consoleT('console.top_organizations_by_members_cf67e5ff')}
                action={
                    <span className="text-xs" style={{ color: 'var(--faint)' }}>
                        {consoleT('console.across_every_environment_de59592c')}
                    </span>
                }
            >
                {topOrganizations.length === 0 ? (
                    <EmptyState
                        icon="members"
                        title={consoleT('console.no_organization_has_a_member_yet_78cd6728')}
                        description={consoleT(
                            'console.this_ranks_organizations_by_member_count_once_they_do_d333557e',
                        )}
                    />
                ) : (
                    <div className="overflow-x-auto">
                        <Table
                            caption={consoleUiText(
                                'console.the_ten_organizations_with_the_most_members_and_the_env_1c7ea5a5',
                            )}
                        >
                            <thead>
                                <tr>
                                    <Th>{consoleT('console.organization_d764d425')}</Th>
                                    <Th>{consoleT('console.environment_9e471951')}</Th>
                                    <Th className="text-right">
                                        {consoleT('console.members_1044a4c0')}
                                    </Th>
                                    <Th>
                                        <span className="sr-only">
                                            {consoleT('console.actions_ff8059dc')}
                                        </span>
                                    </Th>
                                </tr>
                            </thead>
                            <tbody>
                                {topOrganizations.map((organization) => (
                                    <tr key={organization.id}>
                                        <Td className="font-semibold">{organization.name}</Td>
                                        <Td>
                                            {/*
                                                The icon is decorative and the column is
                                                already headed "Environment", so the pill needs no
                                                title attribute to explain itself — one there
                                                would be a tooltip repeating the header.
                                            */}
                                            <Pill tone="info">
                                                <Icon
                                                    name="layers"
                                                    className="w-3 h-3"
                                                    aria-hidden="true"
                                                />
                                                {organization.plane}
                                            </Pill>
                                        </Td>
                                        <TdMono className="text-right tabular-nums">
                                            {organization.members.toLocaleString(consoleLocale)}
                                        </TdMono>
                                        <Td className="text-right whitespace-nowrap">
                                            {/*
                                                A PLANE SWITCH, not a link: this re-points the
                                                console at the tenant's own environment before
                                                opening its detail page, so it is a real
                                                navigation rather than a client-side visit.
                                            */}
                                            <Button asChild size="sm">
                                                <Link href={organization.href}>
                                                    {consoleT('console.view_dcc839a4')}
                                                </Link>
                                            </Button>
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </div>
                )}
            </Panel>
        </>
    );
}

PlatformUsage.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
