import { Link, router } from '@inertiajs/react';
import { Dialog as Primitive } from 'radix-ui';
import { type ReactNode, useState } from 'react';
import { cn } from '@/lib/cn';
import { toggleTheme } from '@/lib/theme';
import type { NavArea, User } from '@/types';
import { Icon } from '@/ui';
import { EnvBadge } from './EnvBadge';

export interface MobileNavProps {
    areas: NavArea[];
    heading: string;
    subheading?: string | null;
    user: User | null;
    logoutUrl: string;
    /** A link to the console host's own security page, on the environment plane. */
    securityUrl?: string;
    /** Plane-specific content above the navigation — the organization switcher. */
    children?: ReactNode;
}

/**
 * THUMB-ANCHORED MOBILE NAVIGATION — the house pattern, shared by every plane.
 *
 * "Rule of thumb": the only always-visible control is a bar pinned to the BOTTOM of the
 * viewport, inside the natural thumb arc — not a hamburger stranded in the top-right
 * corner. Tapping it raises a sheet that grows up from the same spot, so the grouped
 * navigation opens where the thumb already is.
 *
 * The sheet is a Radix dialog rather than the hand-rolled focus trap it replaces. That
 * trap was written by hand because the Alpine Focus plugin is not loaded in this app, and
 * it was reimplemented — differently — in three places.
 *
 * Every group stays open. A rail is a pointer affordance; on a touch screen, collapsing
 * groups adds a tap to every navigation to save vertical space in a sheet that scrolls.
 */
export function MobileNav({
    areas,
    heading,
    subheading,
    user,
    logoutUrl,
    securityUrl,
    children,
}: MobileNavProps) {
    const [open, setOpen] = useState(false);

    return (
        <Primitive.Root open={open} onOpenChange={setOpen}>
            <div data-cbox-mobile-nav className="cbx-mobilebar lg:hidden">
                {user !== null && (
                    <span className="cbx-mobilebar-initial" aria-hidden="true">
                        {user.name.trim().charAt(0).toUpperCase() || 'C'}
                    </span>
                )}

                <span className="min-w-0 flex-1">
                    <span className="block text-[13px] font-semibold truncate leading-tight">
                        {heading} <EnvBadge />
                    </span>
                    {subheading != null && (
                        <span
                            className="block text-[11px] leading-tight truncate"
                            style={{ color: 'var(--muted-foreground)' }}
                        >
                            {subheading}
                        </span>
                    )}
                </span>

                <Primitive.Trigger className="cbx-mobilebar-btn" aria-label="Open menu">
                    <Icon name="menu" className="w-[18px] h-[18px]" /> Menu
                </Primitive.Trigger>
            </div>

            <Primitive.Portal>
                <Primitive.Overlay className="cbx-overlay lg:hidden" />
                <Primitive.Content className="cbx-sheet lg:hidden" aria-describedby={undefined}>
                    <Primitive.Title className="sr-only">Navigation</Primitive.Title>

                    <div className="cbx-sheet-hd">
                        <span className="cbx-sheet-grab" aria-hidden="true" />
                        <Primitive.Close className="cbx-sheet-close" aria-label="Close menu">
                            <Icon name="close" className="w-[18px] h-[18px]" />
                        </Primitive.Close>
                    </div>

                    {children !== undefined && <div className="cbx-sheet-slot">{children}</div>}

                    <nav className="cbx-sheet-nav" aria-label="Navigation">
                        {areas.map((area) => (
                            <div key={area.key} className="space-y-0.5">
                                <p
                                    className="cbx-nav-group flex items-center gap-2"
                                    style={{ color: 'var(--faint)' }}
                                >
                                    <Icon name={area.icon} className="w-3.5 h-3.5" />
                                    {area.label}
                                </p>

                                {area.pages.map((page) => (
                                    <Link
                                        key={page.route}
                                        href={page.href}
                                        onClick={() => setOpen(false)}
                                        className={cn('nav-link', page.active && 'is-active')}
                                        aria-current={page.active ? 'page' : undefined}
                                    >
                                        {page.label}
                                        {/*
                                            The sheet has the room the 176px sub-nav did
                                            not, so the entitlement lock is spelled out
                                            here rather than reduced to a glyph.
                                        */}
                                        {page.badge !== null && (
                                            <span
                                                className="ml-auto"
                                                style={{
                                                    fontSize: '0.6rem',
                                                    fontWeight: 600,
                                                    textTransform: 'uppercase',
                                                    letterSpacing: '0.04em',
                                                    color: 'var(--primary)',
                                                }}
                                            >
                                                {page.badge}
                                            </span>
                                        )}
                                    </Link>
                                ))}
                            </div>
                        ))}
                    </nav>

                    <div className="cbx-sheet-ft">
                        {user !== null && (
                            <div className="px-2 pb-1 min-w-0">
                                <p className="text-[13px] font-medium truncate">{user.name}</p>
                                {user.email !== null && (
                                    <p
                                        className="text-[11px] truncate"
                                        style={{ color: 'var(--muted-foreground)' }}
                                    >
                                        {user.email}
                                    </p>
                                )}
                            </div>
                        )}

                        {securityUrl !== undefined && (
                            <a href={securityUrl} className="nav-link w-full">
                                <Icon name="shield-check" className="w-[1.15rem] h-[1.15rem]" />
                                Profile &amp; security
                            </a>
                        )}

                        <button
                            type="button"
                            className="nav-link w-full"
                            onClick={() => toggleTheme()}
                        >
                            <Icon name="moon" className="w-[1.15rem] h-[1.15rem]" />
                            Toggle theme
                        </button>

                        <button
                            type="button"
                            className="nav-link w-full"
                            style={{ color: 'var(--destructive)' }}
                            onClick={() => router.post(logoutUrl)}
                        >
                            <Icon name="logout" className="w-[1.15rem] h-[1.15rem]" />
                            Sign out
                        </button>
                    </div>
                </Primitive.Content>
            </Primitive.Portal>
        </Primitive.Root>
    );
}
