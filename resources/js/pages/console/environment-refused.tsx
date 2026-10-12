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
    const name = environment?.name ?? 'this environment';

    return (
        <div style={{ maxWidth: '36rem' }}>
            <PageHeader
                eyebrow={null}
                description={
                    <>
                        You are signed in
                        {email !== null ? (
                            <>
                                {' '}
                                as <strong>{email}</strong>
                            </>
                        ) : null}
                        , but your account does not administer {name}. An environment’s console is
                        opened by the owners, admins and developers of the workspace it belongs to.
                    </>
                }
            />

            <div className="mt-6">
                <Panel title="What you can do">
                    <ul className="space-y-3 text-sm">
                        <li className="flex gap-2">
                            <Icon name="members" className="w-4 h-4 mt-0.5 shrink-0" />
                            <span>Ask an owner of the workspace to give you access to {name}.</span>
                        </li>
                        <li className="flex gap-2">
                            <Icon name="switch" className="w-4 h-4 mt-0.5 shrink-0" />
                            <span>
                                Signed in with the wrong account?{' '}
                                <a href={accountsHref} className="underline underline-offset-2">
                                    Switch user
                                </a>
                                .
                            </span>
                        </li>
                        <li className="flex gap-2">
                            <Icon name="layers" className="w-4 h-4 mt-0.5 shrink-0" />
                            <span>
                                <a href={projectsHref} className="underline underline-offset-2">
                                    Go to your projects
                                </a>{' '}
                                to open an environment you do administer.
                            </span>
                        </li>
                    </ul>
                </Panel>
            </div>
        </div>
    );
}

EnvironmentRefused.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
