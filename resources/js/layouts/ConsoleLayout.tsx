import { Head, Link, usePage } from '@inertiajs/react';
import { type ReactNode, useCallback, useEffect, useState } from 'react';
import { AccountMenu, AccountMenuLink } from '@/chrome/AccountMenu';
import { ActingOrganization } from '@/chrome/ActingOrganization';
import { EnvBadge } from '@/chrome/EnvBadge';
import { ImpersonationBanner, SandboxBanner } from '@/chrome/Banners';
import { CommandPalette } from '@/chrome/CommandPalette';
import { MobileNav } from '@/chrome/MobileNav';
import { Rail } from '@/chrome/Rail';
import { RouteAnnouncer } from '@/chrome/RouteAnnouncer';
import { Subnav } from '@/chrome/Subnav';
import { Switcher } from '@/chrome/Switcher';
import { Toaster } from '@/chrome/Toaster';
import { setNavPinned } from '@/lib/theme';
import type { SharedProps } from '@/types';
import { Icon, TooltipProvider } from '@/ui';
import { logout } from '@routes';
import { exit as exitImpersonation } from '@routes/impersonation';
import { switchMethod as switchOrganization } from '@routes/organization';
import { switchMethod as switchEnvironment } from '@routes/platform/environment';

const SUBNAV_KEY = 'cbox-subnav-collapsed';

/** The built-in role as the console writes it everywhere else — "Owner", not `owner`. */
function builtInRole(role: string | null): string {
    return role === null ? 'Member' : role.charAt(0).toUpperCase() + role.slice(1);
}

export interface ConsoleLayoutProps {
    children: ReactNode;
}

/**
 * THE CONSOLE'S ONE SHELL — both planes, every page.
 *
 * There were two of these, and the drift between them is written down all over the files
 * they replace: one carried the impersonation banner and the other did not, so an
 * operator who started an impersonation from a page on the wrong layout had no way out;
 * one used the shared mobile navigation and the other hand-rolled a drawer, so the same
 * page behaved differently on a phone depending on which plane served it.
 *
 * The chrome is decided on the SERVER ({@see \App\Platform\Console\ShellPayload}) and
 * arrives as one shared prop. This file is only the arrangement.
 */
export default function ConsoleLayout({ children }: ConsoleLayoutProps) {
    const { shell, auth, title, environment } = usePage<SharedProps>().props;

    const [pinned, setPinned] = useState(shell?.navPinned ?? false);
    // Read in a lazy initialiser rather than an effect: reading it after mount renders
    // one frame with the sub-nav OPEN before collapsing it, and a 176px column that
    // appears and vanishes on every navigation is worse than not remembering at all.
    //
    // Guarded on `window` because this initialiser is the one place that would run under
    // server-side rendering if it is ever turned on.
    const [subnavCollapsed, setSubnavCollapsed] = useState(
        () => typeof window !== 'undefined' && localStorage.getItem(SUBNAV_KEY) === '1',
    );

    const toggleSubnav = useCallback(() => {
        setSubnavCollapsed((collapsed) => {
            localStorage.setItem(SUBNAV_KEY, collapsed ? '0' : '1');

            return !collapsed;
        });
    }, []);

    const togglePin = useCallback(() => {
        setPinned((current) => {
            setNavPinned(!current);

            return !current;
        });
    }, []);

    // ⌘. collapses the second tier, in every app in the family.
    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent): void => {
            if (event.key !== '.' || !(event.metaKey || event.ctrlKey)) {
                return;
            }

            event.preventDefault();
            toggleSubnav();
        };

        document.addEventListener('keydown', onKeyDown);

        return () => document.removeEventListener('keydown', onKeyDown);
    }, [toggleSubnav]);

    // A page rendered before the shell exists — mid-sign-in, an environment whose admin
    // session has lapsed. Render the page rather than a broken frame around it.
    if (shell === null || auth.user === null) {
        return (
            <TooltipProvider>
                <Head title={title} />
                <RouteAnnouncer />
                <Toaster />
                <main id="main-content" className="canvas-gradient">
                    {children}
                </main>
            </TooltipProvider>
        );
    }

    const active = shell.areas.find((area) => area.key === shell.activeArea);
    const organization = auth.organization;

    return (
        <TooltipProvider>
            {/*
                The title the controller stated, rendered once React is here. The root
                view already put the same string in `<head>` for the first paint; this is
                what keeps it right across a client-side navigation, where nothing
                re-renders the document.
            */}
            <Head title={title} />
            <RouteAnnouncer />
            <Toaster />
            <SandboxBanner />
            <ImpersonationBanner exitUrl={exitImpersonation.url()} />

            <div className="flex h-full">
                <Rail
                    areas={shell.areas}
                    brandHref={shell.brandHref}
                    brandLabel={organization?.name ?? 'Console'}
                    pinned={pinned}
                    onTogglePin={togglePin}
                    foot={
                        <AccountMenu user={auth.user} logoutUrl={logout.url()}>
                            {/*
                                From the SHELL, not from the route helpers: on the
                                environment console these pages are on the workspace's
                                host, and a relative link sent the administrator to a
                                sign-in form for the tenant's end users.
                            */}
                            <AccountMenuLink href={shell.accountHref} icon="user">
                                My account
                            </AccountMenuLink>
                            {/*
                                "Switch user": it moves between the people signed in on
                                this device. "Switch account" read as switching workspace.
                            */}
                            <AccountMenuLink href={shell.switchUserHref} icon="switch">
                                Switch user
                            </AccountMenuLink>
                        </AccountMenu>
                    }
                />

                {/*
                    Only when the area has more than one page. A single-page area is fully
                    addressed by its rail icon, and an empty second column is a column of
                    nothing that still costs 176px.
                */}
                {active !== undefined && active.pages.length > 1 && (
                    <Subnav
                        label={active.label}
                        pages={active.pages}
                        collapsed={subnavCollapsed}
                        onToggle={toggleSubnav}
                    />
                )}

                <MobileNav
                    areas={shell.areas}
                    heading={
                        shell.workspace !== null
                            ? (environment.name ?? 'Environment')
                            : (organization?.name ?? 'Console')
                    }
                    subheading={shell.workspace?.name ?? null}
                    user={auth.user}
                    logoutUrl={logout.url()}
                    showEnvironment={shell.altitude !== 'workspace'}
                    accountUrl={shell.accountHref}
                    switchUserUrl={shell.switchUserHref}
                    workspace={shell.workspace}
                />

                <div className="flex flex-col min-w-0 flex-1">
                    <header className="cbx-topbar">
                        <div className="flex items-center gap-2 min-w-0">
                            {/*
                                TWO DIFFERENT QUESTIONS, and only one of them is asked on
                                any given plane. The account plane asks which of MY
                                organizations I am in — a handful, so a menu of options.
                                The environment plane asks which TENANT of this environment
                                I am acting on — unbounded, so a search.
                            */}
                            {/*
                                THE WAY BACK. The environment console is reached from the
                                workspace's Projects page on another host, and had no link
                                back to it: the rail and the brand mark are the
                                environment's own. So the topbar names where you are the
                                way the design system says it should — workspace, then
                                environment — and the first crumb goes home.
                            */}
                            {shell.workspace !== null && (
                                <>
                                    <a
                                        href={shell.workspace.href}
                                        className="cbx-crumb-back"
                                        aria-label={`Back to ${shell.workspace.name} — Projects`}
                                        title={`Back to ${shell.workspace.name}`}
                                    >
                                        <Icon name="arrow-left" className="w-3.5 h-3.5 shrink-0" />
                                        <span className="hidden sm:block truncate">
                                            {shell.workspace.name}
                                        </span>
                                    </a>
                                    {/*
                                        The environment's name, on a screen wide enough for
                                        it. On a phone the bottom bar already names it, and
                                        the topbar keeps its room for the way back and the
                                        acting organization.
                                    */}
                                    <span className="hidden sm:inline-flex items-center gap-2 min-w-0">
                                        <span style={{ color: 'var(--faint)' }} aria-hidden="true">
                                            /
                                        </span>
                                        <span className="cbx-crumb-env">
                                            <span className="truncate">
                                                {environment.name ?? 'Environment'}
                                            </span>
                                            <EnvBadge />
                                        </span>
                                        <span style={{ color: 'var(--faint)' }} aria-hidden="true">
                                            /
                                        </span>
                                    </span>
                                </>
                            )}

                            {shell.actingOrganization !== null ? (
                                <ActingOrganization acting={shell.actingOrganization} />
                            ) : (
                                <Switcher
                                    heading="Switch organization"
                                    label={organization?.name ?? 'No organization'}
                                    caption={builtInRole(organization?.role ?? null)}
                                    initial={(organization?.name ?? 'C').charAt(0).toUpperCase()}
                                    options={shell.organizations}
                                    action={switchOrganization.url()}
                                    field="organization"
                                />
                            )}

                            {/*
                                WHICH ESTATE THIS CONSOLE IS POINTED AT. Only for whoever
                                runs the deployment, and only ever beside the organization
                                it belongs to — an operator acting on a tenant should be
                                able to see, without leaving the page, which tenant that is.
                            */}
                            {shell.isOperator && shell.environments.length > 0 && (
                                <>
                                    <span style={{ color: 'var(--faint)' }} aria-hidden="true">
                                        /
                                    </span>
                                    <Switcher
                                        heading="Switch target"
                                        eyebrow="Target environment"
                                        icon="layers"
                                        label={
                                            shell.environments.find((option) => option.current)
                                                ?.label ?? 'None yet'
                                        }
                                        options={shell.environments}
                                        action={switchEnvironment.url()}
                                        field="environment"
                                        openLabel={(option) => `Open ${option.label}'s own console`}
                                    />
                                </>
                            )}
                        </div>

                        <div className="flex items-center gap-2">
                            {/*
                                WHAT AUTHORITY YOU ARE HOLDING. On every console page, not
                                only the platform ones — the authority does not lapse when
                                an operator navigates to a customer-facing page, so neither
                                should the reminder that they have it.
                            */}
                            {shell.isOperator && (
                                <span className="cbx-operator-pill hidden sm:inline-flex">
                                    <Icon name="lock" className="w-3 h-3" />
                                    Platform operator
                                </span>
                            )}

                            <CommandPalette areas={shell.areas} />
                        </div>
                    </header>

                    <main id="main-content" className="flex-1 overflow-y-auto canvas-gradient">
                        <div className="p-6 lg:p-8 mx-auto w-full" style={{ maxWidth: '72rem' }}>
                            {shell.notice !== null && (
                                <div className="cbx-shell-notice" role="note">
                                    <Icon name="info" className="w-4 h-4 shrink-0" />
                                    <p className="min-w-0 flex-1">{shell.notice.message}</p>
                                    <Link
                                        href={shell.notice.href}
                                        className="cbx-shell-notice-link"
                                    >
                                        {shell.notice.label}
                                    </Link>
                                </div>
                            )}
                            {children}
                        </div>
                    </main>
                </div>
            </div>
        </TooltipProvider>
    );
}
