import Payments, { type PaymentsConfig, type PaymentProject } from '../components/Payments';
import { useTranslator } from '@/i18n';
import { Link } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import { EmptyState, PageHeader, Panel, Stat } from '@/ui';

interface ProjectRow extends PaymentProject {
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
    help: HelpContent;
    payments: PaymentsConfig;
}>;

export default function Billing({ projects, usage, help, payments }: Props) {
    const { locale } = useTranslator();

    const { t: tc } = useTranslator();

    return (
        <>
            <PageHeader
                help={help}
                description={tc('console.plans_are_per_project_usage_rolls_up_across__1da55f6c')}
            />

            {/* The billing anchor is the PROJECT — one customer can own several products. */}
            <Panel title={tc('console.projects_04e2a972')} flush className="mt-6">
                {projects.length === 0 ? (
                    <EmptyState
                        icon="layers"
                        title={tc('console.no_projects_yet_f83c8065')}
                        description={tc(
                            'console.each_project_you_create_appears_here_with_it_43199044',
                        )}
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
                                <p className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                                    {project.subscription?.plan ?? payments.labels.no_subscription}
                                </p>
                            </div>
                            <span
                                className="text-sm tabular-nums shrink-0"
                                style={{ color: 'var(--muted)' }}
                            >
                                {project.used}
                                {tc('console.of_a4282e4b')}
                                {project.limit}{' '}
                                {project.limit === 1
                                    ? tc('console.environment_ba528516')
                                    : tc('console.environments_d5f6d542')}
                            </span>
                        </Link>
                    ))
                )}
            </Panel>

            <Payments
                key={payments.organizationId}
                config={payments}
                projects={projects}
                locale={locale}
            />

            {/* Live usage — the figures enterprise billing is based on, across all projects. */}
            <div className="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-3">
                <Stat
                    label={tc('console.organizations_across_your_environments_0f0a8102')}
                    value={usage.organizations.toLocaleString(locale)}
                />
                <Stat
                    label={payments.labels.connections}
                    value={usage.connections.toLocaleString(locale)}
                />
                <Stat
                    label={tc('console.sign_ins_this_month_b8097e2d')}
                    value={usage.signIns.toLocaleString(locale)}
                />
            </div>

            <Panel title={tc('console.how_pricing_works_3090dcf5')} className="mt-4">
                <p className="text-sm" style={{ color: 'var(--muted)' }}>
                    {payments.labels.explanation}
                </p>
            </Panel>
        </>
    );
}

Billing.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
