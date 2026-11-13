import { Head, Link, usePage } from '@inertiajs/react';
import { type ReactNode, useCallback, useState } from 'react';
import { AccountMenu } from '@/chrome/AccountMenu';
import { BackLink } from '@/chrome/BackLink';
import { Brand } from '@/chrome/Brand';
import { Rail } from '@/chrome/Rail';
import { RouteAnnouncer } from '@/chrome/RouteAnnouncer';
import { StepUpDialog } from '@/chrome/StepUpDialog';
import { Toaster } from '@/chrome/Toaster';
import { useDocumentLanguage, useTranslator } from '@/i18n';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import { setNavPinned } from '@/lib/theme';
import type { AccountShell, NavArea, SharedProps } from '@/types';
import { LinkTabs, TooltipProvider } from '@/ui';
import type { IconName } from '@/ui/icons';

/**
 * THE LAYOUT OF A PERSON'S OWN PAGES: security, sessions and activity, their keys, devices
 * and connected services, the step-up in front of a change.
 *
 * Where the page stands in a console it is the console's layout, unchanged. In login-host
 * mode these pages are on the login host, which has no console to put around them, and
 * the server says so with `accountShell` ({@see \App\Platform\Surfaces\AccountChrome}).
 * The page is then drawn with the console's own chrome, holding only what a person needs
 * here: the way back to where they came from, their own pages, and the account menu.
 */
export default function AccountLayout({ children }: { children: ReactNode }) {
    const { accountShell } = usePage<SharedProps>().props;

    if (accountShell === null || accountShell === undefined) {
        return <ConsoleLayout>{children}</ConsoleLayout>;
    }

    return <HostedAccount shell={accountShell}>{children}</HostedAccount>;
}

/** Each of a person's pages by its route, as the rail draws it. Anything else is the person. */
const PAGE_ICONS: Record<string, IconName> = {
    account: 'shield',
    'account.activity': 'clock',
    'account.api-keys': 'key',
    'devices.mine': 'device',
    'account.pipes': 'connections',
    'account.services': 'puzzle',
};

/**
 * The person's pages as rail entries, one each: on the login host they are the whole
 * console, so the first tier holds them and no second tier repeats them under one
 * "Account" heading.
 */
export function accountAreas(pages: AccountShell['pages']): NavArea[] {
    return pages.map((page) => ({
        key: page.route,
        label: page.label,
        icon: PAGE_ICONS[page.route] ?? 'user',
        href: page.href,
        active: page.active,
        current: page.active,
        pages: [],
    }));
}

function HostedAccount({ shell, children }: { shell: AccountShell; children: ReactNode }) {
    const { app, auth, brand, title, i18n } = usePage<SharedProps>().props;
    const { t } = useTranslator();

    useDocumentLanguage(i18n?.locale ?? 'en');

    // The root view sets the class from the cookie before the first paint.
    const [pinned, setPinned] = useState(
        () =>
            typeof document === 'undefined' ||
            document.documentElement.classList.contains('cbx-nav-pinned'),
    );
    const togglePin = useCallback(() => {
        setPinned((current) => {
            setNavPinned(!current);

            return !current;
        });
    }, []);

    const home = shell.pages[0]?.href ?? '/settings';

    return (
        <TooltipProvider>
            <Head title={title} />
            <RouteAnnouncer />
            <Toaster />
            <StepUpDialog />

            <div className="flex h-full" data-test="account-shell">
                <Rail
                    areas={accountAreas(shell.pages)}
                    brandHref={home}
                    brandLabel={brand?.name ?? app.name}
                    pinned={pinned}
                    onTogglePin={togglePin}
                />

                <div className="flex flex-col min-w-0 flex-1">
                    <header className="cbx-topbar idc-account-topbar">
                        <div className="flex min-w-0 items-center gap-3">
                            {/* The rail and its mark are not drawn on a phone. */}
                            <Link
                                href={home}
                                className="lg:hidden shrink-0"
                                aria-label={brand?.name ?? app.name}
                            >
                                {brand?.logo ? (
                                    <img
                                        src={brand.logo}
                                        alt=""
                                        style={{ maxHeight: '1.75rem', maxWidth: '6rem' }}
                                    />
                                ) : (
                                    <Brand compact />
                                )}
                            </Link>

                            <BackLink back={shell.back} />
                        </div>

                        {auth.user !== null && (
                            <AccountMenu
                                user={auth.user}
                                logoutUrl={shell.signOutHref}
                                organizationSettingsHref={null}
                                accountHref={null}
                                switchUserHref={shell.switchAccountHref}
                                platformHref={null}
                                withLanguage
                            />
                        )}
                    </header>

                    <main id="main-content" className="flex-1 overflow-y-auto canvas-gradient">
                        <div
                            className="mx-auto w-full px-4 pt-3 pb-16 sm:px-6 sm:pt-6 lg:p-8"
                            style={{ maxWidth: '64rem' }}
                        >
                            {/* The rail's pages, on a phone: one row that scrolls sideways. */}
                            <div className="lg:hidden mb-5 -mx-4 px-4 sm:-mx-6 sm:px-6">
                                <LinkTabs
                                    label={t('console.account_7e1b0d56')}
                                    tabs={shell.pages.map((page) => ({
                                        key: page.route,
                                        label: page.label,
                                        href: page.href,
                                        current: page.active,
                                    }))}
                                />
                            </div>

                            {children}
                        </div>
                    </main>
                </div>
            </div>
        </TooltipProvider>
    );
}
