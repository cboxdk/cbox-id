import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { SandboxBanner } from '@/chrome/Banners';
import { Brand } from '@/chrome/Brand';
import { RouteAnnouncer } from '@/chrome/RouteAnnouncer';
import { Toaster } from '@/chrome/Toaster';
import { toggleTheme } from '@/lib/theme';
import type { SharedProps } from '@/types';
import { Icon, TooltipProvider } from '@/ui';

const FEATURES = [
    'SAML & OIDC single sign-on',
    'SCIM 2.0 directory provisioning',
    'Passkeys, TOTP, and magic links',
    'Hash-chained, tamper-evident audit',
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
                        {brand === null ? (
                            <a href="/" className="inline-block">
                                <Brand />
                            </a>
                        ) : brand.logo !== null ? (
                            <img
                                src={brand.logo}
                                alt={brand.name}
                                style={{ maxHeight: '2.25rem', maxWidth: '12rem' }}
                            />
                        ) : (
                            <BrandMonogram name={brand.name} />
                        )}

                        <div className="mt-9">{children}</div>

                        <div
                            className="mt-10 flex items-center justify-between text-xs"
                            style={{ color: 'var(--faint)' }}
                        >
                            <span className="inline-flex items-center gap-1.5">
                                <Icon name="shield" className="w-3.5 h-3.5" /> Secured by {app.name}
                            </span>

                            <button
                                type="button"
                                onClick={() => toggleTheme()}
                                aria-label="Toggle light or dark theme"
                                className="inline-flex items-center gap-1.5 rounded-md px-2 py-1 transition hover:opacity-80"
                                style={{ border: '1px solid var(--border)' }}
                            >
                                <Icon name="sun" className="w-3.5 h-3.5" /> Theme
                            </button>
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
                        aria-label="About this product"
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
                                Enterprise SSO, SCIM directory sync, MFA and passkeys, RBAC, and a
                                tamper-evident audit trail — self-hostable, and yours.
                            </p>

                            <ul className="mt-9 space-y-3.5">
                                {FEATURES.map((feature) => (
                                    <li key={feature} className="hero-feature">
                                        <span className="tick">
                                            <Icon name="check" className="w-3.5 h-3.5" />
                                        </span>
                                        {feature}
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

/**
 * A brand with no logo uploaded: its initial on the accent, and its name — drawn exactly
 * as the Appearance editor's preview draws it, so the page is the one that was approved.
 */
function BrandMonogram({ name }: { name: string }) {
    return (
        <span className="inline-flex items-center gap-2.5 select-none">
            <span
                aria-hidden="true"
                className="grid place-items-center w-8 h-8 rounded-lg text-sm font-bold"
                style={{ background: 'var(--accent)', color: 'var(--accent-foreground)' }}
            >
                {name.charAt(0).toUpperCase()}
            </span>
            <span
                className="font-semibold tracking-tight"
                style={{ fontSize: '1.02rem', color: 'var(--foreground)' }}
            >
                {name}
            </span>
        </span>
    );
}
