import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { SharedProps } from '@/types';
import { Icon } from '@/ui';

/**
 * A sandbox realm is for development and testing. Say so unmistakably, so nobody mistakes
 * it for the sign-in their customers use.
 */
export function SandboxBanner() {
    const { environment } = usePage<SharedProps>().props;

    if (!environment.sandbox) {
        return null;
    }

    return (
        /*
            NO `role="status"`. A live region present at page load is not announced —
            only updates to one are — so the role did nothing at all here, on content
            that never changes. What actually reaches somebody using a screen reader is
            the text, read in document order, above the page it is warning them about.
        */
        <div
            className="w-full flex items-center justify-center gap-2 px-4 py-2 text-xs font-semibold text-center"
            style={{
                background: 'color-mix(in oklch, var(--warning) 16%, var(--background))',
                color: 'var(--warning-strong)',
                borderBottom: '1px solid color-mix(in oklch, var(--warning) 30%, transparent)',
            }}
        >
            <Icon name="shield" className="w-3.5 h-3.5 shrink-0" />
            <span>
                Sandbox environment — for testing only. This is <strong>not</strong> your
                production sign-in, and no real emails are sent.
            </span>
        </div>
    );
}

/**
 * AN UNCONFIRMED ADDRESS, AND THE BUTTON THAT FIXES IT.
 *
 * The console refuses changes from an address nobody has confirmed, and the refusal used
 * to point at "your account page" — where nothing could send a link. An owner on
 * cboxid.com was refused with no way forward. Now every console page says so up front, with
 * the one click that resolves it.
 */
export function EmailConfirmationBanner() {
    const { auth } = usePage<SharedProps>().props;
    const [sending, setSending] = useState(false);
    const user = auth.user;

    if (user === null || user.emailVerified) {
        return null;
    }

    const confirmUrl = user.confirmUrl ?? null;
    const accountUrl = user.accountUrl ?? null;

    return (
        <div
            className="w-full flex flex-wrap items-center justify-center gap-x-3 gap-y-1 px-4 py-2 text-xs font-semibold text-center"
            style={{
                background: 'color-mix(in oklch, var(--warning) 16%, var(--background))',
                color: 'var(--warning-strong)',
                borderBottom: '1px solid color-mix(in oklch, var(--warning) 30%, transparent)',
            }}
        >
            <Icon name="mail" className="w-3.5 h-3.5 shrink-0" />
            <span>
                Confirm your email address{user.email ? ` (${user.email})` : ''} to make changes in the
                console.
            </span>
            {confirmUrl !== null ? (
                <button
                    type="button"
                    className="underline underline-offset-2"
                    disabled={sending}
                    onClick={() =>
                        router.post(confirmUrl, {}, {
                            preserveScroll: true,
                            onStart: () => setSending(true),
                            onFinish: () => setSending(false),
                        })
                    }
                >
                    {sending ? 'Sending…' : 'Send confirmation link'}
                </button>
            ) : accountUrl !== null ? (
                <a className="underline underline-offset-2" href={accountUrl}>
                    Send a link from your account
                </a>
            ) : null}
        </div>
    );
}

/**
 * A LIVE SUPPORT IMPERSONATION, and the only way out of it.
 *
 * Rendered from a shared prop rather than by a page, and that is the point: this control
 * once lived in one of the two blade layouts, so an operator who started an impersonation
 * from a page on the other one got a banner-less console and no way back except POSTing
 * the exit endpoint by hand. A control that exists on one of the two layouts a person can
 * be holding is not a control.
 *
 * The countdown is a DISPLAY of the server's decision. The window is closed by
 * EnforceImpersonationWindow on the request; a browser that ignores this number gains
 * nothing at all.
 */
export function ImpersonationBanner({ exitUrl }: { exitUrl: string }) {
    const { impersonation } = usePage<SharedProps>().props;
    const granted = impersonation?.expiresInSeconds ?? 0;

    const [remaining, setRemaining] = useState(granted);
    const [lastGranted, setLastGranted] = useState(granted);

    // Every navigation brings a fresh figure from the server. Adopting it DURING RENDER
    // rather than in an effect is the documented way to reset state when a prop changes:
    // an effect would render one frame of the stale count first, and on a control whose
    // whole job is to say how long you have left, a visible jump backwards reads as a
    // bug in the countdown rather than as a refresh.
    if (granted !== lastGranted) {
        setLastGranted(granted);
        setRemaining(granted);
    }

    useEffect(() => {
        if (impersonation === null) {
            return;
        }

        const tick = setInterval(() => setRemaining((seconds) => Math.max(0, seconds - 1)), 1000);

        return () => clearInterval(tick);
    }, [impersonation]);

    if (impersonation === null) {
        return null;
    }

    const minutes = Math.floor(remaining / 60);
    const seconds = remaining % 60;

    return (
        <div
            role="alert"
            style={{
                position: 'sticky',
                top: 0,
                zIndex: 80,
                width: '100%',
                display: 'flex',
                flexWrap: 'wrap',
                alignItems: 'center',
                justifyContent: 'center',
                gap: '0.75rem',
                padding: '0.6rem 1rem',
                background: 'var(--destructive)',
                color: 'var(--destructive-foreground)',
                fontSize: '0.85rem',
                fontWeight: 600,
            }}
        >
            <span>
                <span aria-hidden="true">⚠</span> You are impersonating{' '}
                {impersonation.email ?? impersonation.subject} for support. Everything you do
                is logged.
                {impersonation.reason !== null && (
                    <span style={{ fontWeight: 400, opacity: 0.9 }}>
                        {' '}
                        (reason: {impersonation.reason})
                    </span>
                )}{' '}
                <span style={{ fontWeight: 400, opacity: 0.9, fontVariantNumeric: 'tabular-nums' }}>
                    — {minutes}:{String(seconds).padStart(2, '0')} left
                </span>
            </span>

            <button
                type="button"
                onClick={() => router.post(exitUrl)}
                style={{
                    border: '1px solid rgba(255,255,255,0.7)',
                    borderRadius: '6px',
                    padding: '3px 12px',
                    background: 'transparent',
                    color: 'inherit',
                    fontWeight: 600,
                    cursor: 'pointer',
                }}
            >
                Exit impersonation
            </button>
        </div>
    );
}
