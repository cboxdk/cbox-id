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
    return (
        <Panel
            title="Support sessions"
            description="Signed in to an app as one of this organization's people right now. Each one is also on the organization's audit log, with its reason."
        >
            {supportSessions.length === 0 ? (
                <p className="text-sm" style={{ color: 'var(--faint)' }}>
                    Nobody is signed in to an app as one of its people. Start one from a person's
                    page under <Link href={usersHref} className="underline underline-offset-2">Users</Link>.
                </p>
            ) : (
                <SupportSessions sessions={supportSessions} lead="user" />
            )}
        </Panel>
    );
}

OrganizationSupport.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
