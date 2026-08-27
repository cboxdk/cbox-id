import { useEffect, useRef } from 'react';

declare global {
    interface Window {
        turnstile?: {
            render: (
                el: HTMLElement,
                options: { sitekey: string; callback: (token: string) => void; theme: string },
            ) => string;
            remove: (id: string) => void;
        };
    }
}

const SRC = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

let loading: Promise<void> | null = null;

/**
 * Fetch Cloudflare's script, once, and only when a challenge actually appears.
 *
 * LAZINESS IS THE POINT. An ordinary signup is never challenged and must therefore
 * contact Cloudflare zero times — a CAPTCHA script on every sign-up page is a third party
 * watching every visitor to an identity provider, whether or not it is ever used.
 */
function loadTurnstile(): Promise<void> {
    if (window.turnstile !== undefined) {
        return Promise.resolve();
    }

    loading ??= new Promise<void>((resolve, reject) => {
        const script = document.createElement('script');

        script.src = SRC;
        script.async = true;
        script.defer = true;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('Turnstile failed to load'));

        document.head.appendChild(script);
    });

    return loading;
}

export interface TurnstileProps {
    siteKey: string;
    onToken: (token: string) => void;
}

/**
 * THE RISK-TRIGGERED CAPTCHA.
 *
 * Rendered only once the risk scorer has challenged a submission, so the friction lands
 * on the unusual one and not on everybody else.
 *
 * `render=explicit` rather than the automatic scan, because the automatic one needs a
 * global callback function named in a `data-callback` attribute — which is a name the
 * page has to publish on `window`, and the reason the version this replaces existed at
 * all. Explicit rendering takes the callback as a value, so nothing global is involved
 * and the strict CSP is untouched: Cloudflare's own origin is admitted by
 * `script-src`/`frame-src` only when Turnstile is configured.
 */
export function Turnstile({ siteKey, onToken }: TurnstileProps) {
    const host = useRef<HTMLDivElement>(null);

    useEffect(() => {
        let widget: string | null = null;
        let cancelled = false;

        void loadTurnstile().then(() => {
            if (cancelled || host.current === null || window.turnstile === undefined) {
                return;
            }

            widget = window.turnstile.render(host.current, {
                sitekey: siteKey,
                callback: onToken,
                theme: 'auto',
            });
        });

        return () => {
            cancelled = true;

            if (widget !== null) {
                window.turnstile?.remove(widget);
            }
        };
    }, [siteKey, onToken]);

    return (
        <div>
            <div ref={host} />
            <p className="mt-2 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                A quick check that you're not a bot. It usually completes on its own.
            </p>
        </div>
    );
}
