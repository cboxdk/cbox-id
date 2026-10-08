import { Link, useForm } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';
import { currentTheme, toggleTheme } from '@/lib/theme';
import type { User } from '@/types';
import {
    Avatar,
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
    Icon,
} from '@/ui';
import type { IconName } from '@/ui/icons';

export interface AccountMenuProps {
    user: User;
    logoutUrl: string;
    /** The workspace's settings, where this person may change them; null hides the row. */
    workspaceSettingsHref: string | null;
    /** The person's own account page — on the workspace host when drawn on an environment. */
    accountHref: string;
    /** The signed-in-user switcher. */
    switchUserHref: string;
    /** Platform admin, for whoever runs the install; null for everybody else. */
    platformHref: string | null;
}

/**
 * Who you are, top right — the avatar menu.
 *
 * Everything that is about YOU rather than about the page: your account, the workspace
 * you belong to, the theme, signing out — and, for an operator, the door into platform
 * admin. That door used to be three more areas at the bottom of every rail an operator
 * saw, so the pages that suspend a customer sat one icon below their own Settings.
 */
export function AccountMenu({
    user,
    logoutUrl,
    workspaceSettingsHref,
    accountHref,
    switchUserHref,
    platformHref,
}: AccountMenuProps) {
    const signOut = useForm({});
    // Read when the menu opens, not at render: the theme is the document's, and a value
    // captured on the first render would name the theme from before the last toggle.
    const [theme, setTheme] = useState<'light' | 'dark'>('light');

    return (
        <DropdownMenu onOpenChange={(open) => open && setTheme(currentTheme())}>
            <DropdownMenuTrigger
                className="cbx-avatar-btn"
                aria-label={`Account menu — ${user.name}`}
            >
                <Avatar name={user.name} />
            </DropdownMenuTrigger>

            <DropdownMenuContent side="bottom" align="end" style={{ minWidth: '240px' }}>
                <div className="cbx-account-hd">
                    <p className="cbx-account-name">{user.name}</p>
                    {user.email !== null && <p className="cbx-account-email">{user.email}</p>}
                </div>

                {workspaceSettingsHref !== null && (
                    <AccountMenuLink href={workspaceSettingsHref} icon="briefcase">
                        Workspace settings
                    </AccountMenuLink>
                )}
                {/*
                    From the SHELL, not from the route helpers: on the environment console
                    these pages are on the workspace's host, and a relative link sent the
                    administrator to a sign-in form for the tenant's end users.
                */}
                <AccountMenuLink href={accountHref} icon="user">
                    My account
                </AccountMenuLink>
                {/*
                    "Switch user": it moves between the people signed in on this device.
                    "Switch account" read as switching workspace.
                */}
                <AccountMenuLink href={switchUserHref} icon="switch">
                    Switch user
                </AccountMenuLink>

                {platformHref !== null && (
                    <>
                        <DropdownMenuSeparator />
                        <AccountMenuLink href={platformHref} icon="lock">
                            Platform admin
                        </AccountMenuLink>
                    </>
                )}

                <DropdownMenuSeparator />

                <DropdownMenuItem
                    // `onSelect` rather than `onClick`: Radix fires it for Enter and Space
                    // as well as a pointer, and a menu item that only answers a click is
                    // not a menu item.
                    onSelect={() => setTheme(toggleTheme())}
                >
                    <Icon name={theme === 'dark' ? 'sun' : 'moon'} className="w-4 h-4" />
                    <span className="min-w-0 flex-1">Theme</span>
                    <span className="cbx-account-hint">{theme === 'dark' ? 'Dark' : 'Light'}</span>
                </DropdownMenuItem>

                <DropdownMenuItem
                    destructive
                    // A POST, because signing out is a state change and a GET that ends a
                    // session can be triggered by any image tag on any page.
                    onSelect={() => signOut.post(logoutUrl)}
                >
                    <Icon name="logout" className="w-4 h-4" />
                    Sign out
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * One entry of the menu, styled as a menu row.
 *
 * An ABSOLUTE href is another host — the environment console's links to the person's own
 * account, which lives on the workspace's host — and is a plain anchor: Inertia visits
 * are same-origin XHRs, and one aimed at another host fails without a word.
 */
export function AccountMenuLink({
    href,
    icon,
    children,
}: {
    href: string;
    icon: IconName;
    children: ReactNode;
}) {
    const external = /^https?:\/\//.test(href) && !href.startsWith(window.location.origin);

    return (
        <DropdownMenuItem asChild>
            {external ? (
                <a href={href}>
                    <Icon name={icon} className="w-4 h-4" />
                    {children}
                </a>
            ) : (
                <Link href={href}>
                    <Icon name={icon} className="w-4 h-4" />
                    {children}
                </Link>
            )}
        </DropdownMenuItem>
    );
}
