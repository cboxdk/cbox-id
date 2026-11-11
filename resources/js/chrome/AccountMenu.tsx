import { useTranslator as useConsoleTranslator } from '@/i18n';
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
    organizationSettingsHref: string | null;
    /** The person's own account page — on the workspace host when drawn on an environment. */
    accountHref: string;
    /** The signed-in-user switcher. */
    switchUserHref: string;
    /** Platform admin, for whoever runs the install; null for everybody else. */
    platformHref: string | null;
    /** The platform's own environment console, for whoever runs the install; null otherwise. */
    platformEnvironmentHref?: string | null;
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
    organizationSettingsHref,
    accountHref,
    switchUserHref,
    platformHref,
    platformEnvironmentHref = null,
}: AccountMenuProps) {
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const signOut = useForm({});
    // Read when the menu opens, not at render: the theme is the document's, and a value
    // captured on the first render would name the theme from before the last toggle.
    const [theme, setTheme] = useState<'light' | 'dark'>('light');

    return (
        <DropdownMenu onOpenChange={(open) => open && setTheme(currentTheme())}>
            <DropdownMenuTrigger
                className="cbx-avatar-btn"
                aria-label={consoleFinalText('console.account_menu_part1_18b175d9', {
                    part1: user.name,
                })}
            >
                <Avatar name={user.name} />
            </DropdownMenuTrigger>

            <DropdownMenuContent side="bottom" align="end" style={{ minWidth: '240px' }}>
                <div className="cbx-account-hd">
                    <p className="idc-account-name">{user.name}</p>
                    {user.email !== null && <p className="cbx-account-email">{user.email}</p>}
                </div>

                {organizationSettingsHref !== null && (
                    <AccountMenuLink href={organizationSettingsHref} icon="briefcase">
                        {consoleT('console.organization_settings_4fd62541')}
                    </AccountMenuLink>
                )}
                {/*
                    From the SHELL, not from the route helpers: on the environment console
                    these pages are on the organization's host, and a relative link sent the
                    administrator to a sign-in form for the tenant's end users.
                */}
                <AccountMenuLink href={accountHref} icon="user">
                    {consoleT('console.account_7e1b0d56')}
                </AccountMenuLink>
                {/*
                    "Switch user": it moves between the people signed in on this device.
                    "Switch account" read as switching organization.
                */}
                <AccountMenuLink href={switchUserHref} icon="switch">
                    {consoleT('console.switch_account_fedce010')}
                </AccountMenuLink>

                {(platformHref !== null || platformEnvironmentHref !== null) && (
                    <DropdownMenuSeparator />
                )}
                {platformHref !== null && (
                    <AccountMenuLink href={platformHref} icon="lock">
                        {consoleT('console.platform_admin_69652f55')}
                    </AccountMenuLink>
                )}
                {/*
                    The platform's own environment — the sign-in every organization owner and
                    operator uses: its social login, sign-in methods and branding.
                */}
                {platformEnvironmentHref !== null && (
                    <AccountMenuLink href={platformEnvironmentHref} icon="layers">
                        {consoleT('console.platform_environment_2676dfe4')}
                    </AccountMenuLink>
                )}

                <DropdownMenuSeparator />

                <DropdownMenuItem
                    // `onSelect` rather than `onClick`: Radix fires it for Enter and Space
                    // as well as a pointer, and a menu item that only answers a click is
                    // not a menu item.
                    onSelect={() => setTheme(toggleTheme())}
                >
                    <Icon name={theme === 'dark' ? 'sun' : 'moon'} className="w-4 h-4" />
                    <span className="min-w-0 flex-1">{consoleT('console.theme_efb52e71')}</span>
                    <span className="cbx-account-hint">
                        {theme === 'dark'
                            ? consoleText('console.dark_60acc53f')
                            : consoleText('console.light_dbcd5e7b')}
                    </span>
                </DropdownMenuItem>

                <DropdownMenuItem
                    destructive
                    // A POST, because signing out is a state change and a GET that ends a
                    // session can be triggered by any image tag on any page.
                    onSelect={() => signOut.post(logoutUrl)}
                >
                    {consoleRich('console._part1_sign_out_c5531c62', {
                        part1: <Icon name="logout" className="w-4 h-4" />,
                    })}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * One entry of the menu, styled as a menu row.
 *
 * An ABSOLUTE href is another host — the environment console's links to the person's own
 * account, which lives on the organization's host — and is a plain anchor: Inertia visits
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
