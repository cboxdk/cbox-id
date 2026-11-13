import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Head, Link, usePage } from '@inertiajs/react';
import { type ReactNode, useCallback, useEffect, useState } from 'react';
import { AccountMenu } from '@/chrome/AccountMenu';
import { BackLink } from '@/chrome/BackLink';
import { ConsoleLanguagePicker } from '@/chrome/ConsoleLanguagePicker';
import { EmailConfirmationBanner, ImpersonationBanner, SandboxBanner } from '@/chrome/Banners';
import { CommandPalette } from '@/chrome/CommandPalette';
import { StepUpDialog } from '@/chrome/StepUpDialog';
import { ContextSwitcher } from '@/chrome/ContextSwitcher';
import { MobileNav } from '@/chrome/MobileNav';
import { Rail } from '@/chrome/Rail';
import { RouteAnnouncer } from '@/chrome/RouteAnnouncer';
import { Subnav } from '@/chrome/Subnav';
import { Toaster } from '@/chrome/Toaster';
import { useDocumentLanguage, useTranslator } from '@/i18n';
import { setNavPinned } from '@/lib/theme';
import { OrganizationFrame } from '@/pages/environment/organizations/frame';
import type { SharedProps, ShellActsOn } from '@/types';
import { Icon, PageApiEquivalents, TooltipProvider } from '@/ui';
import { logout } from '@routes';
import { exit as exitImpersonation } from '@routes/impersonation';

const SUBNAV_KEY = 'cbox-subnav-collapsed';

export interface TenantConsoleLayoutProps {
    children: ReactNode;
}

/**
 * A CUSTOMER ENVIRONMENT'S OWN ORGANIZATION CONSOLE — its IT admins, on its host.
 *
 * Every other console page is framed by cbox-ui's `CboxShell` ({@see ConsoleLayout}). This
 * one is white-labelled: the customer's brand, no Cbox app switcher and no Cbox Help (Cbox
 * Assistant is not their support). `CboxShell` cannot leave those out yet, so this surface
 * keeps its own chrome, built from `tenantShell` ({@see \App\Platform\Shell\IdShell}).
 */
export function TenantConsoleLayout({ children: page }: TenantConsoleLayoutProps) {
    const { t: consoleShortText } = useConsoleTranslator();
    const {
        tenantShell: shell,
        auth,
        title,
        environment,
        organizationHub,
        i18n,
        accountBack,
    } = usePage<SharedProps>().props;

    // A page under an environment console's `…/organizations/{organization}/…` is a tab of that organization's
    // page: its header and tabs go around it, whichever page it is.
    const children =
        organizationHub !== undefined && organizationHub !== null ? (
            <OrganizationFrame hub={organizationHub}>{page}</OrganizationFrame>
        ) : (
            page
        );

    useDocumentLanguage(i18n?.locale ?? 'en');

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
    if (shell === null || shell === undefined || auth.user === null) {
        return (
            <TooltipProvider>
                <Head title={title} />
                <RouteAnnouncer />
                <Toaster />
                <StepUpDialog />
                <main id="main-content" className="canvas-gradient">
                    {children}
                </main>
            </TooltipProvider>
        );
    }

    const active = shell.areas.find((area) => area.key === shell.activeArea);
    const organization = auth.organization;
    const currentOrganization = shell.context.organizations.find((option) => option.current);
    const onEnvironment = shell.altitude === 'environment';
    // The environment this console stands in, as the switcher names it — not the request's
    // own: a page of platform admin READS whichever environment the operator pointed it
    // at, while the console around it is still the platform environment's.
    const standingIn = shell.context.projects
        .flatMap((project) => project.environments)
        .find((option) => option.current)?.name;

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
            <StepUpDialog />
            <SandboxBanner />
            <EmailConfirmationBanner />
            <ImpersonationBanner exitUrl={exitImpersonation.url()} />

            <div className="flex h-full">
                <Rail
                    areas={shell.areas}
                    brandHref={shell.brandHref}
                    brandLabel={currentOrganization?.label ?? organization?.name ?? 'Console'}
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
                        onEnvironment
                            ? (standingIn ??
                              environment.name ??
                              consoleShortText('console.environment_9e471951'))
                            : (currentOrganization?.label ??
                              organization?.name ??
                              consoleShortText('console.console_29a40861'))
                    }
                    subheading={onEnvironment ? (currentOrganization?.label ?? null) : null}
                    user={auth.user}
                    logoutUrl={shell.logoutHref ?? logout.url()}
                    showEnvironment={shell.altitude !== 'org-plane'}
                    accountUrl={shell.accountHref}
                    showAccountLink={onEnvironment}
                    switchUserUrl={shell.switchUserHref}
                    organizationSettingsUrl={shell.organizationSettingsHref}
                    platformUrl={shell.platformHref}
                >
                    <div className="cbx-ctx-sheet">{context}</div>
                </MobileNav>

                <div className="flex flex-col min-w-0 flex-1">
                    <header className="cbx-topbar">
                        <div className="flex min-w-0 items-center gap-2">
                            {/*
                                A person's own page opened from one of this environment's
                                apps: the way back to it, validated by the server.
                            */}
                            {accountBack !== null && accountBack !== undefined && (
                                <BackLink back={accountBack} />
                            )}
                            <div className="cbx-topbar-context">{context}</div>
                        </div>

                        <div className="flex items-center gap-2 shrink-0">
                            {/* "</> API": this page's actions as REST, MCP, CLI and SDK calls. */}
                            <PageApiEquivalents />
                            <CommandPalette areas={shell.areas} />
                            <ConsoleLanguagePicker />

                            <AccountMenu
                                user={auth.user}
                                logoutUrl={shell.logoutHref ?? logout.url()}
                                organizationSettingsHref={shell.organizationSettingsHref}
                                accountHref={shell.accountHref}
                                switchUserHref={shell.switchUserHref}
                                platformHref={shell.platformHref}
                                platformEnvironmentHref={shell.platformEnvironmentHref}
                            />
                        </div>
                    </header>

                    <main id="main-content" className="flex-1 overflow-y-auto canvas-gradient">
                        <div
                            className="p-6 pb-24 lg:p-8 mx-auto w-full"
                            style={{ maxWidth: '72rem' }}
                        >
                            {shell.actsOn !== null && <ActsOn actsOn={shell.actsOn} />}
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

const ACTS_ON_ICON = {
    environment: 'layers',
    'org-plane': 'briefcase',
    organization: 'building',
    platform: 'lock',
} as const;

/**
 * WHAT THIS PAGE ACTS ON — `Environment: Production · Environment on acme.cboxauth.com` — so
 * nobody saves a setting believing it is somebody else's. On the platform's own environment
 * it is a warning: that sign-in is every workspace owner's and every operator's. On platform
 * admin it is a warning too, with the lock: those pages act on every organization at once.
 */
function ActsOn({ actsOn }: { actsOn: ShellActsOn }) {
    const { t: tc } = useTranslator();
    const ACTS_ON_NOUN: Record<ShellActsOn['kind'], string> = {
        environment: tc('console.environment_9e471951'),
        'org-plane': tc('console.organization_d764d425'),
        organization: tc('console.organization_d764d425'),
        platform: tc('console.platform_admin_69652f55'),
    };
    const what = (
        <span>
            {ACTS_ON_NOUN[actsOn.kind]}: <span className="cbx-acts-on-what">{actsOn.label}</span>
        </span>
    );

    // The platform's own environment: a heading and a sentence, read before anything is
    // saved — not a breadcrumb-sized aside.
    if (actsOn.critical) {
        return (
            <div className="cbx-acts-on" data-critical="true" role="note">
                <Icon
                    name={actsOn.kind === 'platform' ? ACTS_ON_ICON.platform : 'warning'}
                    className="w-4 h-4"
                />
                <div className="min-w-0 flex-1">
                    <p>{what}</p>
                    {actsOn.detail !== null && <p>{actsOn.detail}</p>}
                </div>
            </div>
        );
    }

    return (
        <p className="cbx-acts-on" data-critical="false">
            <Icon name={ACTS_ON_ICON[actsOn.kind]} className="w-3.5 h-3.5" />
            {what}
            {actsOn.detail !== null && <span>· {actsOn.detail}</span>}
        </p>
    );
}
