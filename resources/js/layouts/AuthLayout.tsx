import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { SandboxBanner } from '@/chrome/Banners';
import { Brand } from '@/chrome/Brand';
import { DoorBrand } from '@/chrome/DoorBrand';
import { LanguagePicker } from '@/chrome/LanguagePicker';
import { RouteAnnouncer } from '@/chrome/RouteAnnouncer';
import { Toaster } from '@/chrome/Toaster';
import { type MessageKey, useDocumentLanguage, useTranslator } from '@/i18n';
import { toggleTheme } from '@/lib/theme';
import type { SharedProps } from '@/types';
import { Icon, TooltipProvider } from '@/ui';

const FEATURES: MessageKey[] = [
    'hosted.layout.features.sso',
    'hosted.layout.features.scim',
    'hosted.layout.features.mfa',
    'hosted.layout.features.audit',
];

/**
 * THE SIGN-IN SURFACE — the only part of this product most people ever see, and the one
 * a customer puts their own name on.
 *
 * The form is `<main>` and the marketing panel is an `<aside>`, which is not decoration:
 * before that split, sign-in, sign-up, MFA, passkey, OTP step-up, sudo, the account
 * chooser, password reset, OAuth consent and device approval all rendered with no
 * landmark at all — a screen reader had no "main" to jump to, and the hero sat in the
 * same undifferentiated region as the form the person came to fill in.
 *
 * The BRAND COLOURS are already in the document: the root view emits the customer's token
 * override in `<head>`, so this page paints their palette on the first frame rather than
 * flashing ours. What React contributes here is the name and the logo.
 *
 * A BRANDED DOOR HAS NO HERO. With a brand — an organization's door, or any door on a
 * customer's environment — the page is the one the Appearance editor previews: their logo
 * (or their initial) and their name over the form, and nothing else. The hero is Cbox
 * selling Cbox, and it used to sit beside a vendor's own sign-up page, pitching SCIM to
 * the vendor's end users under the vendor's name.
 */
export default function AuthLayout({ children }: { children: ReactNode }) {
    const { app, brand, title } = usePage<SharedProps>().props;
    const { locale, t } = useTranslator();

    useDocumentLanguage(locale);

    return (
        <TooltipProvider>
            <Head title={title} />
            <RouteAnnouncer />
            <Toaster />
            <SandboxBanner />

            <div
                className={
                    brand === null
                        ? 'min-h-full grid lg:grid-cols-[1fr_minmax(0,44%)] xl:grid-cols-[1fr_minmax(0,40%)]'
                        : 'min-h-full grid'
                }
            >
                <main
                    id="main-content"
                    className="auth-shell flex flex-col justify-center px-6 py-12 sm:px-12"
                >
                    <div className="mx-auto w-full" style={{ maxWidth: '24rem' }}>
                        <DoorBrand brand={brand} />

                        <div className="mt-9">{children}</div>

                        <div
                            className="mt-10 flex items-center justify-between text-xs"
                            style={{ color: 'var(--faint)' }}
                        >
                            <span className="inline-flex items-center gap-1.5">
                                <Icon name="shield" className="w-3.5 h-3.5" />{' '}
                                {t('hosted.layout.secured_by', { name: app.name })}
                            </span>

                            <span className="inline-flex items-center gap-2">
                                <LanguagePicker />

                                <button
                                    type="button"
                                    onClick={() => toggleTheme()}
                                    aria-label={t('hosted.layout.toggle_theme')}
                                    className="inline-flex items-center gap-1.5 rounded-md px-2 py-1 transition hover:opacity-80"
                                    style={{ border: '1px solid var(--border)' }}
                                >
                                    <Icon name="sun" className="w-3.5 h-3.5" />{' '}
                                    {t('hosted.layout.theme')}
                                </button>
                            </span>
                        </div>
                    </div>
                </main>

                {/*
                    Decorative, and duplicative of the marketing site. An <aside> so it is
                    skipped rather than read out before the form on every single sign-in.
                    Only on Cbox's own doors — see above.
                */}
                {brand === null && (
                    <aside
                        className="auth-hero hidden lg:flex flex-col justify-between p-12 overflow-hidden"
                        aria-label={t('hosted.layout.about')}
                    >
                        <Brand compact className="opacity-95" />

                        <div className="max-w-md">
                            <h2
                                className="font-semibold tracking-tight leading-[1.1]"
                                style={{ fontSize: '2.15rem' }}
                            >
                                {app.tagline}
                            </h2>
                            <p className="mt-4 text-sm leading-relaxed" style={{ opacity: 0.82 }}>
                                {t('hosted.layout.hero_body')}
                            </p>

                            <ul className="mt-9 space-y-3.5">
                                {FEATURES.map((feature) => (
                                    <li key={feature} className="hero-feature">
                                        <span className="tick">
                                            <Icon name="check" className="w-3.5 h-3.5" />
                                        </span>
                                        {t(feature)}
                                    </li>
                                ))}
                            </ul>
                        </div>

                        <div className="flex items-center gap-4 text-xs" style={{ opacity: 0.72 }}>
                            <span>
                                © {app.year} {app.name}
                            </span>
                            {app.trustLine !== '' && (
                                <>
                                    <span aria-hidden="true">·</span>
                                    <span>{app.trustLine}</span>
                                </>
                            )}
                        </div>
                    </aside>
                )}
            </div>
        </TooltipProvider>
    );
}
