import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { SandboxBanner } from '@/chrome/Banners';
import { LanguagePicker } from '@/chrome/LanguagePicker';
import { RouteAnnouncer } from '@/chrome/RouteAnnouncer';
import { Toaster } from '@/chrome/Toaster';
import { DoorMark } from '@/components/auth/DoorMark';
import { ThemeChoice } from '@/components/auth/ThemeChoice';
import { useDocumentLanguage, useTranslator } from '@/i18n';
import type { SharedProps } from '@/types';
import { Icon, TooltipProvider } from '@/ui';

/**
 * THE SIGN-IN SURFACE — the only part of this product most people ever see, and the one
 * a customer puts their own name on.
 *
 * It is drawn as a page OF the product rather than a page in front of it: the console's
 * canvas wash, a top bar holding the mark the rail holds (or the customer's), and the form
 * in a card with the console's border, radius and shadow. Under the card, quietly, who
 * secures it and the two preferences somebody may need before they can read the page at
 * all — language, and System / Light / Dark as the account menu offers them.
 *
 * The form is `<main>`. Every page in this layout — sign-in, sign-up, MFA, passkey, OTP
 * step-up, sudo, the account chooser, password reset, OAuth consent, device approval —
 * renders its heading and fields inside the card; a page with a strip of its own at the
 * card's foot uses {@see AuthCardFoot}.
 *
 * The BRAND COLOURS are already in the document: the root view emits the customer's token
 * override in `<head>`, so this page paints their palette on the first frame rather than
 * flashing ours. Everything here reads those tokens, so a branded door repaints with no
 * code path of its own; what React contributes is the name and the logo.
 *
 * There is no marketing panel. It sold Cbox beside a vendor's own sign-up page when the
 * door was branded, and beside our own it was a second, competing design.
 */
export default function AuthLayout({ children }: { children: ReactNode }) {
    const { app, title } = usePage<SharedProps>().props;
    const { locale, t } = useTranslator();

    useDocumentLanguage(locale);

    return (
        <TooltipProvider>
            <Head title={title} />
            <RouteAnnouncer />
            <Toaster />
            <SandboxBanner />

            <div className="auth-shell auth-page">
                <header className="auth-topbar">
                    <DoorMark />
                </header>

                <main id="main-content" className="auth-main">
                    <div className="auth-column">
                        <div className="auth-card">{children}</div>

                        <footer className="auth-foot">
                            <span className="inline-flex items-center gap-1.5">
                                <Icon name="shield" className="w-3.5 h-3.5" />
                                {t('hosted.layout.secured_by', { name: app.name })}
                            </span>

                            <span className="auth-foot-controls">
                                <LanguagePicker />
                                <ThemeChoice />
                            </span>
                        </footer>
                    </div>
                </main>
            </div>
        </TooltipProvider>
    );
}

/**
 * The strip at the foot of the card for the question that is not the form's own — "New
 * organization? Create one", "Already have an account? Sign in".
 */
export function AuthCardFoot({ children }: { children: ReactNode }) {
    return <div className="auth-card-foot">{children}</div>;
}
