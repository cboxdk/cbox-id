import { Head, Link, usePage } from '@inertiajs/react';
import { type ReactNode, useCallback, useEffect, useState } from 'react';
import { AccountMenu } from '@/chrome/AccountMenu';
import { ImpersonationBanner, SandboxBanner } from '@/chrome/Banners';
import { CommandPalette } from '@/chrome/CommandPalette';
import { ContextSwitcher } from '@/chrome/ContextSwitcher';
import { MobileNav } from '@/chrome/MobileNav';
import { PlatformStrip } from '@/chrome/PlatformStrip';
import { Rail } from '@/chrome/Rail';
import { RouteAnnouncer } from '@/chrome/RouteAnnouncer';
import { Subnav } from '@/chrome/Subnav';
import { Toaster } from '@/chrome/Toaster';
import { useDocumentLanguage } from '@/i18n';
import { setNavPinned } from '@/lib/theme';
import { OrganizationFrame } from '@/pages/environment/organizations/frame';
import type { SharedProps } from '@/types';
import { Icon, PageApiEquivalents, TooltipProvider } from '@/ui';
import { logout } from '@routes';
import { exit as exitImpersonation } from '@routes/impersonation';

const SUBNAV_KEY = 'cbox-subnav-collapsed';

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
 * arrives as one shared prop. This file is only the arrangement:
 *
 *  - the rail, left: where you can go in this console;
 *  - the topbar: where you ARE (workspace / project / environment), search, and who you
 *    are — and nothing that silently narrows the pages: a page about one organization says
 *    so in its own URL and draws that organization's header itself (`organizationHub`);
 *  - in platform admin, a strip above it all saying so, with the way out.
 */
export default function ConsoleLayout({ children: page }: ConsoleLayoutProps) {
    const { shell, auth, title, environment, organizationHub } = usePage<SharedProps>().props;

    // A page under `/admin/organizations/{organization}/…` is a tab of that organization's
    // page: its header and tabs go around it, whichever page it is.
    const children =
        organizationHub !== undefined && organizationHub !== null ? (
            <OrganizationFrame hub={organizationHub}>{page}</OrganizationFrame>
        ) : (
            page
        );

    // The console is English, whatever language the sign-in page before it was in.
    useDocumentLanguage('en');

    const [pinned, setPinned] = useState(shell?.navPinned ?? true);
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
    const workspace = shell.context.workspaces.find((option) => option.current);
    const onEnvironment = shell.altitude === 'environment';

    // The context switcher — drawn in the topbar, and again in the phone's sheet, where the
    // topbar has no room for all of it.
    const context = <ContextSwitcher context={shell.context} />;

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
                    brandLabel={
                        shell.platformMode
                            ? 'Platform admin'
                            : (workspace?.label ?? organization?.name ?? 'Console')
                    }
                    pinned={pinned}
                    onTogglePin={togglePin}
                />

                {/*
                    Only when the area has more than one page. A single-page area is fully
                    addressed by its rail entry, and an empty second column is a column of
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
                        shell.platformMode
                            ? 'Platform admin'
                            : onEnvironment
                              ? (environment.name ?? 'Environment')
                              : (workspace?.label ?? organization?.name ?? 'Console')
                    }
                    subheading={onEnvironment ? (workspace?.label ?? null) : null}
                    user={auth.user}
                    logoutUrl={logout.url()}
                    showEnvironment={shell.altitude !== 'workspace'}
                    accountUrl={shell.accountHref}
                    showAccountLink={onEnvironment}
                    switchUserUrl={shell.switchUserHref}
                    workspaceSettingsUrl={shell.workspaceSettingsHref}
                    platformUrl={shell.platformMode ? null : shell.platformHref}
                >
                    <div className="cbx-ctx-sheet">{context}</div>
                </MobileNav>

                <div className="flex flex-col min-w-0 flex-1">
                    {shell.platformMode && shell.exitPlatformHref !== null && (
                        <PlatformStrip exitHref={shell.exitPlatformHref} />
                    )}

                    <header className="cbx-topbar">
                        <div className="cbx-topbar-context">{context}</div>

                        <div className="flex items-center gap-2 shrink-0">
                            {/* "</> API": this page's actions as REST, MCP, CLI and SDK calls. */}
                            <PageApiEquivalents />
                            <CommandPalette areas={shell.areas} />

                            <AccountMenu
                                user={auth.user}
                                logoutUrl={logout.url()}
                                workspaceSettingsHref={shell.workspaceSettingsHref}
                                accountHref={shell.accountHref}
                                switchUserHref={shell.switchUserHref}
                                platformHref={shell.platformMode ? null : shell.platformHref}
                            />
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
