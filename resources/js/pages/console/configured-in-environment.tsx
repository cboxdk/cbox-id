import { useTranslator as useConsoleTranslator } from '@/i18n';
import { EnvBadge } from '@/chrome/EnvBadge';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { ContextProject, PageProps } from '@/types';
import { Icon, PageHeader, Panel } from '@/ui';

type Props = PageProps<{
    /** The rail's word for what was asked for — "Social login". */
    label: string;
    /** Whether each environment link opens this same page there, rather than its front page. */
    samePage: boolean;
    projectsHref: string | null;
    consoleHref: string;
    /** The platform's own environment console, for an operator. */
    platformHref: string | null;
    /** Every environment this person may open, grouped by project; each link opens this page there. */
    projects: ContextProject[];
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
    consoleHref,
    platformHref,
    projects,
}: Props) {
    const { t: consoleFinalText } = useConsoleTranslator();

    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();

    return (
        <div style={{ maxWidth: '40rem' }}>
            <PageHeader
                eyebrow={null}
                description={
                    <>
                        {consoleRich(
                            'console.this_console_is_your_organization_its_projects_team_key_15ebbd0f',
                            { part1: label },
                        )}
                    </>
                }
            />

            <div className="mt-6 space-y-4">
                <Panel
                    title={consoleFinalText('console.open_part1_in_an_environment_882ce491', {
                        part1: label,
                    })}
                >
                    {projects.length === 0 ? (
                        <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                            {projectsHref === null ? (
                                <a href={consoleHref} className="underline underline-offset-2">
                                    {consoleT('console.open_console_f4063d1b')}
                                </a>
                            ) : (
                                consoleRich(
                                    'console.you_have_no_environment_to_open_yet_part1_part2_part3_t_3a10832d',
                                    {
                                        part1: ' ',
                                        part2: (
                                            <a
                                                href={projectsHref}
                                                className="underline underline-offset-2"
                                            >
                                                {consoleT('console.go_to_projects_cad12684')}
                                            </a>
                                        ),
                                        part3: ' ',
                                    },
                                )
                            )}
                        </p>
                    ) : (
                        <ul
                            className="space-y-2"
                            aria-label={consoleT('console.environments_07437cd6')}
                        >
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
                            {consoleRich(
                                'console.each_link_opens_part1_in_that_environment_s_console_5f38b7b2',
                                { part1: label },
                            )}
                        </p>
                    )}
                </Panel>

                {platformHref !== null && (
                    <Panel title={consoleT('console.the_platform_s_own_sign_in_1b8b2a80')}>
                        <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                            {consoleT(
                                'console.how_organization_owners_and_operators_sign_in_to_cbox_i_444d5c8b',
                            )}
                        </p>
                        <a
                            href={platformHref}
                            className="mt-2 inline-flex items-center gap-2 text-sm hover:underline"
                        >
                            {consoleRich('console._part1_open_the_platform_environment_fc71b62f', {
                                part1: <Icon name="lock" className="w-4 h-4" />,
                            })}
                        </a>
                    </Panel>
                )}
            </div>
        </div>
    );
}

ConfiguredInEnvironment.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
