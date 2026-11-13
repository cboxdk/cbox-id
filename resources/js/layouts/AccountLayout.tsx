import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { BackLink } from '@/chrome/BackLink';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { SharedProps } from '@/types';

/**
 * THE LAYOUT OF A PERSON'S OWN PAGES: their apps, profile, security, sessions and activity,
 * devices, keys and connected services, the step-up in front of a change.
 *
 * Always the console's layout, so always cbox-ui's `CboxShell`: the rail with the account
 * menu at its foot, Switch app in the header, ⌘K. Where the page stands in the console the
 * shell is the console's. On the login host, which has no console, the server sends the
 * same shell with only the person's own pages in it ({@see \App\Platform\Surfaces\AccountChrome}),
 * and `accountShell` adds the one thing a page there needs that the console does not: the
 * way back to the app the person came from. A tenant environment's host draws its own
 * "Back to …" in its own chrome (`accountBack`).
 */
export default function AccountLayout({ children }: { children: ReactNode }) {
    const { accountShell } = usePage<SharedProps>().props;

    return (
        <ConsoleLayout>
            {accountShell !== null && accountShell !== undefined && (
                <div className="acct-back">
                    <BackLink back={accountShell.back} />
                </div>
            )}
            {children}
        </ConsoleLayout>
    );
}
