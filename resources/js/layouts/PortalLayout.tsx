import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Brand } from '@/chrome/Brand';
import { RouteAnnouncer } from '@/chrome/RouteAnnouncer';
import { Toaster } from '@/chrome/Toaster';
import { toggleTheme } from '@/lib/theme';
import type { SharedProps } from '@/types';
import { Icon, TooltipProvider } from '@/ui';

/**
 * THE ADMIN SETUP PORTAL — a single-use link, opened by an external IT administrator who
 * has no account on this platform and never will.
 *
 * No rail, no switcher, no account menu, and that is the whole design. The person here
 * holds a scoped portal session for one organization's SSO and SCIM setup, and every
 * control the console shell offers would either mislead them about what they can reach or
 * lead them somewhere their session cannot go.
 */
export default function PortalLayout({ children }: { children: ReactNode }) {
    const { app, title } = usePage<SharedProps>().props;

    return (
        <TooltipProvider>
            <Head title={title} />
            <RouteAnnouncer />
            <Toaster />

            <div className="min-h-full flex flex-col">
                <header
                    className="h-16 flex items-center justify-between px-5 sm:px-8 border-b"
                    style={{ borderColor: 'var(--border)', background: 'var(--card)' }}
                >
                    <Brand />

                    <div className="flex items-center gap-3">
                        <span
                            className="hidden sm:inline-flex items-center gap-1.5 text-xs"
                            style={{ color: 'var(--faint)' }}
                        >
                            <Icon name="shield" className="w-3.5 h-3.5" /> Admin setup portal
                        </span>

                        <button
                            type="button"
                            className="btn btn-ghost"
                            style={{ padding: '0.4rem' }}
                            onClick={() => toggleTheme()}
                            aria-label="Toggle theme"
                        >
                            <Icon name="sun" className="w-[1.1rem] h-[1.1rem]" />
                        </button>
                    </div>
                </header>

                <main
                    id="main-content"
                    className="flex-1 w-full mx-auto px-5 sm:px-8 py-8 sm:py-12"
                    style={{ maxWidth: '46rem' }}
                >
                    {children}
                </main>

                <footer
                    className="px-5 sm:px-8 py-5 border-t text-xs flex items-center justify-between"
                    style={{ borderColor: 'var(--border)', color: 'var(--faint)' }}
                >
                    <span className="inline-flex items-center gap-1.5">
                        <Icon name="shield" className="w-3.5 h-3.5" /> Secured by {app.name}
                    </span>
                    <span>© {app.year}</span>
                </footer>
            </div>
        </TooltipProvider>
    );
}
