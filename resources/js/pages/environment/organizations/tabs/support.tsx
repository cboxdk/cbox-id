import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Panel, type SupportSessionRow, SupportSessions } from '@/ui';

type Props = PageProps<{
    /** Somebody signed in to an app as one of its people, right now. */
    supportSessions: SupportSessionRow[];
    usersHref: string;
}>;

/**
 * AN ORGANIZATION › SUPPORT — open support sessions as its people, and the way to end one.
 * The header and tabs are the layout's (`../frame.tsx`).
 */
export default function OrganizationSupport({ supportSessions, usersHref }: Props) {
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    return (
        <Panel
            title={consoleT('console.support_sessions_b9144532')}
            description={consoleT(
                'console.signed_in_to_an_app_as_one_of_this_organization_s_peopl_d9c070c7',
            )}
        >
            {supportSessions.length === 0 ? (
                <p className="text-sm" style={{ color: 'var(--faint)' }}>
                    {consoleRich(
                        'console.nobody_is_signed_in_to_an_app_as_one_of_its_people_star_a41ae388',
                        {
                            part1: ' ',
                            part2: (
                                <Link href={usersHref} className="underline underline-offset-2">
                                    {consoleT('console.users_6b0cc904')}
                                </Link>
                            ),
                        },
                    )}
                </p>
            ) : (
                <SupportSessions sessions={supportSessions} lead="user" />
            )}
        </Panel>
    );
}

OrganizationSupport.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
