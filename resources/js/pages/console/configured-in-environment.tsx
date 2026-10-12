import { usePage } from '@inertiajs/react';
import { EnvBadge } from '@/chrome/EnvBadge';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps, SharedProps } from '@/types';
import { Icon, PageHeader, Panel } from '@/ui';

type Props = PageProps<{
    /** The rail's word for what was asked for — "Social login". */
    label: string;
    /** Whether each environment link opens this same page there, rather than its front page. */
    samePage: boolean;
    projectsHref: string;
    /** The platform's own environment console, for an operator. */
    platformHref: string | null;
}>;

/**
 * "SOCIAL LOGIN IS SET IN AN ENVIRONMENT'S CONSOLE" — what the platform root's host answers
 * at a URL like `cboxid.com/social-sign-in`, which used to configure the workspace's own
 * record in Cbox's environment while looking like the platform's sign-in.
 *
 * Says what the console on this host is (the workspace), where the thing they came for
 * lives (each environment's console), and offers the same page in every environment they
 * may open — the context switcher's own rows, each of which already carries the page to
 * land on.
 */
export default function ConfiguredInEnvironment({
    label,
    samePage,
    projectsHref,
    platformHref,
}: Props) {
    const { shell } = usePage<SharedProps>().props;
    const projects = (shell?.context.projects ?? []).filter((project) => project.id !== 'platform');

    return (
        <div style={{ maxWidth: '40rem' }}>
            <PageHeader
                eyebrow={null}
                description={
                    <>
                        This console is your workspace: its projects, team, keys and billing. How
                        people sign in to your apps — {label.toLowerCase()} included — is set for
                        each environment, in that environment’s console.
                    </>
                }
            />

            <div className="mt-6 space-y-4">
                <Panel title={`Open ${label.toLowerCase()} in an environment`}>
                    {projects.length === 0 ? (
                        <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                            You have no environment to open yet.{' '}
                            <a href={projectsHref} className="underline underline-offset-2">
                                Go to Projects
                            </a>{' '}
                            to create one, or ask a workspace owner for access.
                        </p>
                    ) : (
                        <ul className="space-y-2" aria-label="Environments">
                            {projects.flatMap((project) =>
                                project.environments.map((environment) => (
                                    <li key={environment.id}>
                                        <a
                                            href={environment.href}
                                            className="flex items-center gap-2 text-sm hover:underline"
                                        >
                                            <Icon name="layers" className="w-4 h-4 shrink-0" />
                                            <span className="min-w-0 flex-1">
                                                {project.name} / {environment.name}
                                            </span>
                                            <EnvBadge type={environment.type} />
                                            <Icon
                                                name="external"
                                                className="w-3.5 h-3.5 shrink-0"
                                            />
                                        </a>
                                    </li>
                                )),
                            )}
                        </ul>
                    )}
                    {samePage && projects.length > 0 && (
                        <p className="mt-3 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                            Each link opens {label} in that environment’s console.
                        </p>
                    )}
                </Panel>

                {platformHref !== null && (
                    <Panel title="The platform’s own sign-in">
                        <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                            How workspace owners and operators sign in to Cbox ID is set in the
                            platform environment’s console.
                        </p>
                        <a
                            href={platformHref}
                            className="mt-2 inline-flex items-center gap-2 text-sm hover:underline"
                        >
                            <Icon name="lock" className="w-4 h-4" />
                            Open the platform environment
                        </a>
                    </Panel>
                )}
            </div>
        </div>
    );
}

ConfiguredInEnvironment.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
