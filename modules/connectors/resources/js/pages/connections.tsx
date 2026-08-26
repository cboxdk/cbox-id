import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { EmptyState, PageHeader, Pill, type PillTone, Table, Td, Th } from '@/ui';

interface ConnectionRow {
    category: string;
    name: string;
    status: string;
    target: string | null;
    health: string | null;
}

type Props = PageProps<{
    connections: ConnectionRow[];
    /** True when an environment administrator has not chosen an organization to act on. */
    wholeEnvironment: boolean;
}>;

function healthTone(health: string): PillTone {
    if (health === 'healthy') {
        return 'success';
    }

    return health === 'degraded' ? 'warning' : 'neutral';
}

export default function Connections({ connections, wholeEnvironment }: Props) {
    // The copy moves WITH the scoping. A page that says "for this organization" while
    // listing the whole environment teaches a reader to distrust scoping they cannot see.
    const scope = wholeEnvironment ? 'in this environment' : 'for this organization';

    return (
        <div className="space-y-6">
            <PageHeader
                description={`Every live connector ${scope}, across outbound SCIM, webhooks and SSO federation.`}
            />

            {connections.length === 0 ? (
                <EmptyState
                    icon="connections"
                    title="No connectors yet"
                    description={`No connectors are configured ${scope} yet.`}
                />
            ) : (
                <div className="card overflow-hidden">
                    <div className="overflow-x-auto">
                        <Table
                            caption={`Every live connector ${scope}, with its target and health`}
                        >
                            <thead>
                                <tr>
                                    <Th>Type</Th>
                                    <Th>Name</Th>
                                    <Th>Target</Th>
                                    <Th>Status</Th>
                                    <Th>Health</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {connections.map((row) => (
                                    <tr key={`${row.category}-${row.name}`}>
                                        <Td
                                            className="whitespace-nowrap"
                                            style={{ color: 'var(--muted-foreground)' }}
                                        >
                                            {row.category}
                                        </Td>
                                        <Td className="font-medium">{row.name}</Td>
                                        <Td style={{ color: 'var(--muted-foreground)' }}>
                                            {row.target ?? '—'}
                                        </Td>
                                        <Td className="whitespace-nowrap">
                                            <Pill
                                                tone={
                                                    row.status === 'active' ? 'success' : 'neutral'
                                                }
                                                className="capitalize"
                                            >
                                                {row.status}
                                            </Pill>
                                        </Td>
                                        <Td className="whitespace-nowrap">
                                            {row.health === null ? (
                                                <span
                                                    className="text-xs"
                                                    style={{ color: 'var(--faint)' }}
                                                >
                                                    —
                                                </span>
                                            ) : (
                                                <Pill
                                                    tone={healthTone(row.health)}
                                                    className="capitalize"
                                                >
                                                    {row.health}
                                                </Pill>
                                            )}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </div>
                </div>
            )}
        </div>
    );
}

Connections.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
