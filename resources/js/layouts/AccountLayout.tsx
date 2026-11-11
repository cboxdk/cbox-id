import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { DoorBrand } from '@/chrome/DoorBrand';
import { RouteAnnouncer } from '@/chrome/RouteAnnouncer';
import { StepUpDialog } from '@/chrome/StepUpDialog';
import { Toaster } from '@/chrome/Toaster';
import { useDocumentLanguage, useTranslator } from '@/i18n';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import { toggleTheme } from '@/lib/theme';
import type { AccountShell, SharedProps } from '@/types';
import { Icon, TooltipProvider } from '@/ui';

/**
 * THE LAYOUT OF A PERSON'S OWN PAGES: security, sessions and activity, their keys and
 * connected services, the step-up in front of a change.
 *
 * Where the page stands in a console it is the console's layout, unchanged. In login-host
 * mode these pages are on the login host, which has no console to put around them, and
 * the server says so with `accountShell` ({@see \App\Platform\Surfaces\AccountChrome});
 * the page is then drawn the way the hosted pages are, with the three things a person
 * needs there: the way back to where they came from, their own pages, and the way out.
 *
 * No language picker: these pages speak the person's saved interface language, which the
 * hosted pages' picker (a cookie for visitors with no account) does not change.
 */
export default function AccountLayout({ children }: { children: ReactNode }) {
    const { accountShell } = usePage<SharedProps>().props;

    if (accountShell === null || accountShell === undefined) {
        return <ConsoleLayout>{children}</ConsoleLayout>;
    }

    return <HostedAccount shell={accountShell}>{children}</HostedAccount>;
}

function HostedAccount({ shell, children }: { shell: AccountShell; children: ReactNode }) {
    const { app, auth, brand, title, i18n } = usePage<SharedProps>().props;
    const { t } = useTranslator();
    const signOut = useForm({});

    useDocumentLanguage(i18n?.locale ?? 'en');

    return (
        <TooltipProvider>
            <Head title={title} />
            <RouteAnnouncer />
            <Toaster />
            <StepUpDialog />

            <div className="min-h-full flex flex-col">
                <header
                    className="border-b"
                    style={{ borderColor: 'var(--border)', background: 'var(--card)' }}
                >
                    <div
                        className="mx-auto w-full flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-5 sm:px-8 py-3"
                        style={{ maxWidth: '72rem' }}
                    >
                        <div className="flex flex-wrap items-center gap-x-5 gap-y-2">
                            <DoorBrand brand={brand} home={null} />

                            {/* Another origin: a real navigation, never an Inertia visit. */}
                            <a
                                href={shell.back.url}
                                className="inline-flex items-center gap-1.5 text-sm font-medium underline-offset-2 hover:underline"
                                style={{ color: 'var(--muted-foreground)' }}
                                data-test="account-back"
                            >
                                <Icon name="arrow-left" className="w-4 h-4" />
                                {t('console.back_to_part1_dc732693', { part1: shell.back.label })}
                            </a>
                        </div>

                        <div className="flex flex-wrap items-center gap-3 text-xs">
                            {auth.user !== null && (
                                <span style={{ color: 'var(--muted-foreground)' }}>
                                    {auth.user.email ?? auth.user.name}
                                </span>
                            )}

                            <button
                                type="button"
                                className="btn btn-ghost"
                                style={{ padding: '0.4rem' }}
                                onClick={() => toggleTheme()}
                                aria-label={t('hosted.layout.toggle_theme')}
                            >
                                <Icon name="sun" className="w-[1.1rem] h-[1.1rem]" />
                            </button>

                            <button
                                type="button"
                                className="btn btn-ghost"
                                disabled={signOut.processing}
                                onClick={() => signOut.post(shell.signOutHref)}
                            >
                                <Icon name="logout" className="w-4 h-4" />{' '}
                                {t('console.sign_out_48f0d3d3')}
                            </button>
                        </div>
                    </div>

                    {shell.pages.length > 1 && (
                        <nav
                            aria-label={t('console.account_7e1b0d56')}
                            className="mx-auto w-full flex flex-wrap gap-x-1 px-5 sm:px-8"
                            style={{ maxWidth: '72rem' }}
                        >
                            {shell.pages.map((page) => (
                                <Link
                                    key={page.href}
                                    href={page.href}
                                    aria-current={page.active ? 'page' : undefined}
                                    className="px-3 py-2.5 text-sm font-medium"
                                    style={{
                                        color: page.active
                                            ? 'var(--foreground)'
                                            : 'var(--muted-foreground)',
                                        borderBottom: page.active
                                            ? '2px solid var(--primary)'
                                            : '2px solid transparent',
                                    }}
                                >
                                    {page.label}
                                </Link>
                            ))}
                        </nav>
                    )}
                </header>

                <main
                    id="main-content"
                    className="flex-1 w-full mx-auto p-6 pb-24 lg:p-8"
                    style={{ maxWidth: '72rem' }}
                >
                    {children}
                </main>

                <footer
                    className="px-5 sm:px-8 py-5 border-t text-xs flex items-center justify-between"
                    style={{ borderColor: 'var(--border)', color: 'var(--faint)' }}
                >
                    <span className="inline-flex items-center gap-1.5">
                        <Icon name="shield" className="w-3.5 h-3.5" />{' '}
                        {t('hosted.layout.secured_by', { name: app.name })}
                    </span>
                    <span>© {app.year}</span>
                </footer>
            </div>
        </TooltipProvider>
    );
}
