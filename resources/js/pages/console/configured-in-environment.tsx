import { useTranslator } from '@/i18n';
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
    const { t: tc } = useTranslator();

    const { shell } = usePage<SharedProps>().props;
    const projects = (shell?.context.projects ?? []).filter((project) => project.id !== 'platform');

    return (
        <div style={{ maxWidth: '40rem' }}>
            <PageHeader
                eyebrow={null}
                description={
                    <>
                        {tc('console.this_console_is_your_workspace_its_projects__6f25b5a6')}
                        {label.toLowerCase()}
                        {tc('console.included_is_set_for_each_environment_in_that_65e9f6b5')}
                    </>
                }
            />

            <div className="mt-6 space-y-4">
                <Panel
                    title={tc('console.open_value0_in_an_environment_c5a3bb6a', {
                        value0: label.toLowerCase(),
                    })}
                >
                    {projects.length === 0 ? (
                        <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                            {tc('console.you_have_no_environment_to_open_yet_204552fe')}{' '}
                            <a href={projectsHref} className="underline underline-offset-2">
                                {tc('console.go_to_projects_cad12684')}
                            </a>{' '}
                            {tc('console.to_create_one_or_ask_a_workspace_owner_for_a_218b736c')}
                        </p>
                    ) : (
                        <ul className="space-y-2" aria-label={tc('console.environments_07437cd6')}>
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
                            {tc('console.each_link_opens_c21aaa7e')}
                            {label}
                            {tc('console.in_that_environment_s_console_9b410cb2')}
                        </p>
                    )}
                </Panel>

                {platformHref !== null && (
                    <Panel title={tc('console.the_platform_s_own_sign_in_1b8b2a80')}>
                        <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                            {tc('console.how_workspace_owners_and_operators_sign_in_t_293f421d')}
                        </p>
                        <a
                            href={platformHref}
                            className="mt-2 inline-flex items-center gap-2 text-sm hover:underline"
                        >
                            <Icon name="lock" className="w-4 h-4" />
                            {tc('console.open_the_platform_environment_d2be2ac0')}
                        </a>
                    </Panel>
                )}
            </div>
        </div>
    );
}

ConfiguredInEnvironment.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
