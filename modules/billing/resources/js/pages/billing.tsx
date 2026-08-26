import { Link } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { EmptyState, PageHeader, Panel, Stat } from '@/ui';

interface ProjectRow {
    id: string;
    name: string;
    used: number;
    limit: number;
    href: string;
}

type Props = PageProps<{
    projects: ProjectRow[];
    usage: {
        organizations: number;
        connections: number;
        signIns: number;
    };
}>;

export default function Billing({ projects, usage }: Props) {
    return (
        <>
            <PageHeader description="Plans are per project; usage rolls up across every environment this organization owns." />

            {/* The billing anchor is the PROJECT — one customer can own several products. */}
            <Panel title="Projects" flush className="mt-6">
                {projects.length === 0 ? (
                    <EmptyState
                        icon="layers"
                        title="No projects yet"
                        description="Each project you create appears here with its plan and environment allowance."
                    />
                ) : (
                    projects.map((project, index) => (
                        <Link
                            key={project.id}
                            href={project.href}
                            className="flex items-center justify-between gap-4 p-4 transition-colors hover:bg-[var(--surface-2)]"
                            style={
                                index < projects.length - 1
                                    ? { borderBottom: '1px solid var(--border)' }
                                    : undefined
                            }
                        >
                            <div className="min-w-0">
                                <p className="font-medium truncate">{project.name}</p>
                                <p
                                    className="text-xs"
                                    style={{ color: 'var(--muted-foreground)' }}
                                >
                                    Early access — free
                                </p>
                            </div>
                            <span
                                className="text-sm tabular-nums shrink-0"
                                style={{ color: 'var(--muted)' }}
                            >
                                {project.used} of {project.limit}{' '}
                                {project.limit === 1 ? 'environment' : 'environments'}
                            </span>
                        </Link>
                    ))
                )}
            </Panel>

            {/* Live usage — the figures enterprise billing is based on, across all projects. */}
            <div className="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-3">
                <Stat
                    label="Organizations — tenants"
                    value={usage.organizations.toLocaleString()}
                />
                <Stat
                    label="SSO connections — billed"
                    value={usage.connections.toLocaleString()}
                />
                <Stat label="Sign-ins — this month" value={usage.signIns.toLocaleString()} />
            </div>

            <Panel title="How pricing works" className="mt-4">
                <p className="text-sm" style={{ color: 'var(--muted)' }}>
                    Each project is billed on its own plan — usage-based on monthly active users and
                    enterprise connections (SSO &amp; SCIM) across that project&rsquo;s environments.
                    Sandbox environments carry no connection charge. Per-project billing arrives with
                    general availability; every project is free during early access.
                </p>
            </Panel>
        </>
    );
}

Billing.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
