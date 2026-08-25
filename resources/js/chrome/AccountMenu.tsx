import { Link, useForm } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { toggleTheme } from '@/lib/theme';
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

export interface AccountMenuProps {
    user: User;
    logoutUrl: string;
    /** Plane-specific entries, above Toggle theme and Sign out. */
    children?: ReactNode;
}

/**
 * Who you are, at the BOTTOM OF THE RAIL.
 *
 * Not in the sub-nav footer, and that is a rule rather than a preference: the sub-nav is
 * contextual and an area with one page has none at all, so identity placed there would
 * vanish on a third of the console's pages.
 */
export function AccountMenu({ user, logoutUrl, children }: AccountMenuProps) {
    const signOut = useForm({});

    return (
        <DropdownMenu>
            <DropdownMenuTrigger className="cbx-railitem" title={user.name}>
                <Avatar name={user.name} />
                <span className="lbl" style={{ overflow: 'hidden', textOverflow: 'ellipsis' }}>
                    {user.name}
                </span>
            </DropdownMenuTrigger>

            <DropdownMenuContent side="top" align="start" style={{ minWidth: '230px' }}>
                <div
                    style={{
                        padding: '8px 10px',
                        borderBottom: '1px solid var(--border)',
                        marginBottom: '4px',
                    }}
                >
                    <p style={{ fontSize: '13px', fontWeight: 600, margin: 0 }}>{user.name}</p>
                    {user.email !== null && (
                        <p
                            style={{
                                fontSize: '12px',
                                color: 'var(--muted-foreground)',
                                margin: '2px 0 0',
                            }}
                        >
                            {user.email}
                        </p>
                    )}
                </div>

                {children}

                <DropdownMenuItem
                    // `onSelect` rather than `onClick`: Radix fires it for Enter and Space
                    // as well as a pointer, and a menu item that only answers a click is
                    // not a menu item.
                    onSelect={() => toggleTheme()}
                >
                    <Icon name="moon" className="w-4 h-4" />
                    Toggle theme
                </DropdownMenuItem>

                <DropdownMenuSeparator />

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

/** A plane-specific entry for the menu's slot — an internal link, styled as a menu row. */
export function AccountMenuLink({
    href,
    icon,
    children,
}: {
    href: string;
    icon: 'key' | 'refresh';
    children: ReactNode;
}) {
    return (
        <DropdownMenuItem asChild>
            <Link href={href}>
                <Icon name={icon} className="w-4 h-4" />
                {children}
            </Link>
        </DropdownMenuItem>
    );
}
