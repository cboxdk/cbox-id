import { useTranslator } from '@/i18n';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Icon, PageHeader, Panel } from '@/ui';

type Props = PageProps<{
    refusedEnvironment: { name: string; type: string } | null;
    email: string | null;
    projectsHref: string;
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
    const { t: tc } = useTranslator();

    const name = environment?.name ?? tc('console.this_environment_42756031');

    return (
        <div style={{ maxWidth: '36rem' }}>
            <PageHeader
                eyebrow={null}
                description={
                    <>
                        {tc('console.you_are_signed_in_2d55cbc1')}
                        {email !== null ? (
                            <>
                                {' '}
                                {tc('console.as_12876f7c')}
                                <strong>{email}</strong>
                            </>
                        ) : null}
                        {tc('console.but_your_account_does_not_administer_30b5975a')}
                        {name}
                        {tc('console.an_environment_s_console_is_opened_by_the_ow_caccb539')}
                    </>
                }
            />

            <div className="mt-6">
                <Panel title={tc('console.what_you_can_do_f42148c5')}>
                    <ul className="space-y-3 text-sm">
                        <li className="flex gap-2">
                            <Icon name="members" className="w-4 h-4 mt-0.5 shrink-0" />
                            <span>
                                {tc(
                                    'console.ask_an_owner_of_the_workspace_to_give_you_ac_5e72a079',
                                )}
                                {name}.
                            </span>
                        </li>
                        <li className="flex gap-2">
                            <Icon name="switch" className="w-4 h-4 mt-0.5 shrink-0" />
                            <span>
                                {tc('console.signed_in_with_the_wrong_account_030df2ae')}{' '}
                                <a href={accountsHref} className="underline underline-offset-2">
                                    {tc('console.switch_user_4f899129')}
                                </a>
                                .
                            </span>
                        </li>
                        <li className="flex gap-2">
                            <Icon name="layers" className="w-4 h-4 mt-0.5 shrink-0" />
                            <span>
                                <a href={projectsHref} className="underline underline-offset-2">
                                    {tc('console.go_to_your_projects_8ceddc4b')}
                                </a>{' '}
                                {tc('console.to_open_an_environment_you_do_administer_7d12b7e9')}
                            </span>
                        </li>
                    </ul>
                </Panel>
            </div>
        </div>
    );
}

EnvironmentRefused.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
