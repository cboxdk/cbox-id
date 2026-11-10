import { useTranslator as useConsoleTranslator } from '@/i18n';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Icon, PageHeader, Panel } from '@/ui';

type Props = PageProps<{
    refusedEnvironment: { name: string; type: string } | null;
    email: string | null;
    projectsHref: string | null;
    accountsHref: string;
}>;

/**
 * "YOU DON'T ADMINISTER THIS ENVIRONMENT" — the end of a sign-in that worked, for a console
 * that is not this person's. Says so in that order: the sign-in succeeded, this console is
 * somebody else's to grant, and here is where to go instead.
 */
export default function EnvironmentRefused({
    refusedEnvironment: environment,
    email,
    projectsHref,
    accountsHref,
}: Props) {
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const name = environment?.name ?? consoleT('console.this_environment_42756031');

    return (
        <div style={{ maxWidth: '36rem' }}>
            <PageHeader
                eyebrow={null}
                description={
                    <>
                        {consoleRich(
                            'console.you_are_signed_in_part1_but_your_account_does_not_admin_1a2cb08a',
                            {
                                part1:
                                    email !== null ? (
                                        <>
                                            {consoleRich('console._part1_as_part2_cd228e4d', {
                                                part1: ' ',
                                                part2: <strong>{email}</strong>,
                                            })}
                                        </>
                                    ) : null,
                                part2: name,
                            },
                        )}
                    </>
                }
            />

            <div className="mt-6">
                <Panel title={consoleT('console.what_you_can_do_f42148c5')}>
                    <ul className="space-y-3 text-sm">
                        <li className="flex gap-2">
                            <Icon name="members" className="w-4 h-4 mt-0.5 shrink-0" />
                            <span>
                                {consoleRich(
                                    'console.ask_an_owner_of_the_organization_to_give_you_access_to__74d808e8',
                                    { part1: name },
                                )}
                            </span>
                        </li>
                        <li className="flex gap-2">
                            <Icon name="switch" className="w-4 h-4 mt-0.5 shrink-0" />
                            <span>
                                {consoleRich(
                                    'console.signed_in_with_the_wrong_account_part1_part2_d38e6a3f',
                                    {
                                        part1: ' ',
                                        part2: (
                                            <a
                                                href={accountsHref}
                                                className="underline underline-offset-2"
                                            >
                                                {consoleT('console.switch_account_fedce010')}
                                            </a>
                                        ),
                                    },
                                )}
                            </span>
                        </li>
                        {projectsHref !== null && (
                            <li className="flex gap-2">
                                <Icon name="layers" className="w-4 h-4 mt-0.5 shrink-0" />
                                <span>
                                    {consoleRich(
                                        'console._part1_part2_to_open_an_environment_you_do_administer_4755ab7d',
                                        {
                                            part1: (
                                                <a
                                                    href={projectsHref}
                                                    className="underline underline-offset-2"
                                                >
                                                    {consoleT(
                                                        'console.go_to_your_projects_8ceddc4b',
                                                    )}
                                                </a>
                                            ),
                                            part2: ' ',
                                        },
                                    )}
                                </span>
                            </li>
                        )}
                    </ul>
                </Panel>
            </div>
        </div>
    );
}

EnvironmentRefused.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
