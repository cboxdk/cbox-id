import { Link } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
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
}>;

export default function PlatformUsage({ totals, breakdown, topOrganizations }: Props) {
    const tiles: [string, number][] = [
        ['Environments', totals.environments],
        ['Organizations', totals.organizations],
        ['Users', totals.users],
        ['Active sessions', totals.sessions],
        ['SSO connections', totals.connections],
        ['Verified domains', totals.domains],
        ['API clients', totals.clients],
    ];

    return (
        <>
            <PageHeader description="Platform-wide usage across every environment — above the plane the console is currently pinned to." />

            <div className="grid gap-3 grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 mb-5 mt-8">
                {tiles.map(([label, value]) => (
                    <div key={label} className="cbx-stat">
                        <div className="min-w-0">
                            <p className="cbx-stat-value">{value.toLocaleString()}</p>
                            <p className="cbx-stat-label">{label}</p>
                        </div>
                    </div>
                ))}
            </div>

            <div className="mb-5">
                <Panel
                    title="Per-environment breakdown"
                    action={
                        <span className="text-xs" style={{ color: 'var(--faint)' }}>
                            {breakdown.length} {breakdown.length === 1 ? 'plane' : 'planes'}
                        </span>
                    }
                >
                    {breakdown.length === 0 ? (
                        <EmptyState
                            icon="layers"
                            title="No environments provisioned yet"
                            description="Create one on Platform › Environments and its counts appear here."
                        />
                    ) : (
                        <div className="overflow-x-auto">
                            <Table caption="Every environment on this install, and how much of the estate is in each">
                                <thead>
                                    <tr>
                                        <Th>Environment</Th>
                                        <Th className="text-right">Organizations</Th>
                                        <Th className="text-right">Users</Th>
                                        <Th className="text-right">Active sessions</Th>
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
                                                {plane.organizations.toLocaleString()}
                                            </Td>
                                            <Td className="text-right tabular-nums">
                                                {plane.users.toLocaleString()}
                                            </Td>
                                            <Td className="text-right tabular-nums">
                                                {plane.sessions.toLocaleString()}
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
                title="Top organizations by members"
                action={
                    <span className="text-xs" style={{ color: 'var(--faint)' }}>
                        Across every plane
                    </span>
                }
            >
                {topOrganizations.length === 0 ? (
                    <EmptyState
                        icon="members"
                        title="No organization has a member yet"
                        description="This ranks tenants by member count once they do."
                    />
                ) : (
                    <div className="overflow-x-auto">
                        <Table caption="The ten organizations with the most members, and the plane each belongs to">
                            <thead>
                                <tr>
                                    <Th>Organization</Th>
                                    <Th>Plane</Th>
                                    <Th className="text-right">Members</Th>
                                    <Th>
                                        <span className="sr-only">Actions</span>
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
                                                already headed "Plane", so the pill needs no
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
                                            {organization.members.toLocaleString()}
                                        </TdMono>
                                        <Td className="text-right whitespace-nowrap">
                                            {/*
                                                A PLANE SWITCH, not a link: this re-points the
                                                console at the tenant's own environment before
                                                opening its detail page, so it is a real
                                                navigation rather than a client-side visit.
                                            */}
                                            <Button asChild size="sm">
                                                <Link href={organization.href}>View</Link>
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
