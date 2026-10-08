import { Link, router } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import { Button, Icon, type IconName, PageHeader, Stat } from '@/ui';
import { type ChecklistProps, EnvironmentChecklist } from './checklist';

type Props = PageProps<{
    stats: { label: string; icon: IconName; count: number; href: string }[];
    quickActions: { label: string; href: string }[];
    help: HelpContent;
    /** "Get started", until it is done or put away. */
    checklist: (ChecklistProps & { href: string; dismissHref: string }) | null;
}>;

export default function EnvironmentHome({ stats, quickActions, help, checklist }: Props) {
    return (
        <>
            <PageHeader
                help={help}
                description="Everything in this environment — organizations, users, and sign-in."
            />

            {checklist !== null && (
                <div className="mt-6">
                    <EnvironmentChecklist
                        checklist={checklist}
                        compact
                        href={checklist.href}
                        onDismiss={() =>
                            router.post(checklist.dismissHref, {}, { preserveScroll: true })
                        }
                    />
                </div>
            )}

            <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {stats.map((stat) => (
                    <Stat
                        key={stat.label}
                        icon={stat.icon}
                        label={stat.label}
                        // Grouped, because five figures and six are the same width at a
                        // glance and this page exists to be read at a glance.
                        value={stat.count.toLocaleString()}
                        href={stat.href}
                    />
                ))}
            </div>

            <div className="mt-8">
                <h2
                    className="text-xs font-semibold uppercase tracking-wide"
                    style={{ color: 'var(--faint)' }}
                >
                    Quick actions
                </h2>
                <div className="mt-3 flex flex-wrap gap-2">
                    {quickActions.map((action) => (
                        <Button key={action.href} asChild size="sm">
                            <Link href={action.href}>
                                <Icon name="plus" className="w-4 h-4" />
                                {action.label}
                            </Link>
                        </Button>
                    ))}
                </div>
            </div>
        </>
    );
}

EnvironmentHome.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
