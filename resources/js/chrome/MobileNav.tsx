import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link, router } from '@inertiajs/react';
import { Dialog as Primitive } from 'radix-ui';
import { type ReactNode, useState } from 'react';
import { cn } from '@/lib/cn';
import { toggleTheme } from '@/lib/theme';
import type { NavArea, User } from '@/types';
import { NavCount } from './NavCount';
import { Icon } from '@/ui';
import { EnvBadge } from './EnvBadge';

export interface MobileNavProps {
    areas: NavArea[];
    heading: string;
    subheading?: string | null;
    user: User | null;
    logoutUrl: string;
    /**
     * Whether the bar names the environment's type. Not on a workspace's own console: its
     * requests resolve to Cbox's own environment, whose badge would only suggest that the
     * workspace is one.
     */
    showEnvironment?: boolean;
    /** The person's own account page — shown here only where the rail has no My account area. */
    accountUrl?: string;
    /** Whether the rail lacks a My account area, so the sheet carries the link instead. */
    showAccountLink?: boolean;
    /** The signed-in-user switcher. */
    switchUserUrl?: string;
    /** The workspace's settings, where this person may change them. */
    workspaceSettingsUrl?: string | null;
    /** Platform admin, for whoever runs the install. */
    platformUrl?: string | null;
    /** Plane-specific content above the navigation — the context switcher. */
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
    showEnvironment = true,
    accountUrl,
    showAccountLink = false,
    switchUserUrl,
    workspaceSettingsUrl = null,
    platformUrl = null,
    children,
}: MobileNavProps) {
    const { t: consoleShortText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [open, setOpen] = useState(false);
    const [accountOpen, setAccountOpen] = useState(false);

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
                        {heading} {showEnvironment && <EnvBadge />}
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

                <Primitive.Trigger
                    className="cbx-mobilebar-btn"
                    aria-label={consoleT('console.open_menu_b40b3713')}
                >
                    {consoleRich('console._part1_menu_79fc3632', {
                        part1: <Icon name="menu" className="w-[18px] h-[18px]" />,
                    })}
                </Primitive.Trigger>
            </div>

            <Primitive.Portal>
                <Primitive.Overlay className="cbx-overlay lg:hidden" />
                <Primitive.Content className="cbx-sheet lg:hidden" aria-describedby={undefined}>
                    <Primitive.Title className="sr-only">
                        {consoleT('console.navigation_3db65f8c')}
                    </Primitive.Title>

                    <div className="cbx-sheet-hd">
                        <span className="cbx-sheet-grab" aria-hidden="true" />
                        <Primitive.Close
                            className="cbx-sheet-close"
                            aria-label={consoleT('console.close_menu_6ccd5c78')}
                        >
                            <Icon name="close" className="w-[18px] h-[18px]" />
                        </Primitive.Close>
                    </div>

                    {children !== undefined && <div className="cbx-sheet-slot">{children}</div>}

                    <nav
                        className="cbx-sheet-nav"
                        aria-label={consoleT('console.navigation_3db65f8c')}
                    >
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
                                        <NavCount count={page.count} />
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

                    {/*
                        THE ACCOUNT IS ONE ROW, NOT HALF THE SHEET. It used to be a pinned
                        block of seven rows — name, address, My account, Switch user,
                        Platform admin, Toggle theme, Sign out — that took the bottom half
                        of a phone's sheet on every open and left the navigation, the reason
                        the sheet was opened, a scrolling strip above it. Now: who you are
                        on one line, and the account's actions folded behind it.
                    */}
                    <div className="cbx-sheet-ft">
                        {accountOpen && (
                            <div id="mobile-account-actions" className="flex flex-col gap-0.5 pb-1">
                                {/*
                                    Plain anchors throughout: on the environment console
                                    every one of these is on the workspace's host, so it is
                                    a page load rather than a visit.
                                */}
                                {workspaceSettingsUrl !== null && (
                                    <a href={workspaceSettingsUrl} className="nav-link w-full">
                                        {consoleRich('console._part1_workspace_settings_908122da', {
                                            part1: (
                                                <Icon
                                                    name="briefcase"
                                                    className="w-[1.15rem] h-[1.15rem]"
                                                />
                                            ),
                                        })}
                                    </a>
                                )}

                                {/*
                                    MY ACCOUNT only where the rail has no area for it — the
                                    environment console, whose account pages are on the
                                    workspace's host. Everywhere else it is a section above.
                                */}
                                {showAccountLink && accountUrl !== undefined && (
                                    <a href={accountUrl} className="nav-link w-full">
                                        {consoleRich('console._part1_my_account_e642603a', {
                                            part1: (
                                                <Icon
                                                    name="user"
                                                    className="w-[1.15rem] h-[1.15rem]"
                                                />
                                            ),
                                        })}
                                    </a>
                                )}

                                {switchUserUrl !== undefined && (
                                    <a href={switchUserUrl} className="nav-link w-full">
                                        {consoleRich('console._part1_switch_user_704d5a0f', {
                                            part1: (
                                                <Icon
                                                    name="switch"
                                                    className="w-[1.15rem] h-[1.15rem]"
                                                />
                                            ),
                                        })}
                                    </a>
                                )}

                                {platformUrl !== null && (
                                    <a href={platformUrl} className="nav-link w-full">
                                        {consoleRich('console._part1_platform_admin_6ca6edf3', {
                                            part1: (
                                                <Icon
                                                    name="lock"
                                                    className="w-[1.15rem] h-[1.15rem]"
                                                />
                                            ),
                                        })}
                                    </a>
                                )}

                                <button
                                    type="button"
                                    className="nav-link w-full"
                                    onClick={() => toggleTheme()}
                                >
                                    {consoleRich('console._part1_toggle_theme_a2f3db78', {
                                        part1: (
                                            <Icon name="moon" className="w-[1.15rem] h-[1.15rem]" />
                                        ),
                                    })}
                                </button>

                                <button
                                    type="button"
                                    className="nav-link w-full"
                                    style={{ color: 'var(--destructive)' }}
                                    onClick={() => router.post(logoutUrl)}
                                >
                                    {consoleRich('console._part1_sign_out_c5531c62', {
                                        part1: (
                                            <Icon
                                                name="logout"
                                                className="w-[1.15rem] h-[1.15rem]"
                                            />
                                        ),
                                    })}
                                </button>
                            </div>
                        )}

                        <button
                            type="button"
                            className="nav-link w-full"
                            aria-expanded={accountOpen}
                            aria-controls="mobile-account-actions"
                            onClick={() => setAccountOpen((value) => !value)}
                        >
                            <span className="cbx-mobilebar-initial shrink-0" aria-hidden="true">
                                {(user?.name.trim().charAt(0).toUpperCase() ?? '') || 'C'}
                            </span>
                            <span className="min-w-0 flex-1 text-left">
                                <span className="block text-[13px] font-medium truncate leading-tight">
                                    {user?.name ?? consoleShortText('console.account_7e1b0d56')}
                                </span>
                                {user?.email != null && (
                                    <span
                                        className="block text-[11px] truncate leading-tight"
                                        style={{ color: 'var(--muted-foreground)' }}
                                    >
                                        {user.email}
                                    </span>
                                )}
                            </span>
                            <Icon
                                name="chevron"
                                className={cn(
                                    'w-4 h-4 shrink-0 transition-transform',
                                    accountOpen ? '' : 'rotate-180',
                                )}
                            />
                            <span className="sr-only">
                                {accountOpen
                                    ? consoleText('console.hide_account_3759e10c')
                                    : consoleText('console.show_account_345f7979')}
                            </span>
                        </button>
                    </div>
                </Primitive.Content>
            </Primitive.Portal>
        </Primitive.Root>
    );
}
